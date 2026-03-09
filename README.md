# Host Swarm Logs Collector

Host Swarm Logs Collector is a Laravel service responsible for collecting Docker Swarm container logs and forwarding them to the main **server-manager** application.

The service runs inside infrastructure where the Docker socket is mounted and continuously streams logs from running containers.

Logs are normalized into a structured format and forwarded over a websocket connection to the upstream server-manager.

---

# Responsibilities

The collector is responsible for:

• Discovering Docker Swarm services and containers
• Streaming logs from containers
• Normalizing raw logs into structured events
• Forwarding those events to the server-manager over websocket
• Handling reconnects and retry logic
• Providing observability and diagnostics for the log pipeline

---

# Architecture Overview

Docker Swarm
│
│ docker.sock
▼
Logs Collector (Laravel)
│
│ normalize events
▼
WebSocket Transport
│
▼
Server Manager

<pre class="overflow-visible! px-0!" data-start="1801" data-end="2047"><div class="relative w-full mt-4 mb-1"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border border-token-border-light border-radius-3xl corner-superellipse/1.1 rounded-3xl"><div class="h-full w-full border-radius-3xl bg-token-bg-elevated-secondary corner-superellipse/1.1 overflow-clip rounded-3xl lxnfua_clipPathFallback"><div class="pointer-events-none absolute end-1.5 top-1 z-2 md:end-2 md:top-1"></div><div class="pt-3"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼd ͼr"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><br/><span>Pipeline stages:</span><br/><br/><span>1. Docker service discovery</span><br/><span>2. Container log streaming</span><br/><span>3. Event normalization</span><br/><span>4. Forwarding to upstream server-manager</span><br/><span>5. Retry / buffering</span><br/><span>6. Observability reporting</span><br/><br/><span>---</span><br/><br/><span># Event Hierarchy</span><br/><br/><span>Logs follow the structure:</span><br/></div></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

swarm
└ service
└ container
└ logs

<pre class="overflow-visible! px-0!" data-start="2101" data-end="2471"><div class="relative w-full mt-4 mb-1"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border border-token-border-light border-radius-3xl corner-superellipse/1.1 rounded-3xl"><div class="h-full w-full border-radius-3xl bg-token-bg-elevated-secondary corner-superellipse/1.1 overflow-clip rounded-3xl lxnfua_clipPathFallback"><div class="pointer-events-none absolute end-1.5 top-1 z-2 md:end-2 md:top-1"></div><div class="pt-3"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼd ͼr"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><br/><span>Each log entry contains metadata identifying its source.</span><br/><br/><span>---</span><br/><br/><span># Event Payload Example</span><br/><br/><span>```json</span><br/><span>{</span><br/><span>  &#34;event&#34;: &#34;container.log&#34;,</span><br/><span>  &#34;timestamp&#34;: &#34;2026-03-09T12:44:22Z&#34;,</span><br/><span>  &#34;swarm&#34;: &#34;cluster-1&#34;,</span><br/><span>  &#34;service&#34;: &#34;nginx&#34;,</span><br/><span>  &#34;container&#34;: &#34;nginx.1.a8db&#34;,</span><br/><span>  &#34;channel&#34;: &#34;stdout&#34;,</span><br/><span>  &#34;message&#34;: &#34;GET /index.html 200&#34;,</span><br/><span>  &#34;raw&#34;: &#34;10.1.1.12 - - [09/Mar/2026] GET /index.html 200&#34;</span><br/><span>}</span></div></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

---

# Security Model

The collector has access to the Docker socket which provides powerful control over the host system.

Because of this:

• Docker operations must remain limited to log discovery and reading
• Docker actions must never be exposed through HTTP endpoints
• No user input may directly influence Docker commands

---

# Observability

The collector must expose visibility for:

• docker connectivity
• upstream websocket connectivity
• services being monitored
• containers being monitored
• log throughput
• dropped messages
• retry attempts

---

# Running the Collector

Example container run:

<pre class="overflow-visible! px-0!" data-start="3107" data-end="3262"><div class="relative w-full mt-4 mb-1"><div class=""><div class="relative"><div class="h-full min-h-0 min-w-0"><div class="h-full min-h-0 min-w-0"><div class="border border-token-border-light border-radius-3xl corner-superellipse/1.1 rounded-3xl"><div class="h-full w-full border-radius-3xl bg-token-bg-elevated-secondary corner-superellipse/1.1 overflow-clip rounded-3xl lxnfua_clipPathFallback"><div class="pointer-events-none absolute end-1.5 top-1 z-2 md:end-2 md:top-1"></div><div class="pt-3"><div class="relative z-0 flex max-w-full"><div id="code-block-viewer" dir="ltr" class="q9tKkq_viewer cm-editor z-10 light:cm-light dark:cm-light flex h-full w-full flex-col items-stretch ͼd ͼr"><div class="cm-scroller"><div class="cm-content q9tKkq_readonly"><span>docker run \</span><br/><span>  -v /var/run/docker.sock:/var/run/docker.sock \</span><br/><span>  -e SERVER_MANAGER_SOCKET=wss://server-manager/ws/logs \</span><br/><span>  host-swarm-logs-collector</span></div></div></div></div></div></div></div></div></div><div class=""><div class=""></div></div></div></div></div></pre>

---

# Development Goals

This service is designed to be:

• lightweight
• resilient to connection failures
• safe with docker socket access
• horizontally scalable
• observable and debuggable

---

# Related Projects

Host Swarm ecosystem:

• server-manager
• deployment orchestrator
• monitoring services
• swarm automation tools

