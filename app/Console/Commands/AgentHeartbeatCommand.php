<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Docker\DockerHttpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

class AgentHeartbeatCommand extends Command
{
    protected $signature = 'agent:heartbeat';

    protected $description = 'Connect to the parent server-manager and send periodic heartbeats';

    /** @var array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null */
    private ?array $previousCpuStats = null;

    private bool $running = true;

    public function handle(DockerHttpClient $docker, LoggerInterface $logger): int
    {
        $parentUrl = rtrim((string) config('logs_collector.parent_app.url'), '/');
        $connectionKey = (string) config('logs_collector.connection_key');
        $interval = (int) config('logs_collector.heartbeat_interval', 30);

        if ($parentUrl === '' || $connectionKey === '') {
            $this->error('PARENT_APP_URL and CONNECTION_KEY must be configured.');

            return self::FAILURE;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        // ── Connect ────────────────────────────────────────────────────
        $this->info('Connecting to parent server…');

        try {
            $response = Http::withToken($connectionKey)
                ->timeout(10)
                ->post("{$parentUrl}/api/agent/connect");

            if ($response->successful()) {
                $data = $response->json();
                $logger->info('Agent connected.', ['server_name' => $data['name'] ?? 'unknown']);
                $this->info('Connected as: '.($data['name'] ?? 'unknown'));
            } else {
                $logger->warning('Agent connect returned non-success.', ['status' => $response->status()]);
                $this->warn("Connect returned HTTP {$response->status()}, continuing anyway…");
            }
        } catch (\Throwable $e) {
            $logger->error('Agent connect failed.', ['error' => $e->getMessage()]);
            $this->warn("Connect failed: {$e->getMessage()}, will retry via heartbeat…");
        }

        // ── Heartbeat loop ─────────────────────────────────────────────
        $this->info("Sending heartbeats every {$interval}s. Press Ctrl+C to stop.");

        $this->previousCpuStats = $this->readCpuStats();

        while ($this->running) {
            sleep($interval);

            if (! $this->running) {
                break;
            }

            try {
                $metrics = $this->gatherMetrics($docker);

                $response = Http::withToken($connectionKey)
                    ->timeout(10)
                    ->post("{$parentUrl}/api/agent/heartbeat", $metrics);

                if (! $response->successful()) {
                    $logger->warning('Heartbeat returned non-success.', ['status' => $response->status()]);
                }
            } catch (\Throwable $e) {
                $logger->warning('Heartbeat failed.', ['error' => $e->getMessage()]);
            }
        }

        // ── Disconnect ─────────────────────────────────────────────────
        $this->info('Disconnecting…');

        try {
            Http::withToken($connectionKey)
                ->timeout(5)
                ->post("{$parentUrl}/api/agent/disconnect");

            $logger->info('Agent disconnected gracefully.');
            $this->info('Disconnected.');
        } catch (\Throwable $e) {
            $logger->warning('Agent disconnect failed.', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{cpu_percent: float, ram_used_mb: int, ram_total_mb: int, stacks: int, containers: int}
     */
    private function gatherMetrics(DockerHttpClient $docker): array
    {
        return [
            'cpu_percent' => $this->getCpuPercent(),
            'ram_used_mb' => $this->getRamUsedMb(),
            'ram_total_mb' => $this->getRamTotalMb(),
            'stacks' => $this->countStacks($docker),
            'containers' => $this->countRunningContainers($docker),
        ];
    }

    private function getCpuPercent(): float
    {
        $current = $this->readCpuStats();

        if ($current === null || $this->previousCpuStats === null) {
            return 0.0;
        }

        $prevTotal = array_sum($this->previousCpuStats);
        $currTotal = array_sum($current);
        $totalDelta = $currTotal - $prevTotal;

        $prevIdle = $this->previousCpuStats['idle'] + $this->previousCpuStats['iowait'];
        $currIdle = $current['idle'] + $current['iowait'];
        $idleDelta = $currIdle - $prevIdle;

        $this->previousCpuStats = $current;

        if ($totalDelta <= 0) {
            return 0.0;
        }

        return round((1.0 - ($idleDelta / $totalDelta)) * 100, 1);
    }

    /**
     * @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null
     */
    private function readCpuStats(): ?array
    {
        $line = @file_get_contents('/proc/stat');

        if ($line === false) {
            return null;
        }

        if (! preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $line, $m)) {
            return null;
        }

        return [
            'user' => (int) $m[1],
            'nice' => (int) $m[2],
            'system' => (int) $m[3],
            'idle' => (int) $m[4],
            'iowait' => (int) $m[5],
            'irq' => (int) $m[6],
            'softirq' => (int) $m[7],
            'steal' => (int) $m[8],
        ];
    }

    private function getRamUsedMb(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false) {
            return 0;
        }

        $total = 0;
        $available = 0;

        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $m)) {
            $total = (int) $m[1];
        }

        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $meminfo, $m)) {
            $available = (int) $m[1];
        }

        return (int) round(($total - $available) / 1024);
    }

    private function getRamTotalMb(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false) {
            return 0;
        }

        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $m)) {
            return (int) round((int) $m[1] / 1024);
        }

        return 0;
    }

    private function countStacks(DockerHttpClient $docker): int
    {
        try {
            $services = $docker->getJson('/services');
            $stacks = [];

            foreach ($services as $service) {
                $stackName = $service['Spec']['Labels']['com.docker.stack.namespace'] ?? null;

                if ($stackName !== null) {
                    $stacks[$stackName] = true;
                }
            }

            return count($stacks);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countRunningContainers(DockerHttpClient $docker): int
    {
        try {
            $containers = $docker->getJson('/containers/json', [
                'filters' => json_encode(['status' => ['running']]),
            ]);

            return count($containers);
        } catch (\Throwable) {
            return 0;
        }
    }
}
