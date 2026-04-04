<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Docker\Services\StackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class StackController extends Controller
{
    public function __construct(
        private StackService $stackService,
        private LoggerInterface $logger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $swarmKey = (string) config('logs_collector.swarm_key');

        try {
            $stacks = $this->stackService->listStacks($swarmKey);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list stacks from Docker.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Docker unavailable.'], 503);
        }

        return response()->json([
            'stacks' => array_map(fn ($stack) => $stack->toSummaryArray(), $stacks),
        ]);
    }

    public function show(Request $request, string $stack): JsonResponse
    {
        $swarmKey = (string) config('logs_collector.swarm_key');

        try {
            $stackDTO = $this->stackService->findStack($swarmKey, $stack);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to fetch stack detail from Docker.', [
                'stack' => $stack,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Docker unavailable.'], 503);
        }

        if ($stackDTO === null) {
            return response()->json(['error' => 'Stack not found.'], 404);
        }

        return response()->json([
            'stack' => $stackDTO->name,
            'services' => array_map(fn ($service) => $service->toArray(), $stackDTO->services),
        ]);
    }
}
