<?php

use App\Infrastructure\Docker\DockerLogFrameParser;

it('parses multiplexed docker log frames', function () {
    $parser = new DockerLogFrameParser(true);
    $frames = [];

    $frame1 = buildFrame(1, "hello\n");
    $frame2 = buildFrame(2, "oops\n");

    $parser->feed(substr($frame1, 0, 6), function (string $channel, string $payload) use (&$frames): void {
        $frames[] = [$channel, $payload];
    });

    $parser->feed(substr($frame1, 6) . $frame2, function (string $channel, string $payload) use (&$frames): void {
        $frames[] = [$channel, $payload];
    });

    expect($frames)->toHaveCount(2);
    expect($frames[0])->toBe(['stdout', "hello\n"]);
    expect($frames[1])->toBe(['stderr', "oops\n"]);
});

function buildFrame(int $streamType, string $payload): string
{
    return chr($streamType) . "\x00\x00\x00" . pack('N', strlen($payload)) . $payload;
}
