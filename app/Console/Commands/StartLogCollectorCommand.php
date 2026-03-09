<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logs\DTOs\LogStreamOptionsDTO;
use App\Domain\Logs\Services\LogObserverService;
use App\Domain\Logs\Services\SwarmDiscoveryService;
use App\Jobs\StreamContainerLogsJob;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

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
        $seenContainers = [];

        $this->logger->info('Log collection started.', [
            'swarm_key' => $swarmKey,
            'follow' => $follow,
            'tail' => $tail,
        ]);

        do {
            $containers = $this->discovery->discover($swarmKey);

            foreach ($containers as $container) {
                if ($follow && isset($seenContainers[$container->containerId])) {
                    continue;
                }

                $seenContainers[$container->containerId] = true;
                $options = new LogStreamOptionsDTO(
                    tail: $tail,
                    follow: $follow,
                    timestamps: $timestamps,
                    stdout: true,
                    stderr: true,
                );

                if ($follow) {
                    StreamContainerLogsJob::dispatch($container, $options);
                } else {
                    $job = new StreamContainerLogsJob($container, $options);
                    $job->handle($this->observer);
                }
            }

            if (! $follow) {
                break;
            }

            sleep(max(1, $intervalSeconds));
        } while (true);

        return self::SUCCESS;
    }
}
