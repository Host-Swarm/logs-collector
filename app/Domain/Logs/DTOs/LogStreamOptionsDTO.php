<?php

declare(strict_types=1);

namespace App\Domain\Logs\DTOs;

final class LogStreamOptionsDTO
{
    public function __construct(
        public int $tail,
        public bool $follow,
        public bool $timestamps,
        public bool $stdout,
        public bool $stderr,
    ) {}
}
