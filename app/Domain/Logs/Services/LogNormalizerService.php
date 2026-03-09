<?php

declare(strict_types=1);

namespace App\Domain\Logs\Services;

use App\Domain\Logs\DTOs\DiscoveredContainerDTO;
use App\Domain\Logs\DTOs\NormalizedLogPayloadDTO;
use DateTimeImmutable;
use DateTimeZone;

final class LogNormalizerService
{
    public function normalize(
        DiscoveredContainerDTO $container,
        string $channel,
        string $raw,
        ?DateTimeImmutable $timestamp = null,
    ): NormalizedLogPayloadDTO {
        $timestamp = $timestamp ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $message = rtrim($raw, "\r\n");
        $stackName = $container->stackName;

        return new NormalizedLogPayloadDTO([
            'event' => 'container.log',
            'timestamp' => $timestamp->format(DateTimeImmutable::ATOM),
            'swarm' => [
                'key' => $container->swarmKey,
            ],
            'stack' => [
                'name' => $stackName,
            ],
            'service' => [
                'id' => $container->serviceId,
                'name' => $container->serviceName,
            ],
            'container' => [
                'id' => $container->containerId,
                'name' => $container->containerName,
            ],
            'log' => [
                'channel' => $channel,
                'raw' => $raw,
                'message' => $message,
            ],
            'meta' => [
                'task_id' => $container->taskId,
                'task_slot' => $container->taskSlot,
                'node_id' => $container->nodeId,
                'node_hostname' => $container->nodeHostname,
                'service_labels' => $container->serviceLabels,
                'container_labels' => $container->containerLabels,
                'source' => 'docker',
                'extra' => [
                    'service_mode' => $container->serviceMode,
                    'task_state' => $container->taskState,
                    'desired_state' => $container->desiredState,
                    'container_state' => $container->containerState,
                    'container_status' => $container->containerStatus,
                    'container_image' => $container->containerImage,
                ],
            ],
        ], $timestamp);
    }
}
