<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\LogStreamOptionsDTO;
use App\Domain\Logs\Services\LogObserverService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class StreamContainerLogsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 0;

    public function __construct(
        public DiscoveredContainerDTO $container,
        public LogStreamOptionsDTO $options,
    ) {
        $this->onQueue(config('logs_collector.queue'));
    }

    /**
     * Unique per container — prevents duplicate streaming jobs while one is
     * already running, and releases the lock when the job finishes so the
     * discovery loop can re-dispatch a fresh stream automatically.
     */
    public function uniqueId(): string
    {
        return $this->container->containerId;
    }

    public function handle(LogObserverService $observer): void
    {
        $observer->observe($this->container, $this->options);
    }

    public function retryUntil(): DateTimeImmutable
    {
        $minutes = (int) config('logs_collector.retry_until_minutes', 10080);

        return now()->addMinutes($minutes)->toImmutable();
    }
}
