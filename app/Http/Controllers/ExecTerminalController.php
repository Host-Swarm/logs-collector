<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ExecTerminalController extends Controller
{
    public function __invoke(Request $request, string $containerId): View
    {
        abort_unless((bool) preg_match('/^[a-f0-9]{12,64}$/', $containerId), 400, 'Invalid container ID.');

        return view('exec.terminal', [
            'containerId' => $containerId,
            'token' => (string) ($request->query('token') ?? ''),
        ]);
    }
}
