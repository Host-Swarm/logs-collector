<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;
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
            $this->docker->getJson("/containers/{$containerId}/json");
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
        } // --- IGNORE ---
        $stdout = filter_var($request->query('stdout', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $stderr = filter_var($request->query('stderr', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $follow = filter_var($request->query('follow', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

        $this->logger->info('Container log stream started.', [
            'container_id' => $containerId,
            'follow' => $follow,
        ]);

        return response()->stream(function () use ($containerId, $stdout, $stderr, $follow): void {
            set_time_limit(0);
            ignore_user_abort(true);

            $queryParams = [
                'follow' => $follow,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];

            try {
                $this->docker->stream(
                    "/containers/{$containerId}/logs",
                    $queryParams,
                    function (string $chunk): void {
                        echo $chunk;
                        ob_flush();
                        flush();
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
