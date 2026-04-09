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

        $stdout = filter_var($request->query('stdout', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $stderr = filter_var($request->query('stderr', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $follow = filter_var($request->query('follow', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $timestamps = filter_var($request->query('timestamps', '0'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $tail = $request->query('tail', '100');
        $since = $request->query('since');

        $queryParams = [
            'follow' => $follow,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timestamps' => $timestamps,
            'tail' => $tail,
        ];

        if (is_string($since) && $since !== '') {
            $queryParams['since'] = $since;
        }

        // Open the Docker log socket before entering the streaming callback
        // so we can still return proper error codes if the connection fails.
        try {
            $stream = $this->docker->openStreamSocket(
                "/containers/{$containerId}/logs",
                $queryParams,
            );
        } catch (DockerApiException $exception) {
            $this->logger->error('Failed to open log stream.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            if ($exception->isNotFound()) {
                return response()->json(['error' => 'Container not found.'], 404);
            }

            if ($exception->isUnavailable()) {
                return response()->json(['error' => 'Docker unavailable.'], 503);
            }

            return response()->json(['error' => 'Failed to open log stream.'], 502);
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error opening log stream.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to open log stream.'], 502);
        }

        $socket = $stream['socket'];
        $initialBuffer = $stream['buffer'];
        $isChunked = $stream['chunked'];

        return response()->stream(function () use ($socket, $initialBuffer, $isChunked, $containerId): void {
            set_time_limit(0);
            ignore_user_abort(true);

            $chunkedBuffer = '';

            // Flush any data already buffered from the HTTP header read.
            if ($initialBuffer !== '') {
                if ($isChunked) {
                    [$decoded, $chunkedBuffer] = $this->docker->decodeChunkedStream($chunkedBuffer.$initialBuffer);
                    if ($decoded !== '') {
                        echo $decoded;
                        flush();
                    }
                } else {
                    echo $initialBuffer;
                    flush();
                }
            }

            // Read loop — same pattern as exec proxy. Direct fread on the
            // Docker socket, echo straight to the HTTP response.
            while (true) {
                if (connection_aborted()) {
                    break;
                }

                $read = [$socket];
                $write = null;
                $except = null;

                $selected = @stream_select($read, $write, $except, 0, 200_000);

                if ($selected === false) {
                    break;
                }

                if ($selected === 0) {
                    continue;
                }

                $chunk = @fread($socket, 8192);

                if ($chunk === false || $chunk === '' || feof($socket)) {
                    break;
                }

                if ($isChunked) {
                    [$decoded, $chunkedBuffer] = $this->docker->decodeChunkedStream($chunkedBuffer.$chunk);
                    if ($decoded !== '') {
                        echo $decoded;
                        flush();
                    }
                } else {
                    echo $chunk;
                    flush();
                }
            }

            @fclose($socket);

            $this->logger->info('Container log stream ended.', [
                'container_id' => $containerId,
            ]);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
