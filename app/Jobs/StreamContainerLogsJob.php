<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\LogStreamOptionsDTO;
use App\Domain\Logs\Services\LogObserverService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class StreamContainerLogsJob implements ShouldQueue
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

    public function handle(LogObserverService $observer): void
    {
        $observer->observe($this->container, $this->options);
    }

    public function retryUntil(): DateTimeImmutable
    {
        $minutes = (int) config('logs_collector.retry_until_minutes', 15);

        return now()->addMinutes($minutes)->toImmutable();
    }
}
