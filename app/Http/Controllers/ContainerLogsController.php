<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;
use App\Infrastructure\Docker\DockerLogFrameParser;
use Illuminate\Http\JsonResponse;
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

    public function __invoke(Request $request, string $containerId): StreamedResponse|JsonResponse
    {
        if (! preg_match('/^[a-f0-9]{12,64}$/', $containerId)) {
            return response()->json(['error' => 'Invalid container ID.'], 400);
        }

        // Inspect the container before starting the stream so we can return
        // 404 or 503 with proper HTTP status codes before any headers are sent.
        try {
            $info = $this->docker->getJson("/containers/{$containerId}/json");
        } catch (DockerApiException $exception) {
            if ($exception->isNotFound()) {
                return response()->json(['error' => 'Container not found.'], 404);
            }

            $this->logger->error('Docker unavailable during container inspect.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Docker unavailable.'], 503);
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error during container inspect.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Docker unavailable.'], 503);
        }

        $tty = (bool) ($info['Config']['Tty'] ?? false);
        $tail = min((int) $request->query('tail', 100), 10000);
        $stdout = filter_var($request->query('stdout', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $stderr = filter_var($request->query('stderr', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $timestamps = filter_var($request->query('timestamps', '0'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $follow = filter_var($request->query('follow', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

        $this->logger->info('Container log stream started.', [
            'container_id' => $containerId,
            'tail' => $tail,
            'follow' => $follow,
        ]);

        return response()->stream(function () use ($containerId, $tty, $tail, $stdout, $stderr, $timestamps, $follow): void {
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
                        $parser->feed($chunk, function (string $channel, string $payload): void {
                            echo $channel.': '.$payload;
                            ob_flush();
                            flush();
                        });
                    },
                );
            } catch (Throwable $exception) {
                $this->logger->error('Container log stream error.', [
                    'container_id' => $containerId,
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->logger->info('Container log stream ended.', [
                'container_id' => $containerId,
            ]);
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
