<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Docker\DockerHttpClient;
use App\Infrastructure\Docker\DockerLogFrameParser;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ContainerLogsController extends Controller
{
    public function __construct(
        private DockerHttpClient $docker,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, string $containerId): StreamedResponse
    {
        $tail = min((int) $request->query('tail', 100), 10000);
        $stdout = filter_var($request->query('stdout', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $stderr = filter_var($request->query('stderr', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $timestamps = filter_var($request->query('timestamps', '0'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $follow = filter_var($request->query('follow', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

        $this->logger->info('Container log stream started.', [
            'container_id' => $containerId,
        ]);

        return response()->stream(function () use ($containerId, $tail, $stdout, $stderr, $timestamps, $follow): void {
            // Detect TTY by inspecting the container (best-effort).
            $tty = false;

            try {
                $info = $this->docker->getJson("/containers/{$containerId}/json");
                $tty = (bool) ($info['Config']['Tty'] ?? false);
            } catch (Throwable) {
                // Non-fatal: fall back to non-TTY frame parsing.
            }

            $parser = new DockerLogFrameParser(! $tty);

            try {
                $this->docker->stream(
                    "/containers/{$containerId}/logs",
                    [
                        'follow' => $follow,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                        'tail' => (string) $tail,
                        'timestamps' => $timestamps,
                    ],
                    function (string $chunk) use ($parser): void {
                        $parser->feed($chunk, function (string $line, string $stream): void {
                            echo $stream.': '.$line."\n";
                            ob_flush();
                            flush();
                        });
                    },
                );
            } catch (Throwable $exception) {
                $this->logger->error('Container log stream failed.', [
                    'container_id' => $containerId,
                    'error' => $exception->getMessage(),
                ]);

                echo 'error: '.$exception->getMessage()."\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
