import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const containerId = window.__EXEC_CONTAINER_ID ?? '';
const token = window.__EXEC_TOKEN ?? '';

// ── Terminal setup ─────────────────────────────────────────────────────────

const term = new Terminal({
    theme: {
        background: '#0d1117',
        foreground: '#c9d1d9',
        cursor: '#58a6ff',
        black: '#0d1117',
        red: '#ff7b72',
        green: '#3fb950',
        yellow: '#d29922',
        blue: '#58a6ff',
        magenta: '#bc8cff',
        cyan: '#39c5cf',
        white: '#b1bac4',
        brightBlack: '#6e7681',
        brightRed: '#ffa198',
        brightGreen: '#56d364',
        brightYellow: '#e3b341',
        brightBlue: '#79c0ff',
        brightMagenta: '#d2a8ff',
        brightCyan: '#56d9e5',
        brightWhite: '#f0f6fc',
    },
    fontFamily: '"Cascadia Code", "Fira Code", Menlo, Consolas, monospace',
    fontSize: 13,
    lineHeight: 1.35,
    cursorBlink: true,
    cursorStyle: 'bar',
    scrollback: 5000,
    convertEol: true,
});

const fitAddon = new FitAddon();
term.loadAddon(fitAddon);

// ── Status helpers ─────────────────────────────────────────────────────────

function setStatus(text, state) {
    const statusText = document.getElementById('status-text');
    const statusDot = document.getElementById('status-dot');
    const reconnectBtn = document.getElementById('reconnect-btn');

    if (statusText) statusText.textContent = text;

    if (statusDot) {
        statusDot.className = 'w-2 h-2 rounded-full';
        const colors = {
            connected: 'bg-[#3fb950]',
            connecting: 'bg-[#d29922]',
            idle: 'bg-[#6e7681]',
            error: 'bg-[#ff7b72]',
        };
        statusDot.classList.add(colors[state] ?? colors.idle);
    }

    if (reconnectBtn) {
        reconnectBtn.classList.toggle('hidden', state !== 'error' && state !== 'idle');
    }
}

// ── HTTP exec session ─────────────────────────────────────────────────────

let sessionId = null;
let abortController = null;

function buildExecUrl() {
    const params = token ? `?token=${encodeURIComponent(token)}` : '';
    return `/api/containers/${containerId}/exec${params}`;
}

function sendInput(data) {
    if (!sessionId) return;
    fetch(`/api/containers/exec/${sessionId}/input`, {
        method: 'POST',
        body: data,
        keepalive: true,
    }).catch(() => {});
}

function sendResize(cols, rows) {
    if (!sessionId) return;
    fetch(`/api/containers/exec/${sessionId}/resize`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cols, rows }),
    }).catch(() => {});
}

async function connect() {
    if (abortController) {
        abortController.abort();
    }

    sessionId = null;
    abortController = new AbortController();
    const { signal } = abortController;

    setStatus('Connecting...', 'connecting');

    try {
        const response = await fetch(buildExecUrl(), { signal });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            setStatus(body.error || 'Connection failed', 'error');
            term.writeln(`\r\n\x1b[31m[${body.error || `HTTP ${response.status}`}]\x1b[0m`);
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let firstLine = true;
        let buffer = '';

        setStatus('Connected', 'connected');
        term.focus();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            if (firstLine) {
                // The first line of the response is a JSON session descriptor.
                buffer += decoder.decode(value, { stream: true });
                const newlineIdx = buffer.indexOf('\n');
                if (newlineIdx === -1) continue;

                const line = buffer.slice(0, newlineIdx);
                const rest = buffer.slice(newlineIdx + 1);
                buffer = '';
                firstLine = false;

                try {
                    const meta = JSON.parse(line);
                    sessionId = meta.session;
                } catch {
                    setStatus('Invalid session', 'error');
                    break;
                }

                // Send initial resize now that session is established.
                sendResize(term.cols, term.rows);

                // Write any remaining bytes after the session line.
                if (rest.length > 0) {
                    term.write(new TextEncoder().encode(rest));
                }
                continue;
            }

            // Subsequent chunks are raw terminal output bytes.
            term.write(new Uint8Array(value.buffer, value.byteOffset, value.byteLength));
        }

        // Stream ended cleanly.
        sessionId = null;
        setStatus('Session ended', 'idle');
        term.writeln('\r\n\x1b[2m[Session closed]\x1b[0m');
    } catch (err) {
        if (err.name === 'AbortError') return;
        sessionId = null;
        setStatus('Disconnected', 'error');
        term.writeln('\r\n\x1b[31m[Connection lost]\x1b[0m');
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const element = document.getElementById('terminal-container');
    if (!element) return;

    term.open(element);
    fitAddon.fit();
    window.addEventListener('resize', () => fitAddon.fit());

    // Forward terminal input to the exec session via HTTP POST.
    term.onData((data) => sendInput(data));

    // Notify Docker of terminal resize.
    term.onResize(({ cols, rows }) => sendResize(cols, rows));

    document.getElementById('reconnect-btn')?.addEventListener('click', connect);

    connect();
});
