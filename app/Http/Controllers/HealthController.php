<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Docker\DTOs\ContainerDTO;
use App\Domain\Docker\DTOs\ServiceDTO;
use App\Domain\Docker\DTOs\StackDTO;
use App\Domain\Docker\Services\StackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class HealthController extends Controller
{
    public function __construct(
        private StackService $stackService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $swarmKey = (string) config('logs_collector.swarm_key');

        try {
            $stacks = $this->stackService->listStacks($swarmKey);
        } catch (Throwable $exception) {
            $this->logger->error('Health check failed to list stacks.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'degraded',
                'error' => 'Docker unavailable.',
            ], 503);
        }

        return response()->json([
            'status' => 'healthy',
            'stacks' => array_map(fn (StackDTO $stack) => $this->formatStack($stack), $stacks),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStack(StackDTO $stack): array
    {
        return [
            'name' => $stack->name,
            'log_viewer_url' => url('/api/logs/'.rawurlencode($stack->name)),
            'services' => array_map(fn (ServiceDTO $service) => $this->formatService($stack->name, $service), $stack->services),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatService(string $stackName, ServiceDTO $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'mode' => $service->mode,
            'replicas' => $service->replicas,
            'image' => $service->image,
            'log_viewer_url' => url('/api/logs/'.rawurlencode($stackName).'/'.rawurlencode($service->name)),
            'containers' => array_map(
                fn (ContainerDTO $container) => $this->formatContainer($stackName, $service->name, $container),
                $service->containers,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatContainer(string $stackName, string $serviceName, ContainerDTO $container): array
    {
        return [
            'id' => $container->id,
            'name' => $container->name,
            'state' => $container->state,
            'node_id' => $container->nodeId,
            'node_hostname' => $container->nodeHostname,
            'task_id' => $container->taskId,
            'task_slot' => $container->taskSlot,
            'image' => $container->image,
            'log_viewer_url' => url(
                '/api/logs/'.rawurlencode($stackName).'/'.rawurlencode($serviceName).'/'.rawurlencode($container->id),
            ),
        ];
    }
}
