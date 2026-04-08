import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const RESET = '\x1b[0m';
const RED = '\x1b[31m';
const BOLD = '\x1b[1m';
const DIM = '\x1b[2m';

const LABEL_COLORS = [
    '\x1b[96m', // bright cyan
    '\x1b[92m', // bright green
    '\x1b[93m', // bright yellow
    '\x1b[95m', // bright magenta
    '\x1b[94m', // bright blue
    '\x1b[36m', // cyan
    '\x1b[32m', // green
    '\x1b[33m', // yellow
    '\x1b[35m', // magenta
    '\x1b[34m', // blue
];

function buildTerminal() {
    return new Terminal({
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
        cursorStyle: 'bar',
        scrollback: 50000,
        convertEol: true,
    });
}

// ── Single-container log viewer ────────────────────────────────────────────

class LogViewer {
    constructor(containerId, options = {}) {
        this.containerId = containerId;
        this.label = options.label ?? null;
        this.labelColor = options.labelColor ?? '\x1b[36m';
        this.externalTerm = options.term ?? null;
        this.externalSignal = options.signal ?? null;
        this.skipHistory = options.skipHistory ?? false;
        this.term = null;
        this.fitAddon = null;
        this.ownAbort = null;
    }

    init(element) {
        if (this.externalTerm) {
            this.term = this.externalTerm;
        } else {
            this.term = buildTerminal();
            this.fitAddon = new FitAddon();
            this.term.loadAddon(this.fitAddon);
            this.term.open(element);
            this.fitAddon.fit();
            window.addEventListener('resize', () => this.fitAddon?.fit());
        }

        this.stream();
    }

    writeLine(line) {
        if (this.label) {
            const padded = this.label.padEnd(22);
            this.term.writeln(`${this.labelColor}${BOLD}${padded}${RESET}${DIM}|${RESET} ${line}`);
        } else {
            this.term.writeln(line);
        }
    }

    stream() {
        const signal = this.externalSignal ?? (this.ownAbort = new AbortController()).signal;

        const run = async () => {
            let lastTimestamp = null;
            let reconnectDelay = 3000;

            if (!this.externalTerm) {
                this.setStatus('Connecting...', 'connecting');
            }

            while (!signal.aborted) {
                const params = new URLSearchParams({
                    follow: '1',
                    stdout: '1',
                    stderr: '1',
                    timestamps: '1',
                });

                if (lastTimestamp !== null) {
                    params.set('since', lastTimestamp);
                } else if (this.skipHistory) {
                    params.set('since', Math.floor(Date.now() / 1000).toString());
                } else {
                    params.set('tail', '100');
                }

                try {
                    const response = await fetch(
                        `/api/containers/${this.containerId}/stream?${params}`,
                        { signal },
                    );

                    if (!response.ok) {
                        if (response.status === 404) {
                            if (!this.externalTerm) {
                                this.setStatus('Container not found', 'error');
                            }
                            this.writeLine(`${RED}Container not found.${RESET}`);
                            break;
                        }
                        if (response.status === 400) {
                            if (!this.externalTerm) {
                                this.setStatus('Invalid container ID', 'error');
                            }
                            break;
                        }
                        await this.sleep(reconnectDelay, signal);
                        reconnectDelay = Math.min(reconnectDelay * 2, 30000);
                        continue;
                    }

                    reconnectDelay = 3000;
                    if (!this.externalTerm) {
                        this.setStatus('Connected', 'connected');
                    }

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let bytes = new Uint8Array(0);

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;

                        // Append incoming chunk
                        const merged = new Uint8Array(bytes.length + value.length);
                        merged.set(bytes);
                        merged.set(value, bytes.length);
                        bytes = merged;

                        // Docker multiplexed log frame: 8-byte header + payload
                        while (bytes.length >= 8) {
                            const payloadLen =
                                (bytes[4] << 24) | (bytes[5] << 16) | (bytes[6] << 8) | bytes[7];

                            if (bytes.length < 8 + payloadLen) break;

                            const payload = bytes.slice(8, 8 + payloadLen);
                            bytes = bytes.slice(8 + payloadLen);

                            const text = decoder.decode(payload);
                            for (const line of text.split('\n')) {
                                const trimmed = line.endsWith('\r') ? line.slice(0, -1) : line;
                                if (trimmed === '') continue;

                                const tsMatch = trimmed.match(
                                    /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z) /,
                                );
                                if (tsMatch) {
                                    lastTimestamp = tsMatch[1];
                                    this.writeLine(trimmed.slice(tsMatch[0].length));
                                } else {
                                    this.writeLine(trimmed);
                                }
                            }
                        }
                    }

                    if (!this.externalTerm) {
                        this.setStatus('Reconnecting...', 'connecting');
                    }
                    await this.sleep(1000, signal);
                } catch (err) {
                    if (err.name === 'AbortError') break;
                    if (!this.externalTerm) {
                        this.setStatus('Reconnecting...', 'connecting');
                    }
                    await this.sleep(reconnectDelay, signal);
                    reconnectDelay = Math.min(reconnectDelay * 2, 30000);
                }
            }
        };

        run();
    }

    sleep(ms, signal) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(resolve, ms);
            signal?.addEventListener(
                'abort',
                () => {
                    clearTimeout(timer);
                    reject(new DOMException('Aborted', 'AbortError'));
                },
                { once: true },
            );
        });
    }

    setStatus(text, state) {
        const statusText = document.getElementById('status-text');
        const statusDot = document.getElementById('status-dot');

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
    }

    destroy() {
        this.ownAbort?.abort();
        this.fitAddon = null;
        if (this.term && !this.externalTerm) {
            this.term.dispose();
            this.term = null;
        }
    }
}

