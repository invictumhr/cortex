{{-- REST API self-serve documentation for /user.
     Styling uses ONLY explicit Tailwind utility classes (no `prose` dependency)
     so it renders consistently inside Filament without the Typography plugin.
     Base URL + scope list come from the Page so examples stay in sync if
     scopes are added/renamed in App\Models\ApiToken. --}}
<x-filament-panels::page>
    @php
        $base = $this->getBaseUrl();
        $scopes = $this->getScopes();

        // Per-endpoint metadata used by the section partial below. Keeping it
        // in PHP rather than inlining lets us iterate visually-consistent
        // headers without repeating six lines of Tailwind per endpoint.
        $endpoints = [
            [
                'method' => 'POST',
                'path'   => '/api/v1/discuss',
                'title'  => 'Start a new boardroom',
                'scope'  => 'cortex:discuss',
                'icon'   => 'heroicon-o-play-circle',
                'desc'   => 'Kick off a new discussion. Returns 202 immediately — the boardroom runs asynchronously. Poll GET /chats/{id} until status flips to "paused".',
                'lead'   => 'The boardroom can be composed three ways (mutually exclusive — pick one, or omit all for <code class="mono-inline">agents: 5</code> default):',
                'bullets' => [
                    '<strong>Quick</strong> — <code class="mono-inline">agents: N</code> — system picks N models + personas based on topic complexity',
                    '<strong>Custom models</strong> — <code class="mono-inline">models: ["gpt-4o", ...]</code> — you pick exact model strings; system picks personas',
                    '<strong>Custom</strong> — <code class="mono-inline">panel: [{persona, model}, ...]</code> — you pick everything; same persona can repeat with different models',
                    'If <code class="mono-inline">title</code> is omitted, an AI-generated title is created automatically (prefixed with <code class="mono-inline">API:</code>)',
                ],
                'req' => <<<JSON
                {
                  "topic": "Should we ship SSR or hydrate on first paint?",
                  "agents": 5,
                  "rounds": 2,
                  "language": "en",
                  "context": "Optional background, max 50000 chars",
                  "constraints": "Must support older Safari"
                }
                JSON,
                'res' => <<<JSON
                {
                  "ok": true,
                  "chat_id": "a3f7c2e9...64-char-sha256-hash...",
                  "status": "active",
                  "title": "API: SSR vs Hydration Trade-offs",
                  "rounds": 2,
                  "poll_url": "/api/v1/chats/a3f7c2e9..."
                }
                JSON,
            ],
            [
                'method' => 'GET',
                'path'   => '/api/v1/chats',
                'title'  => 'List your chats',
                'scope'  => 'cortex:chats.read',
                'icon'   => 'heroicon-o-list-bullet',
                'desc'   => 'Newest-first list of your chats.',
                'lead'   => 'Query parameters:',
                'bullets' => [
                    '<code class="mono-inline">limit</code> — page size (max 100, default 50)',
                    '<code class="mono-inline">before</code> — ISO-8601 timestamp cursor; pass <code class="mono-inline">next_before</code> from the previous response',
                    '<code class="mono-inline">include_archived=1</code> — show archived chats too (hidden by default)',
                ],
                'curl' => "curl \"{$base}/api/v1/chats?limit=10\" \\\n  -H \"Authorization: Bearer ctx_...\"",
            ],
            [
                'method' => 'GET',
                'path'   => '/api/v1/chats/{id}',
                'title'  => 'Chat detail + poll for completion',
                'scope'  => 'cortex:chats.read',
                'icon'   => 'heroicon-o-eye',
                'desc'   => 'Full chat with personas, messages, and scribe summaries. Use this to poll after POST /discuss or /messages — when chat.status changes from "active" to "paused", the turn is done.',
                'lead'   => 'Pass <code class="mono-inline">messages_after=ID</code> to fetch only newer messages (incremental polling).',
                'curl' => "curl \"{$base}/api/v1/chats/<sha256-hash>?messages_after=100\" \\\n  -H \"Authorization: Bearer ctx_...\"",
            ],
            [
                'method' => 'POST',
                'path'   => '/api/v1/chats/{id}/messages',
                'title'  => 'Follow-up turn',
                'scope'  => 'cortex:chats.write',
                'icon'   => 'heroicon-o-chat-bubble-left-right',
                'desc'   => 'Drop a new user message into an existing chat. Returns 202 immediately — the boardroom runs asynchronously. Poll GET /chats/{id} for results.',
                'lead'   => 'Optional <code class="mono-inline">language</code> (ISO 639-1) re-aligns the chat\'s output language going forward.',
                'req' => <<<JSON
                {
                  "content": "What's the biggest risk if we ship Monday?",
                  "language": "en",
                  "rounds": 1
                }
                JSON,
                'res' => <<<JSON
                {
                  "ok": true,
                  "chat_id": "a3f7c2e9...sha256...",
                  "status": "active",
                  "user_message_id": 188,
                  "poll_url": "/api/v1/chats/a3f7c2e9..."
                }
                JSON,
            ],
            [
                'method' => 'POST',
                'path'   => '/api/v1/chats/{id}/archive',
                'title'  => 'Soft archive',
                'scope'  => 'cortex:chats.write',
                'icon'   => 'heroicon-o-archive-box',
                'desc'   => 'Hide the chat from default listings without deleting anything.',
                'lead'   => 'Re-list with <code class="mono-inline">include_archived=1</code> on <code class="mono-inline">GET /chats</code>.',
                'curl' => "curl -X POST {$base}/api/v1/chats/<chat-id>/archive \\\n  -H \"Authorization: Bearer ctx_...\"",
            ],
            [
                'method' => 'DELETE',
                'path'   => '/api/v1/chats/{id}',
                'title'  => 'Hard delete',
                'scope'  => 'cortex:chats.write',
                'icon'   => 'heroicon-o-trash',
                'desc'   => 'Permanently delete the chat and everything attached (messages, summaries, attachments, persona pivots).',
                'lead'   => '<strong class="text-rose-600 dark:text-rose-400">Not reversible.</strong>',
                'curl' => "curl -X DELETE {$base}/api/v1/chats/<chat-id> \\\n  -H \"Authorization: Bearer ctx_...\"",
            ],
            [
                'method' => 'GET',
                'path'   => '/api/v1/wallet',
                'title'  => 'Balance snapshot',
                'scope'  => 'cortex:wallet.read',
                'icon'   => 'heroicon-o-banknotes',
                'desc'   => 'Current balance, reserved euros (held for in-flight runs), 30-day spend, and which margin tier you\'re on.',
                'curl' => "curl {$base}/api/v1/wallet \\\n  -H \"Authorization: Bearer ctx_...\"",
                'res' => <<<JSON
                {
                  "balance": 114.110398,
                  "reserved": 0,
                  "available": 114.110398,
                  "currency": "EUR",
                  "spend_30d": 4.484888,
                  "margin_multiplier": 2,
                  "tier": "hobby",
                  "low_warning_threshold": 0.5,
                  "min_send_balance": 0.05,
                  "topup_url": "{$base}/user/redeem-code"
                }
                JSON,
            ],
            [
                'method' => 'GET',
                'path'   => '/api/v1/wallet/transactions',
                'title'  => 'Wallet ledger',
                'scope'  => 'cortex:wallet.read',
                'icon'   => 'heroicon-o-document-text',
                'desc'   => 'Paginated append-only ledger of all wallet activity.',
                'lead'   => 'Query parameters:',
                'bullets' => [
                    '<code class="mono-inline">limit</code> — page size (max 200, default 50)',
                    '<code class="mono-inline">before</code> — ISO-8601 cursor for pagination',
                    '<code class="mono-inline">type</code> — filter to one of <code class="mono-inline">DEPOSIT, RESERVE, RELEASE, DEBIT, REFUND, GRANT, ADJUSTMENT</code>',
                ],
                'curl' => "curl \"{$base}/api/v1/wallet/transactions?type=DEBIT&limit=20\" \\\n  -H \"Authorization: Bearer ctx_...\"",
            ],
        ];

        // Pill color classes are defined in the inline <style> block below.
        // We use our own CSS instead of dynamic Tailwind utilities because
        // Filament's compiled CSS doesn't always include arbitrary `bg-*-950`
        // dark variants, and those that are included blend into Filament's
        // dark surface (no contrast).
        $methodColors = [
            'GET'    => 'pill-blue',
            'POST'   => 'pill-green',
            'DELETE' => 'pill-red',
        ];

        $scopeColors = [
            'cortex:discuss'      => 'pill-green',
            'cortex:chats.read'   => 'pill-blue',
            'cortex:chats.write'  => 'pill-amber',
            'cortex:wallet.read'  => 'pill-violet',
            'cortex:knowledge'    => 'pill-gray',
        ];
    @endphp

    {{-- Inline component styles. Keeping them here (not in app.css) because
         this is the only Filament page that needs proper code-block styling
         and we don't want to pollute the global stylesheet. --}}
    <style>
        .cortex-doc-code {
            background: rgb(17 24 39);
            color: rgb(243 244 246);
            border-radius: 0.5rem;
            padding: 0.875rem 1rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.78rem;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre;
            border: 1px solid rgb(31 41 55);
        }
        .dark .cortex-doc-code {
            background: rgb(3 7 18);
            border-color: rgb(31 41 55);
        }
        .cortex-doc-code .cmt { color: rgb(156 163 175); font-style: italic; }
        .cortex-doc-code .str { color: rgb(134 239 172); }
        .cortex-doc-code .num { color: rgb(251 191 36); }
        .cortex-doc-code .key { color: rgb(125 211 252); }
        .cortex-doc-code .punc { color: rgb(209 213 219); }
        .cortex-doc-code .flag { color: rgb(167 139 250); }
        .mono-inline {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85em;
            padding: 0.1rem 0.35rem;
            border-radius: 0.25rem;
            background: rgb(243 244 246);
            color: rgb(17 24 39);
            border: 1px solid rgb(229 231 235);
        }
        .dark .mono-inline {
            background: rgb(31 41 55);
            color: rgb(243 244 246);
            border-color: rgb(55 65 81);
        }
        .cortex-doc-list { list-style: disc; padding-left: 1.25rem; }
        .cortex-doc-list > li { margin-bottom: 0.35rem; line-height: 1.55; }
        .cortex-doc-list > li:last-child { margin-bottom: 0; }
        .cortex-method-pill {
            display: inline-flex;
            align-items: center;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 600;
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            padding: 0.22rem 0.6rem;
            border-radius: 0.375rem;
            border: 1px solid transparent;
            line-height: 1.1;
        }

        /* Named pill colors — defined here instead of relying on Tailwind
           utilities because the dynamic `dark:bg-*-950` variants may not be
           in Filament's compiled CSS bundle. These rules ALWAYS apply and
           always look the same regardless of Tailwind purge state. */
        .pill-green  { background: rgb(220 252 231); color: rgb(22 101 52); border-color: rgb(187 247 208); }
        .pill-blue   { background: rgb(224 242 254); color: rgb(7 89 133);   border-color: rgb(186 230 253); }
        .pill-red    { background: rgb(254 226 226); color: rgb(153 27 27);  border-color: rgb(254 202 202); }
        .pill-amber  { background: rgb(254 243 199); color: rgb(146 64 14);  border-color: rgb(253 230 138); }
        .pill-violet { background: rgb(237 233 254); color: rgb(76 29 149);  border-color: rgb(221 214 254); }
        .pill-gray   { background: rgb(243 244 246); color: rgb(31 41 55);   border-color: rgb(229 231 235); }

        .dark .pill-green  { background: rgba(16 185 129 / 0.18); color: rgb(110 231 183); border-color: rgba(16 185 129 / 0.35); }
        .dark .pill-blue   { background: rgba(56 189 248 / 0.18); color: rgb(125 211 252); border-color: rgba(56 189 248 / 0.35); }
        .dark .pill-red    { background: rgba(244 63 94 / 0.18);  color: rgb(252 165 165); border-color: rgba(244 63 94 / 0.35); }
        .dark .pill-amber  { background: rgba(245 158 11 / 0.18); color: rgb(252 211 77);  border-color: rgba(245 158 11 / 0.35); }
        .dark .pill-violet { background: rgba(167 139 250 / 0.18); color: rgb(196 181 253); border-color: rgba(167 139 250 / 0.35); }
        .dark .pill-gray   { background: rgba(156 163 175 / 0.15); color: rgb(209 213 219); border-color: rgba(156 163 175 / 0.3); }
        .cortex-section-label {
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgb(107 114 128);
            margin-bottom: 0.4rem;
            display: block;
        }
        .dark .cortex-section-label { color: rgb(156 163 175); }

        /* Tables — explicit border + zebra so dividers stay visible across
           Filament's light/dark themes without relying on Tailwind utilities
           that may or may not be in the compiled bundle. */
        .cortex-table-wrap {
            overflow-x: auto;
            border-radius: 0.5rem;
            border: 1px solid rgb(229 231 235);
        }
        .dark .cortex-table-wrap { border-color: rgb(55 65 81); }
        .cortex-table {
            min-width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .cortex-th {
            background: rgb(249 250 251);
            text-align: left;
            font-weight: 600;
            color: rgb(31 41 55);
            padding: 0.7rem 1rem;
            border-bottom: 1px solid rgb(229 231 235);
        }
        .dark .cortex-th {
            background: rgba(17 24 39 / 0.6);
            color: rgb(243 244 246);
            border-bottom-color: rgb(55 65 81);
        }
        .cortex-td {
            padding: 0.65rem 1rem;
            color: rgb(55 65 81);
            border-bottom: 1px solid rgb(243 244 246);
            vertical-align: middle;
        }
        .dark .cortex-td {
            color: rgb(209 213 219);
            border-bottom-color: rgba(55 65 81 / 0.6);
        }
        .cortex-table tbody tr:last-child .cortex-td { border-bottom: 0; }
        .cortex-table tbody tr:hover .cortex-td {
            background: rgba(243 244 246 / 0.7);
        }
        .dark .cortex-table tbody tr:hover .cortex-td {
            background: rgba(31 41 55 / 0.35);
        }
        .cortex-td-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.78rem;
            color: rgb(75 85 99);
        }
        .dark .cortex-td-mono { color: rgb(156 163 175); }
    </style>

    {{-- ===== Intro ===================================================== --}}
    <x-filament::section icon="heroicon-o-rocket-launch">
        <x-slot name="heading">Getting started</x-slot>

        <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
            <p>
                The Cortex REST API lets you start AI boardroom discussions, fetch results,
                and read your wallet balance from outside the web UI — useful for n8n flows,
                CI pipelines, Zapier, or your own integrations.
            </p>
            <ol class="cortex-doc-list" style="list-style: decimal;">
                <li>Issue a token under <a href="/user/api-tokens" class="text-primary-600 dark:text-primary-400 underline underline-offset-2">API Tokens</a>. Copy it once — it's hashed after that.</li>
                <li>Send it as <code class="mono-inline">Authorization: Bearer ctx_...</code> on every request.</li>
                <li>Every call is billed off your wallet (same prices as the web UI) and logged so you can audit per-token spend on the dashboard.</li>
            </ol>
            <div class="flex items-center gap-2 pt-2">
                <span class="cortex-section-label" style="margin-bottom: 0;">Base URL</span>
                <code class="mono-inline">{{ $base }}/api/v1</code>
            </div>
        </div>
    </x-filament::section>

    {{-- ===== Authentication ============================================ --}}
    <x-filament::section icon="heroicon-o-key">
        <x-slot name="heading">Authentication</x-slot>

        <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
            Every request must carry a Bearer token in the <code class="mono-inline">Authorization</code> header:
        </p>
        <pre class="cortex-doc-code"><span class="flag">curl</span> {{ $base }}/api/v1/wallet \
  <span class="flag">-H</span> <span class="str">"Authorization: Bearer ctx_your_token_here"</span> \
  <span class="flag">-H</span> <span class="str">"Accept: application/json"</span></pre>
        <p class="text-sm text-gray-700 dark:text-gray-300 mt-3">
            Tokens are bound to your user account. Revoke any token at any time from the API Tokens page —
            existing chats it created stay accessible to you, but the token stops working immediately.
        </p>
    </x-filament::section>

    {{-- ===== Scopes ==================================================== --}}
    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Scopes</x-slot>

        <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">
            Each token can be restricted to a subset of scopes. A token with <strong>no scopes set</strong>
            is granted full access — convenient for personal scripts, dangerous if leaked.
            For integrations, prefer least-privilege.
        </p>

        <div class="cortex-table-wrap">
            <table class="cortex-table">
                <thead>
                    <tr>
                        <th class="cortex-th" style="width: 30%;">Scope</th>
                        <th class="cortex-th">Allows</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scopes as $scope => $label)
                        <tr>
                            <td class="cortex-td">
                                <span class="cortex-method-pill {{ $scopeColors[$scope] ?? 'pill-gray' }}">
                                    {{ $scope }}
                                </span>
                            </td>
                            <td class="cortex-td">{{ $label }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ===== Rate limits =============================================== --}}
    <x-filament::section icon="heroicon-o-clock">
        <x-slot name="heading">Rate limits</x-slot>

        <ul class="cortex-doc-list text-sm text-gray-700 dark:text-gray-300 mb-3">
            <li><strong>{{ config('cortex.api_rate_limit.per_minute', 10) }}</strong> requests per token per minute</li>
            <li><strong>{{ config('cortex.api_rate_limit.per_hour', 50) }}</strong> requests per token per hour</li>
        </ul>
        <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
            Exceeding a limit returns <code class="mono-inline">429</code> with <code class="mono-inline">retry_after_seconds</code>
            in the body and a <code class="mono-inline">Retry-After</code> header.
        </p>
        <pre class="cortex-doc-code"><span class="punc">{</span>
  <span class="key">"error"</span><span class="punc">:</span> <span class="str">"rate_limited"</span><span class="punc">,</span>
  <span class="key">"window"</span><span class="punc">:</span> <span class="str">"hour"</span><span class="punc">,</span>
  <span class="key">"cap"</span><span class="punc">:</span> <span class="num">10</span><span class="punc">,</span>
  <span class="key">"retry_after_seconds"</span><span class="punc">:</span> <span class="num">1842</span>
<span class="punc">}</span></pre>
    </x-filament::section>

    {{-- ===== Available Models ============================================ --}}
    @php
        $aiModels = $this->getModels();
        $grouped = $aiModels->groupBy(fn ($m) => $m->provider->name ?? 'Unknown');

        $providerPills = [
            'Anthropic' => 'pill-violet',
            'OpenAI'    => 'pill-green',
            'xAI'       => 'pill-blue',
            'Google'    => 'pill-blue',
            'Mistral'   => 'pill-amber',
            'DeepSeek'  => 'pill-green',
        ];

        $formatCtx = function (int $tokens): string {
            return $tokens >= 1000000 ? (int) ($tokens / 1000000) . 'M' : (int) ($tokens / 1000) . 'k';
        };
    @endphp

    <x-filament::section icon="heroicon-o-cpu-chip">
        <x-slot name="heading">Available models</x-slot>

        <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">
            Pass any <code class="mono-inline">model_string</code> below in the
            <code class="mono-inline">models</code> array of <code class="mono-inline">POST /discuss</code>
            to pick the exact AI engine for each boardroom seat. If omitted, the
            system auto-selects based on topic complexity.
        </p>

        <div class="cortex-table-wrap">
            <table class="cortex-table">
                <thead>
                    <tr>
                        <th class="cortex-th">Provider</th>
                        <th class="cortex-th">Model</th>
                        <th class="cortex-th" style="white-space: nowrap;">Model ID</th>
                        <th class="cortex-th" style="text-align: right;">Context</th>
                        <th class="cortex-th" style="text-align: center;">Vision</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grouped as $providerName => $models)
                        @foreach ($models as $i => $m)
                            <tr>
                                @if ($i === 0)
                                    <td class="cortex-td" rowspan="{{ $models->count() }}" style="vertical-align: top; padding-top: 0.85rem;">
                                        <span class="cortex-method-pill {{ $providerPills[$providerName] ?? 'pill-gray' }}">{{ $providerName }}</span>
                                    </td>
                                @endif
                                <td class="cortex-td" style="font-weight: 500;">{{ $m->name }}</td>
                                <td class="cortex-td cortex-td-mono">{{ $m->model_string }}</td>
                                <td class="cortex-td" style="text-align: right; white-space: nowrap;">{{ $formatCtx($m->max_context_tokens) }}</td>
                                <td class="cortex-td" style="text-align: center;">
                                    @if ($m->supports_vision)
                                        <span style="color: rgb(34 197 94);">&#10003;</span>
                                    @else
                                        <span style="color: rgb(156 163 175);">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
            Your wallet is debited in EUR at provider cost &times; your margin tier ({{ config('cortex.billing.margin_hobby') }}&times; hobby / {{ config('cortex.billing.margin_enterprise') }}&times; enterprise).
        </p>
    </x-filament::section>

    {{-- ===== Endpoint sections ========================================= --}}
    @foreach ($endpoints as $ep)
        <x-filament::section :icon="$ep['icon']">
            <x-slot name="heading">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="cortex-method-pill {{ $methodColors[$ep['method']] }}">{{ $ep['method'] }}</span>
                    <code class="font-mono text-sm text-gray-900 dark:text-gray-100">{{ $ep['path'] }}</code>
                    <span class="text-gray-400 dark:text-gray-600">·</span>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $ep['title'] }}</span>
                </div>
            </x-slot>

            {{-- Required scope badge --}}
            <div class="mb-3">
                <span class="cortex-section-label">Required scope</span>
                <span class="cortex-method-pill {{ $scopeColors[$ep['scope']] ?? 'bg-gray-100 text-gray-800' }}">{{ $ep['scope'] }}</span>
            </div>

            {{-- Description + optional lead paragraph + optional bullet list --}}
            <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">{{ $ep['desc'] }}</p>
            @if (! empty($ep['lead']))
                <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">{!! $ep['lead'] !!}</p>
            @endif
            @if (! empty($ep['bullets']))
                <ul class="cortex-doc-list text-sm text-gray-700 dark:text-gray-300 mb-3">
                    @foreach ($ep['bullets'] as $bullet)
                        <li>{!! $bullet !!}</li>
                    @endforeach
                </ul>
            @endif

            {{-- Request example: either a JSON body for POSTs, or a plain curl for GETs --}}
            @if (! empty($ep['req']))
                <span class="cortex-section-label mt-3">Request body</span>
                <pre class="cortex-doc-code">{!! e(trim($ep['req'])) !!}</pre>
                <span class="cortex-section-label mt-4">curl</span>
                <pre class="cortex-doc-code"><span class="flag">curl</span> {{ $base }}{{ $ep['path'] }} \
  <span class="flag">-H</span> <span class="str">"Authorization: Bearer ctx_..."</span> \
  <span class="flag">-H</span> <span class="str">"Content-Type: application/json"</span> \
  <span class="flag">-d</span> <span class="str">@</span>request.json</pre>
            @elseif (! empty($ep['curl']))
                <span class="cortex-section-label mt-3">curl</span>
                <pre class="cortex-doc-code">{!! e(trim($ep['curl'])) !!}</pre>
            @endif

            @if (! empty($ep['res']))
                <span class="cortex-section-label mt-4">Response</span>
                <pre class="cortex-doc-code">{!! e(trim($ep['res'])) !!}</pre>
            @endif
        </x-filament::section>
    @endforeach

    {{-- ===== Status codes ============================================== --}}
    <x-filament::section icon="heroicon-o-exclamation-triangle">
        <x-slot name="heading">Status codes</x-slot>

        <div class="cortex-table-wrap">
            <table class="cortex-table">
                <thead>
                    <tr>
                        <th class="cortex-th" style="width: 90px;">Code</th>
                        <th class="cortex-th" style="width: 35%;">Meaning</th>
                        <th class="cortex-th">Response body</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $codes = [
                            ['200', 'pill-green',  'Success',                                '{"ok": true, ...}'],
                            ['401', 'pill-red',    'Missing / invalid / revoked token',      '{"error": "invalid_token"}'],
                            ['402', 'pill-amber',  'Insufficient wallet balance',            '{"error": "insufficient_funds", "available_eur": 0.01}'],
                            ['403', 'pill-red',    'Scope mismatch or cross-user chat',      '{"error": "scope_required", "required": "..."}'],
                            ['404', 'pill-gray',   'Chat does not exist or was deleted',     '{"message": "No query results..."}'],
                            ['422', 'pill-amber',  'Validation error',                       '{"errors": {"content": ["The content field is required."]}}'],
                            ['202', 'pill-green',  'Accepted — discussion running async',    '{"ok": true, "chat_id": "...", "poll_url": "..."}'],
                            ['429', 'pill-amber',  'Rate limit (minute or hour cap)',        '{"error": "rate_limited", "retry_after_seconds": ...}'],
                            ['500', 'pill-red',    'Unexpected server error',                '{"error": "<message>"}'],
                        ];
                    @endphp
                    @foreach ($codes as [$code, $color, $meaning, $body])
                        <tr>
                            <td class="cortex-td"><span class="cortex-method-pill {{ $color }}">{{ $code }}</span></td>
                            <td class="cortex-td">{{ $meaning }}</td>
                            <td class="cortex-td cortex-td-mono">{{ $body }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ===== Best practices ============================================ --}}
    <x-filament::section icon="heroicon-o-light-bulb">
        <x-slot name="heading">Best practices</x-slot>

        <ul class="cortex-doc-list text-sm text-gray-700 dark:text-gray-300 space-y-2">
            <li>
                <strong>Async + poll.</strong>
                POST /discuss and /messages return 202 immediately. Poll <code class="mono-inline">GET /chats/{id}</code> until <code class="mono-inline">status</code> flips from <code class="mono-inline">"active"</code> to <code class="mono-inline">"paused"</code>. Use <code class="mono-inline">messages_after=ID</code> for incremental fetches.
            </li>
            <li>
                <strong>POST /discuss is not idempotent.</strong>
                Retrying creates a new chat and bills again. For <code class="mono-inline">5xx</code> responses, check <code class="mono-inline">GET /chats</code> before retrying.
            </li>
            <li>
                <strong>Mind the cost.</strong>
                Each call burns wallet funds — instrument <code class="mono-inline">turn_user_cost_eur</code> in your client and set budget alerts.
            </li>
            <li>
                <strong>One token per integration.</strong>
                Easier to revoke if one leaks; per-token usage stats stay clean on your dashboard.
            </li>
            <li>
                <strong>Scope every token.</strong>
                A leaked <code class="mono-inline">cortex:wallet.read</code> token can't drain your wallet by starting discussions.
            </li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
