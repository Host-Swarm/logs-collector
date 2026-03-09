<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

use App\Domain\Logs\Contracts\LogStreamService;
use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\LogStreamOptionsDTO;

final class DockerLogStreamService implements LogStreamService
{
    public function __construct(
        private DockerHttpClient $docker,
    ) {}

    public function stream(DiscoveredContainerDTO $container, LogStreamOptionsDTO $options, callable $onFrame): void
    {
        $parser = new DockerLogFrameParser(! $container->containerTty);

        $this->docker->stream(
            "/containers/{$container->containerId}/logs",
            [
                'follow' => $options->follow ? '1' : '0',
                'stdout' => $options->stdout ? '1' : '0',
                'stderr' => $options->stderr ? '1' : '0',
                'tail' => (string) $options->tail,
                'timestamps' => $options->timestamps ? '1' : '0',
            ],
            function (string $chunk) use ($parser, $onFrame): void {
                $parser->feed($chunk, $onFrame);
            },
        );
    }
}
