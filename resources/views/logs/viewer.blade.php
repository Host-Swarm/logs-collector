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
            <a href="/logs" class="text-[#58a6ff] hover:underline shrink-0">All Logs</a>

            @if (!empty($scope['stack']))
                <span class="text-[#484f58]">/</span>
                @if (!empty($scope['service']) || !empty($scope['container_id']))
                    <a href="/logs/{{ rawurlencode($scope['stack']) }}" class="text-[#58a6ff] hover:underline truncate max-w-[160px]" title="{{ $scope['stack'] }}">{{ $scope['stack'] }}</a>
                @else
                    <span class="text-[#c9d1d9] truncate max-w-[160px]" title="{{ $scope['stack'] }}">{{ $scope['stack'] }}</span>
                @endif
            @endif

            @if (!empty($scope['service']))
                <span class="text-[#484f58]">/</span>
                @if (!empty($scope['container_id']))
                    <a href="/logs/{{ rawurlencode($scope['stack']) }}/{{ rawurlencode($scope['service']) }}" class="text-[#58a6ff] hover:underline truncate max-w-[160px]" title="{{ $scope['service'] }}">{{ $scope['service'] }}</a>
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


    {{-- ── Header ──────────────────────────────────────────────────────── --}}
    <header class="bg-[#161b22] border-b border-[#30363d] px-4 py-2 flex items-center gap-3 shrink-0">

        {{-- Breadcrumb navigation --}}
        <nav class="flex items-center gap-1.5 text-sm min-w-0 flex-1">
            <a href="/logs" class="text-[#58a6ff] hover:underline shrink-0">All Logs</a>

            @if (!empty($scope['stack']))
                <span class="text-[#484f58]">/</span>
                @if (!empty($scope['service']) || !empty($scope['container_id']))
                    <a href="/logs/{{ rawurlencode($scope['stack']) }}" class="text-[#58a6ff] hover:underline truncate max-w-[160px]" title="{{ $scope['stack'] }}">{{ $scope['stack'] }}</a>
                @else
                    <span class="text-[#c9d1d9] truncate max-w-[160px]" title="{{ $scope['stack'] }}">{{ $scope['stack'] }}</span>
                @endif
            @endif

            @if (!empty($scope['service']))
                <span class="text-[#484f58]">/</span>
                @if (!empty($scope['container_id']))
                    <a href="/logs/{{ rawurlencode($scope['stack']) }}/{{ rawurlencode($scope['service']) }}" class="text-[#58a6ff] hover:underline truncate max-w-[160px]" title="{{ $scope['service'] }}">{{ $scope['service'] }}</a>
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
            <span class="text-xs text-[#8b949e] whitespace-nowrap" id="status-text">Requires authentication</span>
            <button
                id="disconnect-btn"
                class="hidden text-xs text-[#8b949e] hover:text-[#c9d1d9] px-2 py-0.5 border border-[#30363d] rounded hover:border-[#6e7681] transition-colors"
            >Disconnect</button>
        </div>
    </header>

    {{-- ── Terminal ─────────────────────────────────────────────────────── --}}
    <div class="flex-1 overflow-hidden min-h-0">
        <div id="terminal-container" class="w-full h-full p-1"></div>
    </div>

    {{-- ── Auth overlay ─────────────────────────────────────────────────── --}}
    <div id="auth-overlay" class="fixed inset-0 bg-[#0d1117]/95 backdrop-blur-sm flex items-center justify-center z-50">
        <div class="bg-[#161b22] border border-[#30363d] rounded-lg p-8 w-full max-w-sm shadow-2xl">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-8 h-8 rounded bg-[#238636] flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-[#f0f6fc]">Connect to Log Stream</h2>
                    <p class="text-xs text-[#8b949e]">{{ $title }}</p>
                </div>
            </div>

            <form id="auth-form">
                <label class="block text-xs text-[#8b949e] mb-1.5 uppercase tracking-wider font-medium">
                    Server Secret
                </label>
                <input
                    type="password"
                    id="secret-input"
                    class="w-full bg-[#0d1117] border border-[#30363d] rounded px-3 py-2 text-sm mb-4 focus:outline-none focus:border-[#58a6ff] text-[#c9d1d9] placeholder-[#484f58]"
                    placeholder="Enter server secret..."
                    autocomplete="current-password"
                    autofocus
                />
                <button
                    type="submit"
                    class="w-full bg-[#238636] hover:bg-[#2ea043] text-white py-2 px-4 rounded text-sm font-medium transition-colors"
                >
                    Connect &amp; Stream
                </button>
            </form>

            <p class="text-xs text-[#6e7681] mt-4 text-center">
                Secret is stored in session memory only and cleared on disconnect.
            </p>
        </div>
    </div>

    <script>
        window.__LOG_SCOPE = @json($scope);
    </script>
</body>
</html>
