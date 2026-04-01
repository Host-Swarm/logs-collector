<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logs\DTOs\LogStreamOptionsDTO;
use App\Domain\Logs\Services\LogObserverService;
use App\Domain\Logs\Services\SwarmDiscoveryService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

final class StartLogCollectorCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'logs:collect
        {--swarm-key= : Override the configured swarm key}
        {--tail=100 : Number of historical lines to request before following}
        {--no-follow : Read available logs without following the stream}
        {--no-timestamps : Disable Docker timestamps in log output}
        {--interval= : Seconds between discovery runs when following}';

    /**
     * @var string
     */
    protected $description = 'Start collecting Docker Swarm container logs and forward them to server-manager';

    public function __construct(
        private SwarmDiscoveryService $discovery,
        private LogObserverService $observer,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $swarmKey = (string) ($this->option('swarm-key') ?: config('logs_collector.swarm_key'));
        $tail = (int) $this->option('tail');
        $follow = ! $this->option('no-follow');
        $timestamps = ! $this->option('no-timestamps');
        $interval = $this->option('interval');
        $intervalSeconds = $interval !== null ? (int) $interval : (int) config('logs_collector.discovery_interval', 30);

        $this->logger->info('Log collection started.', [
            'swarm_key' => $swarmKey,
            'follow' => $follow,
            'tail' => $tail,
        ]);

        if (! $follow) {
            $containers = $this->discovery->discover($swarmKey);

            foreach ($containers as $container) {
                $options = new LogStreamOptionsDTO(
                    tail: $tail,
                    follow: false,
                    timestamps: $timestamps,
                    stdout: true,
                    stderr: true,
                );

                $this->observer->observe($container, $options);
            }

            return self::SUCCESS;
        }

        // Follow mode: fork one child process per container so every container
        // streams in parallel. The parent re-runs discovery every $intervalSeconds
        // to pick up newly-started containers and re-spawn children that exited.

        /** @var array<string, int> $childPids  containerId => child pid */
        $childPids = [];

        pcntl_async_signals(true);

        // Reap finished children to avoid zombies.
        pcntl_signal(SIGCHLD, function () use (&$childPids): void {
            foreach ($childPids as $cid => $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result > 0 || $result === -1) {
                    $this->logger->info('Stream child exited.', ['container_id' => $cid, 'pid' => $pid]);
                    unset($childPids[$cid]);
                }
            }
        });

        // Forward shutdown signals to all children then exit cleanly.
        $terminate = function (int $signal) use (&$childPids): never {
            foreach ($childPids as $pid) {
                posix_kill($pid, $signal);
            }

            foreach ($childPids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            exit(0);
        };

        pcntl_signal(SIGTERM, $terminate);
        pcntl_signal(SIGINT, $terminate);

        do {
            $containers = $this->discovery->discover($swarmKey);

            foreach ($containers as $container) {
                $cid = $container->containerId;

                // If a live child is already streaming this container, skip it.
                if (isset($childPids[$cid])) {
                    $result = pcntl_waitpid($childPids[$cid], $status, WNOHANG);

                    if ($result === 0) {
                        // Child still running — nothing to do.
                        continue;
                    }

                    // Child exited since last check — allow re-spawn below.
                    unset($childPids[$cid]);
                }

                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->logger->error('pcntl_fork failed; skipping container.', ['container_id' => $cid]);

                    continue;
                }

                if ($pid === 0) {
                    // ---- Child process ----
                    pcntl_signal(SIGCHLD, SIG_DFL);
                    pcntl_signal(SIGTERM, SIG_DFL);
                    pcntl_signal(SIGINT, SIG_DFL);

                    $options = new LogStreamOptionsDTO(
                        tail: $tail,
                        follow: true,
                        timestamps: $timestamps,
                        stdout: true,
                        stderr: true,
                    );

                    try {
                        $this->observer->observe($container, $options);
                    } catch (Throwable $exception) {
                        $this->logger->warning('Container stream ended with error.', [
                            'container_id' => $cid,
                            'service_name' => $container->serviceName,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    exit(0);
                }

                // ---- Parent process ----
                $childPids[$cid] = $pid;

                $this->logger->info('Stream child spawned.', [
                    'container_id' => $cid,
                    'service_name' => $container->serviceName,
                    'pid' => $pid,
                ]);
            }

            sleep(max(1, $intervalSeconds));
        } while (true);

        return self::SUCCESS;
    }
}
