<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Log Viewer</title>
    @vite(['resources/css/app.css', 'resources/js/log-viewer.js'])
    <style>
        html, body { height: 100%; margin: 0; }
        #terminal-container .xterm { height: 100%; }
        #terminal-container .xterm-viewport { overflow-y: scroll; }
        #terminal-container .xterm-screen { height: 100%; }
    </style>
</head>
<body class="bg-[#0d1117] text-[#c9d1d9] h-full flex flex-col overflow-hidden">

    {{-- ── Header ──────────────────────────────────────────────────────── --}}
    <header class="bg-[#161b22] border-b border-[#30363d] px-4 py-2 flex items-center gap-3 shrink-0">

        {{-- Breadcrumb navigation --}}
        <nav class="flex items-center gap-1.5 text-sm min-w-0 flex-1">
            @php $tokenQuery = request()->query('accessToken') ? '?accessToken=' . urlencode(request()->query('accessToken')) : '' @endphp
            <a href="/logs{{ $tokenQuery }}" class="text-[#58a6ff] hover:underline shrink-0">All Logs</a>

            @if (!empty($scope['stack']))
                <span class="text-[#484f58]">/</span>
                @if (!empty($scope['service']) || !empty($scope['container_id']))
                    <a href="/logs/{{ rawurlencode($scope['stack']) }}{{ $tokenQuery }}" class="text-[#58a6ff] hover:underline truncate max-w-[160px]" title="{{ $scope['stack'] }}">{{ $scope['stack'] }}</a>
                @else
                    <span class="text-[#c9d1d9] truncate max-w-[160px]" title="{{ $scope['stack'] }}">{{ $scope['stack'] }}</span>
                @endif
            @endif

            @if (!empty($scope['service']))
                <span class="text-[#484f58]">/</span>
                @if (!empty($scope['container_id']))
                    <a href="/logs/{{ rawurlencode($scope['stack']) }}/{{ rawurlencode($scope['service']) }}{{ $tokenQuery }}" class="text-[#58a6ff] hover:underline truncate max-w-[160px]" title="{{ $scope['service'] }}">{{ $scope['service'] }}</a>
                @else
                    <span class="text-[#c9d1d9] truncate max-w-[160px]" title="{{ $scope['service'] }}">{{ $scope['service'] }}</span>
                @endif
            @endif

            @if (!empty($scope['container_id']))
                <span class="text-[#484f58]">/</span>
                <span class="text-[#c9d1d9] font-mono text-xs truncate" title="{{ $scope['container_id'] }}">{{ substr($scope['container_id'], 0, 12) }}</span>
            @endif
        </nav>

        {{-- Connection status --}}
        <div class="flex items-center gap-2 shrink-0">
            <span class="w-2 h-2 rounded-full bg-[#6e7681]" id="status-dot"></span>
            <span class="text-xs text-[#8b949e] whitespace-nowrap" id="status-text">Connecting...</span>
        </div>
    </header>

    {{-- ── Terminal ─────────────────────────────────────────────────────── --}}
    <div class="flex-1 overflow-hidden min-h-0">
        <div id="terminal-container" class="w-full h-full p-1"></div>
    </div>

    <script>
        window.__LOG_SCOPE = @json($scope);
    </script>
</body>
</html>
