<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logs\Services\SwarmDiscoveryService;
use App\Jobs\StreamContainerLogsJob;
use Illuminate\Console\Command;

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
        
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        

        return self::SUCCESS;
    }
}