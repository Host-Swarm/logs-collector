<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Docker\Contracts\ExecService;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ContainerExecController extends Controller
{
    /** @var string Directory for exec session FIFOs and metadata. */
    private const SESSION_DIR = '/tmp/exec-sessions';

    public function __construct(
        private ExecService $execService,
        private DockerHttpClient $docker,
        private LoggerInterface $logger,
    ) {}

    /**
     * Opens an interactive exec session into the container via HTTP streaming.
     *
     * The endpoint:
     *  1. Verifies the container exists (returns 404 or 503 before streaming).
     *  2. Creates a Docker exec instance (/bin/sh — hardcoded, not caller-controlled).
     *  3. Starts the exec and returns a StreamedResponse.
     *  4. The first line of the response is a JSON session descriptor.
     *  5. Subsequent bytes are raw terminal output from the container.
     *  6. Input is received via POST /exec/{session}/input through a FIFO pipe.
     */
    public function stream(Request $request, string $containerId): StreamedResponse|JsonResponse
    {
        if (! preg_match('/^[a-f0-9]{12,64}$/', $containerId)) {
            return response()->json(['error' => 'Invalid container ID.'], 400);
        }

        try {
            $this->docker->getJson("/containers/{$containerId}/json");
        } catch (DockerApiException $exception) {
            if ($exception->isNotFound()) {
                return response()->json(['error' => 'Container not found.'], 404);
            }

            $this->logger->error('Docker unavailable during exec pre-check.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Docker unavailable.'], 503);
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error during exec pre-check.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Docker unavailable.'], 503);
        }

        try {
            $execId = $this->execService->createExec($containerId);
        } catch (Throwable $exception) {
            $this->logger->error('Exec create failed.', [
                'container_id' => $containerId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to create exec session.'], 500);
        }

        try {
            $dockerSocket = $this->execService->startExec($execId);
        } catch (Throwable $exception) {
            $this->logger->error('Exec start failed.', [
                'container_id' => $containerId,
                'exec_id' => $execId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to start exec session.'], 500);
        }

        $sessionId = Str::uuid()->toString();

        $this->logger->info('Exec session started.', [
            'container_id' => $containerId,
            'exec_id' => $execId,
            'session_id' => $sessionId,
        ]);

        // Prepare session directory and FIFO for input relay.
        $this->ensureSessionDir();
        $fifoPath = self::SESSION_DIR."/{$sessionId}.fifo";
        $metaPath = self::SESSION_DIR."/{$sessionId}.meta";

        posix_mkfifo($fifoPath, 0600);
        file_put_contents($metaPath, json_encode([
            'exec_id' => $execId,
            'container_id' => $containerId,
        ], JSON_THROW_ON_ERROR));

        return response()->stream(function () use ($dockerSocket, $containerId, $sessionId, $fifoPath, $metaPath): void {
            set_time_limit(0);
            ignore_user_abort(true);

            // Send session descriptor as the first line so the client knows
            // which session ID to use for input and resize requests.
            echo json_encode(['session' => $sessionId])."\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Open the FIFO in read-write mode to avoid blocking (the process
            // itself acts as both a potential writer and reader).
            $fifo = fopen($fifoPath, 'r+');

            if ($fifo === false) {
                $this->logger->error('Failed to open input FIFO.', [
                    'container_id' => $containerId,
                    'session_id' => $sessionId,
                ]);
                $this->cleanupSession($fifoPath, $metaPath, $dockerSocket);

                return;
            }

            stream_set_blocking($dockerSocket, false);
            stream_set_blocking($fifo, false);

            try {
                $this->proxyLoop($dockerSocket, $fifo);
            } finally {
                fclose($fifo);
                $this->cleanupSession($fifoPath, $metaPath, $dockerSocket);

                $this->logger->info('Exec session closed.', [
                    'container_id' => $containerId,
                    'session_id' => $sessionId,
                ]);
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Receives terminal input from the client and writes it to the session FIFO.
     */
    public function input(Request $request, string $sessionId): JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sessionId)) {
            return response()->json(['error' => 'Invalid session ID.'], 400);
        }

        $fifoPath = self::SESSION_DIR."/{$sessionId}.fifo";

        if (! file_exists($fifoPath)) {
            return response()->json(['error' => 'Session not found.'], 404);
        }

        $data = $request->getContent();

        if ($data === '') {
            return response()->json(['error' => 'Empty input.'], 400);
        }

        $fifo = @fopen($fifoPath, 'w');

        if ($fifo === false) {
            return response()->json(['error' => 'Session no longer active.'], 410);
        }

        fwrite($fifo, $data);
        fclose($fifo);

        return response()->json([], 204);
    }

    /**
     * Resizes the exec TTY to match the client's terminal dimensions.
     */
    public function resize(Request $request, string $sessionId): JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sessionId)) {
            return response()->json(['error' => 'Invalid session ID.'], 400);
        }

        $metaPath = self::SESSION_DIR."/{$sessionId}.meta";

        if (! file_exists($metaPath)) {
            return response()->json(['error' => 'Session not found.'], 404);
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        $execId = $meta['exec_id'] ?? null;

        if (! is_string($execId) || $execId === '') {
            return response()->json(['error' => 'Session metadata corrupt.'], 500);
        }

        $cols = (int) $request->input('cols', 80);
        $rows = (int) $request->input('rows', 24);

        $this->execService->resizeExec($execId, $cols, $rows);

        return response()->json([], 204);
    }

    /**
     * Proxies bytes between the Docker exec socket (output) and the FIFO (input)
     * using stream_select() for non-blocking multiplexed I/O.
     *
     * @param  resource  $dockerSocket
     * @param  resource  $fifo
     */
    private function proxyLoop($dockerSocket, $fifo): void
    {
        while (true) {
            if (connection_aborted()) {
                break;
            }

            $read = [$dockerSocket, $fifo];
            $write = null;
            $except = null;

            $selected = @stream_select($read, $write, $except, 0, 200_000);

            if ($selected === false) {
                break;
            }

            // Docker exec stdout → HTTP chunked response.
            if (in_array($dockerSocket, $read, true)) {
                $data = @fread($dockerSocket, 4096);

                if ($data === false || $data === '' || feof($dockerSocket)) {
                    break;
                }

                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            // FIFO input (from POST /input) → Docker exec stdin.
            if (in_array($fifo, $read, true)) {
                $data = @fread($fifo, 4096);

                if ($data !== false && $data !== '') {
                    @fwrite($dockerSocket, $data);
                }
            }
        }
    }

    private function ensureSessionDir(): void
    {
        if (! is_dir(self::SESSION_DIR)) {
            mkdir(self::SESSION_DIR, 0700, true);
        }
    }

    /**
     * @param  resource  $dockerSocket
     */
    private function cleanupSession(string $fifoPath, string $metaPath, $dockerSocket): void
    {
        @fclose($dockerSocket);
        @unlink($fifoPath);
        @unlink($metaPath);
    }
}
