import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const CONTAINER_COLORS = [
    '\x1b[36m',
    '\x1b[33m',
    '\x1b[32m',
    '\x1b[35m',
    '\x1b[34m',
    '\x1b[91m',
    '\x1b[96m',
    '\x1b[93m',
    '\x1b[92m',
    '\x1b[95m',
];

const RESET = '\x1b[0m';
const DIM = '\x1b[2m';
const RED = '\x1b[31m';
const STDERR_COLOR = '\x1b[38;5;203m';

class LogViewer {
    constructor(scope) {
        this.scope = scope;
        this.term = null;
        this.fitAddon = null;
        this.abortControllers = [];
        this.activeStreams = 0;
    }

    init(containerElement) {
        this.term = new Terminal({
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

        this.fitAddon = new FitAddon();
        this.term.loadAddon(this.fitAddon);
        this.term.open(containerElement);
        this.fitAddon.fit();

        window.addEventListener('resize', () => this.fitAddon?.fit());

        this.start();
    }

    async start() {
        this.updateStatus('Discovering containers...', 'connecting');

        let containers;
        try {
            containers = await this.discoverContainers();
        } catch (err) {
            this.updateStatus('Discovery failed', 'error');
            this.term.writeln(`${RED}Failed to discover containers: ${err.message}${RESET}`);
            return;
        }

        if (containers.length === 0) {
            this.updateStatus('No running containers', 'idle');
            this.term.writeln('\x1b[33mNo running containers found for this scope.\x1b[0m');
            return;
        }

        const count = containers.length;
        this.term.writeln(`${DIM}Connecting to ${count} container(s)...${RESET}\r\n`);
        this.updateStatus(`Streaming ${count} container(s)`, 'connected');

        containers.forEach((container, index) => {
            const color = CONTAINER_COLORS[index % CONTAINER_COLORS.length];
            this.streamContainer(container, color, count > 1);
        });
    }

    async discoverContainers() {
        const { type, stack, service, container_id } = this.scope;

        if (type === 'container') {
            return [{ id: container_id, name: container_id.slice(0, 12) }];
        }

        if (type === 'all') {
            const data = await this.fetchJson('/api/stacks');
            const containers = [];
            for (const s of data.stacks ?? []) {
                const detail = await this.fetchJson(`/api/stacks/${encodeURIComponent(s.name)}`);
                for (const svc of detail.services ?? []) {
                    for (const c of svc.containers ?? []) {
                        if (c.state === 'running') {
                            containers.push({ id: c.id, name: c.name ?? c.id.slice(0, 12) });
                        }
                    }
                }
            }
            return containers;
        }

        if (type === 'stack') {
            const detail = await this.fetchJson(`/api/stacks/${encodeURIComponent(stack)}`);
            const containers = [];
            for (const svc of detail.services ?? []) {
                for (const c of svc.containers ?? []) {
                    if (c.state === 'running') {
                        containers.push({ id: c.id, name: c.name ?? c.id.slice(0, 12) });
                    }
                }
            }
            return containers;
        }

        if (type === 'service') {
            const detail = await this.fetchJson(`/api/stacks/${encodeURIComponent(stack)}`);
            const containers = [];
            for (const svc of detail.services ?? []) {
                if (svc.name === service) {
                    for (const c of svc.containers ?? []) {
                        if (c.state === 'running') {
                            containers.push({ id: c.id, name: c.name ?? c.id.slice(0, 12) });
                        }
                    }
                }
            }
            return containers;
        }

        return [];
    }

    async fetchJson(url) {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status} from ${url}`);
        }
        return response.json();
    }

    streamContainer(container, color, showPrefix) {
        const controller = new AbortController();
        this.abortControllers.push(controller);
        this.activeStreams++;

        const run = async () => {
            const params = new URLSearchParams({ tail: '100', follow: '1', stdout: '1', stderr: '1' });
            const url = `/api/containers/${container.id}/stream?${params}`;

            try {
                const response = await fetch(url, { signal: controller.signal });

                if (!response.ok) {
                    this.term.writeln(`${color}[${container.name}]${RESET} ${RED}Stream error: HTTP ${response.status}${RESET}`);
                    return;
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) {
                        break;
                    }

                    buffer += decoder.decode(value, { stream: true });

                    const newlinePos = buffer.lastIndexOf('\n');
                    if (newlinePos < 0) {
                        continue;
                    }

                    const complete = buffer.slice(0, newlinePos);
                    buffer = buffer.slice(newlinePos + 1);

                    for (const raw of complete.split('\n')) {
                        if (raw === '') {
                            continue;
                        }

                        let channel = 'stdout';
                        let text = raw;

                        if (raw.startsWith('stdout: ')) {
                            text = raw.slice(8);
                        } else if (raw.startsWith('stderr: ')) {
                            channel = 'stderr';
                            text = raw.slice(8);
                        }

                        if (text.endsWith('\r')) {
                            text = text.slice(0, -1);
                        }

                        const styledText = channel === 'stderr' ? `${STDERR_COLOR}${text}${RESET}` : text;
                        const line = showPrefix ? `${color}[${container.name}]${RESET} ${styledText}` : styledText;

                        this.term.writeln(line);
                    }
                }

                this.term.writeln(`${DIM}${showPrefix ? `[${container.name}] ` : ''}--- stream ended ---${RESET}`);
            } catch (err) {
                if (err.name === 'AbortError') {
                    return;
                }
                this.term.writeln(
                    `${showPrefix ? `${color}[${container.name}]${RESET} ` : ''}${RED}Connection error: ${err.message}${RESET}`,
                );
            } finally {
                this.activeStreams--;
                if (this.activeStreams === 0) {
                    this.updateStatus('All streams ended', 'idle');
                }
            }
        };

        run();
    }

    updateStatus(text, state) {
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
        this.abortControllers.forEach((c) => c.abort());
        this.abortControllers = [];
        this.fitAddon = null;
        if (this.term) {
            this.term.dispose();
            this.term = null;
        }
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('terminal-container');
    if (!container) {
        return;
    }

    const scope = window.__LOG_SCOPE ?? { type: 'all' };
    const viewer = new LogViewer(scope);
    viewer.init(container);
});