// ── Multi-container log viewer (stack / service / all scopes) ──────────────
class MultiLogViewer {
    constructor(scope) {
        this.scope = scope;
        this.viewers = [];
        this.term = null;
        this.fitAddon = null;
        this.abort = new AbortController();
    }

    async init(element) {
        this.term = buildTerminal();
        this.fitAddon = new FitAddon();
        this.term.loadAddon(this.fitAddon);
        this.term.open(element);
        this.fitAddon.fit();
        window.addEventListener('resize', () => this.fitAddon?.fit());

        this.setStatus('Discovering containers…', 'connecting');

        let containers;
        try {
            containers = await this.discoverContainers();
        } catch (err) {
            if (err.name === 'AbortError') {
                return;
            }
            this.setStatus('Discovery failed', 'error');
            this.term.writeln(`${RED}Failed to discover containers: ${err.message}${RESET}`);
            return;
        }

        if (containers.length === 0) {
            this.setStatus('No containers', 'idle');
            this.term.writeln('No running containers found.');
            return;
        }

        this.term.writeln(`${DIM}Streaming logs from ${containers.length} container(s)…${RESET}`);
        this.term.writeln('');

        for (let i = 0; i < containers.length; i++) {
            const { id, label } = containers[i];
            const color = LABEL_COLORS[i % LABEL_COLORS.length];
            const skipHistory = this.scope.type === 'all' || this.scope.type === 'stack';
            const viewer = new LogViewer(id, {
                term: this.term,
                label,
                labelColor: color,
                signal: this.abort.signal,
                skipHistory,
            });
            viewer.init(null);
            this.viewers.push(viewer);
        }

        this.setStatus(`Connected (${containers.length})`, 'connected');
    }

    async discoverContainers() {
        const { type, stack, service } = this.scope;
        const signal = this.abort.signal;

        if (type === 'stack' || type === 'service') {
            const res = await fetch(`/api/stacks/${encodeURIComponent(stack)}`, { signal });
            if (!res.ok) {
                throw new Error(`Stack API returned ${res.status}`);
            }
            const data = await res.json();

            if (type === 'service') {
                const svc = (data.services ?? []).find((s) => s.name === service);
                if (!svc) {
                    return [];
                }
                const running = (svc.containers ?? []).filter((c) => c.state === 'running');
                return running.map((c) => ({
                    id: c.id,
                    label:
                        running.length > 1
                            ? `${svc.name}.${c.task_slot ?? running.indexOf(c) + 1}`
                            : svc.name,
                }));
            }

            // type === 'stack'
            const containers = [];
            for (const svc of data.services ?? []) {
                const running = (svc.containers ?? []).filter((c) => c.state === 'running');
                for (const c of running) {
                    const label =
                        running.length > 1
                            ? `${svc.name}.${c.task_slot ?? running.indexOf(c) + 1}`
                            : svc.name;
                    containers.push({ id: c.id, label });
                }
            }
            return containers;
        }

        // type === 'all' — fetch all stacks then drill into each
        const res = await fetch('/api/stacks', { signal });
        if (!res.ok) {
            throw new Error(`Stacks API returned ${res.status}`);
        }
        const data = await res.json();
        const containers = [];
        for (const stackSummary of data.stacks ?? []) {
            try {
                const stackRes = await fetch(
                    `/api/stacks/${encodeURIComponent(stackSummary.name)}`,
                    { signal },
                );
                if (!stackRes.ok) {
                    continue;
                }
                const stackData = await stackRes.json();
                for (const svc of stackData.services ?? []) {
                    for (const c of (svc.containers ?? []).filter((c) => c.state === 'running')) {
                        containers.push({ id: c.id, label: `${stackSummary.name}/${svc.name}` });
                    }
                }
            } catch (err) {
                if (err.name === 'AbortError') {
                    throw err;
                }
                // skip stacks that fail to load
            }
        }
        return containers;
    }

    setStatus(text, state) {
        const statusText = document.getElementById('status-text');
        const statusDot = document.getElementById('status-dot');

        if (statusText) {
            statusText.textContent = text;
        }

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
    }

    destroy() {
        this.abort.abort();
        this.fitAddon = null;
        if (this.term) {
            this.term.dispose();
            this.term = null;
        }
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const element = document.getElementById('terminal-container');
    if (!element) {
        return;
    }

    const scope = window.__LOG_SCOPE ?? {};

    if (scope.type === 'container') {
        const viewer = new LogViewer(scope.container_id);
        viewer.init(element);
    } else {
        const viewer = new MultiLogViewer(scope);
        viewer.init(element);
    }
});
