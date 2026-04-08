<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Docker\Contracts\ExecService;
use App\Infrastructure\Docker\DockerApiException;
use App\Infrastructure\Docker\DockerHttpClient;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class ContainerExecController extends Controller
{
    public function __construct(
        private ExecService $execService,
        private DockerHttpClient $docker,
        private LoggerInterface $logger,
    ) {}

    /**
     * Opens an interactive exec session into the container via WebSocket.
     *
     * The endpoint:
     *  1. Verifies the container exists (returns 404 or 503 before upgrading if not).
     *  2. Performs the WebSocket 101 handshake.
     *  3. Creates a Docker exec instance (/bin/sh — hardcoded, not caller-controlled).
     *  4. Starts the exec and proxies bytes bidirectionally using stream_select().
     *
     * Runtime note: requires a WebSocket-capable server (PHP built-in server, FrankenPHP,
     * Swoole). PHP-FPM does not support connection hijacking.
     */
    public function __invoke(Request $request, string $containerId): mixed
    {
        if (! $this->isWebSocketUpgrade($request)) {
            return response()->json(['error' => 'WebSocket upgrade required.'], 426);
        }

        $key = $request->header('Sec-WebSocket-Key');

        if (! is_string($key) || $key === '') {
            return response()->json(['error' => 'Missing Sec-WebSocket-Key header.'], 400);
        }

        if (! preg_match('/^[a-f0-9]{12,64}$/', $containerId)) {
            return response()->json(['error' => 'Invalid container ID.'], 400);
        }

        // Verify the container exists before upgrading — we can still return proper
        // error codes at this point because headers haven't been sent yet.
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

        $this->logger->info('Exec session started.', [
            'container_id' => $containerId,
            'exec_id' => $execId,
        ]);

        // All pre-flight checks passed — perform the WebSocket handshake and take
        // over the connection. No Laravel response object is returned after this point.
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $this->sendWebSocketHandshake($accept);
        $this->proxyLoop($dockerSocket, $containerId);
    }

    private function isWebSocketUpgrade(Request $request): bool
    {
        return strtolower((string) $request->header('Upgrade', '')) === 'websocket'
            && str_contains(strtolower((string) $request->header('Connection', '')), 'upgrade');
    }

    private function sendWebSocketHandshake(string $accept): void
    {
        header('HTTP/1.1 101 Switching Protocols', true, 101);
        header('Upgrade: websocket');
        header('Connection: Upgrade');
        header('Sec-WebSocket-Accept: '.$accept);

        // Flush all PHP output buffers so the 101 response reaches the client
        // before we start reading/writing raw WebSocket frames.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();
    }

    /**
     * Proxies bytes bidirectionally between the WebSocket client and the Docker
     * exec stream using stream_select() for non-blocking multiplexed I/O.
     *
     * @param  resource  $dockerSocket
     */
    private function proxyLoop($dockerSocket, string $containerId): void
    {
        $clientInput = fopen('php://input', 'rb');
        $clientOutput = fopen('php://output', 'wb');

        if ($clientInput === false || $clientOutput === false) {
            fclose($dockerSocket);
            $this->logger->error('Failed to open client I/O streams for exec session.', [
                'container_id' => $containerId,
            ]);

            return;
        }

        stream_set_blocking($dockerSocket, false);
        stream_set_blocking($clientInput, false);

        // Input buffer for accumulating partial WebSocket frames from the client.
        $clientBuffer = '';

        while (true) {
            $read = [$dockerSocket, $clientInput];
            $write = null;
            $except = null;

            $selected = @stream_select($read, $write, $except, 0, 200000);

            if ($selected === false) {
                break;
            }

            // Docker exec stdout → encode as WebSocket binary frame → client.
            if (in_array($dockerSocket, $read, true)) {
                $data = fread($dockerSocket, 4096);

                if ($data === false || feof($dockerSocket)) {
                    // Docker shell exited — send a WebSocket close frame.
                    $this->sendCloseFrame($clientOutput);
                    break;
                }

                if ($data !== '') {
                    fwrite($clientOutput, $this->encodeWebSocketFrame($data));
                    fflush($clientOutput);
                }
            }

            // WebSocket client → decode frame → Docker exec stdin.
            if (in_array($clientInput, $read, true)) {
                $raw = fread($clientInput, 4096);

                if ($raw === false || feof($clientInput)) {
                    break;
                }

                if ($raw !== '') {
                    $clientBuffer .= $raw;
                    [$decoded, $clientBuffer] = $this->consumeWebSocketFrames($clientBuffer, $clientOutput, $dockerSocket);

                    if ($decoded === null) {
                        // Close frame received from client.
                        break;
                    }
                }
            }
        }

        fclose($dockerSocket);
        fclose($clientInput);
        fclose($clientOutput);

        $this->logger->info('Exec session closed.', [
            'container_id' => $containerId,
        ]);
    }

    /**
     * Consumes all complete WebSocket frames from the buffer.
     * Writes decoded payload to the Docker socket.
     * Returns [null, ''] if a close frame is encountered.
     * Returns ['ok', $remaining] otherwise.
     *
     * @param  resource  $dockerSocket
     * @param  resource  $clientOutput
     * @return array{0: string|null, 1: string}
     */
    private function consumeWebSocketFrames(string $buffer, $clientOutput, $dockerSocket): array
    {
        while ($buffer !== '') {
            [$result, $payload, $buffer] = $this->decodeWebSocketFrame($buffer);

            if ($result === 'incomplete') {
                break;
            }

            if ($result === 'close') {
                $this->sendCloseFrame($clientOutput);

                return [null, ''];
            }

            if ($result === 'ping') {
                // RFC 6455: must respond to ping with pong carrying same payload.
                fwrite($clientOutput, $this->encodePongFrame($payload));
                fflush($clientOutput);

                continue;
            }

            if ($result === 'data' && $payload !== '') {
                fwrite($dockerSocket, $payload);
            }
        }

        return ['ok', $buffer];
    }

    /**
     * Decodes the first WebSocket frame from the buffer.
     *
     * Returns:
     *   ['incomplete', '', $buffer]  — not enough bytes yet
     *   ['close', '', '']            — close frame (opcode 0x08)
     *   ['ping', $payload, $rest]    — ping frame (opcode 0x09)
     *   ['data', $payload, $rest]    — text/binary/continuation frame
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function decodeWebSocketFrame(string $raw): array
    {
        if (strlen($raw) < 2) {
            return ['incomplete', '', $raw];
        }

        $firstByte = ord($raw[0]);
        $secondByte = ord($raw[1]);
        $opcode = $firstByte & 0x0F;
        $masked = (bool) ($secondByte & 0x80);
        $payloadLength = $secondByte & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if (strlen($raw) < 4) {
                return ['incomplete', '', $raw];
            }

            $payloadLength = unpack('n', substr($raw, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            if (strlen($raw) < 10) {
                return ['incomplete', '', $raw];
            }

            $payloadLength = unpack('J', substr($raw, 2, 8))[1];
            $offset = 10;
        }

        $maskSize = $masked ? 4 : 0;
        $totalLength = $offset + $maskSize + $payloadLength;

        if (strlen($raw) < $totalLength) {
            return ['incomplete', '', $raw];
        }

        $remaining = substr($raw, $totalLength);

        if ($opcode === 0x08) {
            return ['close', '', ''];
        }

        $payload = substr($raw, $offset + $maskSize, $payloadLength);

        if ($masked) {
            $maskingKey = substr($raw, $offset, 4);
            $unmasked = '';

            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($maskingKey[$i % 4]));
            }

            $payload = $unmasked;
        }

        $type = $opcode === 0x09 ? 'ping' : 'data';

        return [$type, $payload, $remaining];
    }

    /**
     * Encodes a payload as an unmasked WebSocket binary frame (server → client).
     */
    private function encodeWebSocketFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x82); // FIN=1, RSV=0, opcode=binary(2)

        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126).pack('n', $length);
        } else {
            $frame .= chr(127).pack('J', $length);
        }

        return $frame.$payload;
    }

    /**
     * Encodes a WebSocket pong frame (server → client) carrying the given payload.
     */
    private function encodePongFrame(string $payload): string
    {
        $length = strlen($payload);

        return chr(0x8A).chr($length).$payload; // FIN=1, opcode=pong(10)
    }

    /**
     * Sends a WebSocket close frame (server → client).
     *
     * @param  resource  $clientOutput
     */
    private function sendCloseFrame($clientOutput): void
    {
        // Close frame: FIN=1, opcode=0x08, no masking, no payload.
        fwrite($clientOutput, chr(0x88).chr(0x00));
        fflush($clientOutput);
    }
}
