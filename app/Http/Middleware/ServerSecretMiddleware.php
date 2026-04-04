<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ServerSecretMiddleware
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('logs_collector.server_secret');

        if (! is_string($configured) || $configured === '') {
            $this->logger->error('SERVER_SECRET is not configured.');

            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $bearer = $this->extractBearer($request);

        if ($bearer === null || ! hash_equals($configured, $bearer)) {
            $this->logger->info('Server secret authentication failed.', [
                'endpoint' => $request->path(),
            ]);

            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }
}
