import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const containerId = window.__EXEC_CONTAINER_ID ?? '';
const ACCESS_TOKEN = new URLSearchParams(window.location.search).get('accessToken') ?? '';

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

// ── WebSocket exec session ────────────────────────────────────────────────

let ws = null;

function buildWsUrl() {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const params = new URLSearchParams();
    if (ACCESS_TOKEN) {
        params.set('accessToken', ACCESS_TOKEN);
    }
    return `${protocol}//${window.location.host}/ws/exec/${containerId}?${params}`;
}

function connect() {
    if (ws) {
        ws.close();
        ws = null;
    }

    setStatus('Connecting...', 'connecting');

    ws = new WebSocket(buildWsUrl());
    ws.binaryType = 'arraybuffer';

    ws.onopen = () => {
        setStatus('Connected', 'connected');
        term.focus();

        // Send initial resize
        ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows }));
    };

    ws.onmessage = (event) => {
        if (typeof event.data === 'string') {
            try {
                const msg = JSON.parse(event.data);
                if (msg.type === 'session') {
                    return;
                }
                if (msg.error) {
                    setStatus(msg.error, 'error');
                    term.writeln(`\r\n\x1b[31m[${msg.error}]\x1b[0m`);
                    return;
                }
            } catch { /* ignore non-JSON text */ }
            return;
        }

        // Binary data — raw terminal output from Docker
        term.write(new Uint8Array(event.data));
    };

    ws.onerror = () => {};

    ws.onclose = () => {
        ws = null;
        setStatus('Session ended', 'idle');
        term.writeln('\r\n\x1b[2m[Session closed]\x1b[0m');
    };
}

// ── Bootstrap ──────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const element = document.getElementById('terminal-container');
    if (!element) return;

    term.open(element);
    fitAddon.fit();
    window.addEventListener('resize', () => fitAddon.fit());

    // Forward terminal input over WebSocket.
    term.onData((data) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(data);
        }
    });

    // Notify Docker of terminal resize over WebSocket.
    term.onResize(({ cols, rows }) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'resize', cols, rows }));
        }
    });

    document.getElementById('reconnect-btn')?.addEventListener('click', connect);

    connect();
});
