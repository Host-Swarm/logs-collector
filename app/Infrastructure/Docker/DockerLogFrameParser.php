<?php

declare(strict_types=1);

namespace App\Infrastructure\Docker;

final class DockerLogFrameParser
{
    private string $buffer = '';

    public function __construct(
        private bool $multiplexed,
    ) {}

    /**
     * @param  callable(string $channel, string $payload): void  $onFrame
     */
    public function feed(string $chunk, callable $onFrame): void
    {
        if (! $this->multiplexed) {
            if ($chunk !== '') {
                $onFrame('stdout', $chunk);
            }

            return;
        }

        $this->buffer .= $chunk;

        while (strlen($this->buffer) >= 8) {
            $streamType = ord($this->buffer[0]);
            $length = unpack('Nlength', substr($this->buffer, 4, 4));
            $payloadLength = $length['length'] ?? 0;

            if (strlen($this->buffer) < 8 + $payloadLength) {
                break;
            }

            $payload = substr($this->buffer, 8, $payloadLength);
            $this->buffer = substr($this->buffer, 8 + $payloadLength);

            $channel = match ($streamType) {
                1 => 'stdout',
                2 => 'stderr',
                default => 'unknown',
            };

            $onFrame($channel, $payload);
        }
    }
}
