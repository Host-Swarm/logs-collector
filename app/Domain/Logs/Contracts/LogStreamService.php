<?php

declare(strict_types=1);

namespace App\Domain\Logs\Contracts;

use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\LogStreamOptionsDTO;

interface LogStreamService
{
    /**
     * @param  callable(string $channel, string $payload): void  $onFrame
     */
    public function stream(DiscoveredContainerDTO $container, LogStreamOptionsDTO $options, callable $onFrame): void;
}
