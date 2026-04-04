<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Contracts\TokenValidator;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class PassportOneTimeMiddleware
{
    public function __construct(
        private TokenValidator $validator,
        private LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->extractBearer($request);

        if ($bearer === null) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $containerId = $request->route('containerId');

        if (! is_string($containerId) || ! preg_match('/^[a-f0-9]{12,64}$/', $containerId)) {
            return response()->json(['error' => 'Invalid container ID.'], 400);
        }

        if (! $this->validator->validate($bearer, $containerId)) {
            $this->logger->info('Passport token validation failed.', [
                'container_id' => $containerId,
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
