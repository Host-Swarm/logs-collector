<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Docker\DockerExecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class ContainerExecController extends Controller
{
    public function __construct(
        private DockerExecService $execService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Opens an interactive exec session into the container via WebSocket.
     *
     * This method performs a manual WebSocket upgrade and then proxies bytes
     * bidirectionally between the WebSocket client and the Docker exec stream.
     *
     * Requirements:
     *   - Client must send a valid WebSocket upgrade request.
     *   - The route must already be protected by PassportOneTimeMiddleware.
     *
     * Note: This implementation uses PHP's raw output/input stream and is designed
     * to run under php artisan serve (PHP built-in server) or a WebSocket-capable
     * runtime such as FrankenPHP or Swoole. PHP-FPM does not support WebSocket hijacking.
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

        // Send the WebSocket 101 handshake response.
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $this->sendWebSocketHandshake($accept);

        // Proxy bytes between the WebSocket client and the Docker exec socket.
        $this->proxyLoop($dockerSocket);
    }

    private function isWebSocketUpgrade(Request $request): bool
    {
        return strtolower((string) $request->header('Upgrade', '')) === 'websocket'
            && str_contains(strtolower((string) $request->header('Connection', '')), 'upgrade');
    }

    private function sendWebSocketHandshake(string $accept): void
    {
        // Bypass Laravel's response system to send a raw 101 response.
        header('HTTP/1.1 101 Switching Protocols');
        header('Upgrade: websocket');
        header('Connection: Upgrade');
        header('Sec-WebSocket-Accept: '.$accept);

        ob_end_flush();
        flush();
    }

    /**
     * Proxies bytes bidirectionally between the WebSocket client (php://input / php://output)
     * and the Docker exec socket using stream_select().
     *
     * @param  resource  $dockerSocket
     */
    private function proxyLoop($dockerSocket): void
    {
        $clientInput = fopen('php://input', 'rb');
        $clientOutput = fopen('php://output', 'wb');

        if ($clientInput === false || $clientOutput === false) {
            fclose($dockerSocket);

            return;
        }

        stream_set_blocking($dockerSocket, false);
        stream_set_blocking($clientInput, false);

        while (true) {
            $read = [$dockerSocket, $clientInput];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }

            // Data from Docker exec → encode as WebSocket frame → send to client.
            if (in_array($dockerSocket, $read, true)) {
                $data = fread($dockerSocket, 4096);

                if ($data === false || ($data === '' && feof($dockerSocket))) {
                    break;
                }

                if ($data !== '') {
                    fwrite($clientOutput, $this->encodeWebSocketFrame($data));
                    fflush($clientOutput);
                }
            }

            // Data from WebSocket client → decode frame → send to Docker exec.
            if (in_array($clientInput, $read, true)) {
                $raw = fread($clientInput, 4096);

                if ($raw === false || ($raw === '' && feof($clientInput))) {
                    break;
                }

                if ($raw !== '') {
                    $decoded = $this->decodeWebSocketFrame($raw);

                    if ($decoded === null) {
                        // Close frame or undecodable frame — end session.
                        break;
                    }

                    fwrite($dockerSocket, $decoded);
                }
            }
        }

        fclose($dockerSocket);
        fclose($clientInput);
        fclose($clientOutput);

        $this->logger->info('Exec session closed.');
    }

    /**
     * Encodes a binary/text payload as an unmasked WebSocket frame (server → client).
     */
    private function encodeWebSocketFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x82); // FIN + binary opcode (0x02)

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
     * Decodes a masked WebSocket frame (client → server).
     * Returns the unmasked payload, or null for close/undecodable frames.
     */
    private function decodeWebSocketFrame(string $raw): ?string
    {
        if (strlen($raw) < 2) {
            return null;
        }

        $firstByte = ord($raw[0]);
        $secondByte = ord($raw[1]);
        $opcode = $firstByte & 0x0F;

        // Close frame.
        if ($opcode === 0x08) {
            return null;
        }

        $masked = (bool) ($secondByte & 0x80);
        $payloadLength = $secondByte & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if (strlen($raw) < 4) {
                return null;
            }

            $payloadLength = unpack('n', substr($raw, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            if (strlen($raw) < 10) {
                return null;
            }

            $payloadLength = unpack('J', substr($raw, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($raw) < $offset + 4) {
                return null;
            }

            $maskingKey = substr($raw, $offset, 4);
            $offset += 4;
            $payload = substr($raw, $offset, $payloadLength);
            $unmasked = '';

            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($maskingKey[$i % 4]));
            }

            return $unmasked;
        }

        return substr($raw, $offset, $payloadLength);
    }
}
