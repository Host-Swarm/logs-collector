<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

final class LogViewerController extends Controller
{
    public function index(): View
    {
        return view('logs.viewer', [
            'scope' => ['type' => 'all'],
            'title' => 'All Containers',
        ]);
    }

    public function stack(string $stack): View
    {
        return view('logs.viewer', [
            'scope' => ['type' => 'stack', 'stack' => $stack],
            'title' => "Stack: {$stack}",
        ]);
    }

    public function service(string $stack, string $service): View
    {
        return view('logs.viewer', [
            'scope' => ['type' => 'service', 'stack' => $stack, 'service' => $service],
            'title' => "{$service} (stack: {$stack})",
        ]);
    }

    public function container(string $stack, string $service, string $containerId): View
    {
        return view('logs.viewer', [
            'scope' => [
                'type' => 'container',
                'stack' => $stack,
                'service' => $service,
                'container_id' => $containerId,
            ],
            'title' => "Container: {$containerId}",
        ]);
    }
}
