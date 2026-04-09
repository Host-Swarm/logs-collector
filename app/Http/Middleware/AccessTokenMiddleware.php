<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Contracts\ScopedTokenValidator;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class AccessTokenMiddleware
{
    public function __construct(
        private ScopedTokenValidator $validator,
        private LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The /up endpoint is used by infrastructure health checks and must remain unauthenticated.
        if ($request->is('up')) {
            return $next($request);
        }

        $token = $request->query('accessToken');
        
        if (! is_string($token) || $token === '') {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $scope = $this->resolveScope($request);
        
        if (! $this->validator->validate($token, $scope)) {
            $this->logger->info('Access token validation failed.', [
                'scope' => $scope,
                'endpoint' => $request->path(),
            ]);

            return response()->json(['error' => 'Unauthorized.'], 401);
        }
        
        return $next($request);
    }

    private function resolveScope(Request $request): string
    {
        $path = $request->path();

        // /api/stacks/{stack}[/...] → scope = stack name
        if (preg_match('#^api/stacks/([^/]+)#', $path, $matches)) {
            return rawurldecode($matches[1]);
        }

        // /logs/{stack}[/...] (web log viewer routes)
        if (preg_match('#^logs/([^/]+)#', $path, $matches)) {
            return rawurldecode($matches[1]);
        }

        // Container and exec routes without a URL-identifiable stack — caller provides ?stack=name
        $stackQuery = $request->query('stack');
        if (is_string($stackQuery) && $stackQuery !== '') {
            return $stackQuery;
        }

        return 'global';
    }
}
