<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Metrics\Services\HostMetricsService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

final class CollectHostMetricsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'metrics:collect {--interval= : Seconds between metrics broadcasts}';

    /**
     * @var string
     */
    protected $description = 'Collect host metrics and forward them to server-manager';

    public function __construct(
        private HostMetricsService $metrics,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $swarmKey = (string) config('logs_collector.swarm_key');
        $interval = $this->option('interval');
        $intervalSeconds = $interval !== null ? (int) $interval : (int) config('logs_collector.metrics.interval', 60);
        $intervalSeconds = max(5, $intervalSeconds);

        $this->logger->info('Host metrics collection started.', [
            'swarm_key' => $swarmKey,
            'interval_seconds' => $intervalSeconds,
        ]);

        while (true) {
            $this->metrics->collectAndBroadcast($swarmKey);
            sleep($intervalSeconds);
        }
    }
}
