{{-- CLAUDE.md / AI Agent Briefing page. Intro card explains the workflow to a
     human user, the Download button (header action) ships the file, and the
     preview below shows the rendered markdown with explicit styling so the
     user sees what they're handing to their AI before downloading. --}}
<x-filament-panels::page>
    @php
        $base = rtrim(config('app.url'), '/');
    @endphp

    {{-- Inline styles for the rendered markdown preview. We can't rely on the
         Tailwind Typography plugin inside Filament, so we style each Markdown
         element explicitly. Pill / code-block classes are reused from the
         visual language of the API Documentation page. --}}
    <style>
        .agent-md {
            font-size: 0.9rem;
            line-height: 1.65;
            color: rgb(55 65 81);
        }
        .dark .agent-md { color: rgb(209 213 219); }

        .agent-md h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 1rem;
            color: rgb(17 24 39);
            border-bottom: 2px solid rgb(229 231 235);
            padding-bottom: 0.5rem;
        }
        .dark .agent-md h1 { color: rgb(243 244 246); border-color: rgb(55 65 81); }

        .agent-md h2 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-top: 2rem;
            margin-bottom: 0.75rem;
            color: rgb(17 24 39);
            border-bottom: 1px solid rgb(229 231 235);
            padding-bottom: 0.35rem;
        }
        .dark .agent-md h2 { color: rgb(243 244 246); border-color: rgb(55 65 81); }

        .agent-md h3 {
            font-size: 1.02rem;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
            color: rgb(31 41 55);
        }
        .dark .agent-md h3 { color: rgb(243 244 246); }

        .agent-md p { margin-bottom: 0.85rem; }

        .agent-md ul, .agent-md ol {
            margin-bottom: 0.85rem;
            padding-left: 1.4rem;
        }
        .agent-md ul { list-style: disc; }
        .agent-md ol { list-style: decimal; }
        .agent-md li { margin-bottom: 0.3rem; }

        .agent-md strong { color: rgb(17 24 39); font-weight: 600; }
        .dark .agent-md strong { color: rgb(243 244 246); }

        .agent-md a { color: rgb(37 99 235); text-decoration: underline; text-underline-offset: 2px; }
        .dark .agent-md a { color: rgb(96 165 250); }

        .agent-md code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.82em;
            background: rgb(243 244 246);
            color: rgb(31 41 55);
            padding: 0.1rem 0.4rem;
            border-radius: 0.3rem;
            border: 1px solid rgb(229 231 235);
        }
        .dark .agent-md code {
            background: rgb(31 41 55);
            color: rgb(243 244 246);
            border-color: rgb(55 65 81);
        }

        .agent-md pre {
            background: rgb(17 24 39);
            color: rgb(243 244 246);
            border-radius: 0.5rem;
            padding: 0.9rem 1.1rem;
            font-size: 0.78rem;
            line-height: 1.55;
            overflow-x: auto;
            margin: 0.85rem 0;
            border: 1px solid rgb(31 41 55);
        }
        .dark .agent-md pre { background: rgb(3 7 18); }
        .agent-md pre code {
            background: transparent;
            border: none;
            color: inherit;
            padding: 0;
            font-size: inherit;
        }

        .agent-md table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.85rem 0;
            font-size: 0.85rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .dark .agent-md table { border-color: rgb(55 65 81); }
        .agent-md th {
            background: rgb(249 250 251);
            text-align: left;
            font-weight: 600;
            padding: 0.6rem 0.85rem;
            border-bottom: 1px solid rgb(229 231 235);
            color: rgb(17 24 39);
        }
        .dark .agent-md th {
            background: rgb(17 24 39);
            border-bottom-color: rgb(55 65 81);
            color: rgb(243 244 246);
        }
        .agent-md td {
            padding: 0.55rem 0.85rem;
            border-bottom: 1px solid rgb(243 244 246);
        }
        .dark .agent-md td { border-bottom-color: rgb(31 41 55); }
        .agent-md tr:last-child td { border-bottom: 0; }

        .agent-md blockquote {
            border-left: 3px solid rgb(99 102 241);
            background: rgb(238 242 255);
            padding: 0.6rem 1rem;
            margin: 0.85rem 0;
            border-radius: 0 0.5rem 0.5rem 0;
            font-style: italic;
            color: rgb(55 65 81);
        }
        .dark .agent-md blockquote {
            background: rgb(30 27 75 / 0.4);
            border-left-color: rgb(129 140 248);
            color: rgb(199 210 254);
        }
        .agent-md blockquote p:last-child { margin-bottom: 0; }

        .agent-md hr {
            border: 0;
            border-top: 1px solid rgb(229 231 235);
            margin: 1.5rem 0;
        }
        .dark .agent-md hr { border-top-color: rgb(55 65 81); }

        .agent-md em { font-style: italic; color: inherit; }
    </style>

    {{-- ===== Intro card ================================================ --}}
    <x-filament::section icon="heroicon-o-cpu-chip">
        <x-slot name="heading">What is this?</x-slot>

        <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
            <p>
                <strong>CLAUDE.md</strong> is a self-contained briefing for an AI agent
                (Claude, GPT, Gemini, or any tool-using assistant). It teaches the agent
                <em>everything</em> it needs to operate your Cortex API on your behalf —
                endpoints, costs, error handling, when to use it, when to walk away.
            </p>
            <p class="text-gray-700 dark:text-gray-300">
                <strong>How to use it:</strong>
            </p>
            <ol class="list-decimal pl-6 space-y-1.5">
                <li>
                    Generate an API token at
                    <a href="/user/api-tokens" class="text-primary-600 dark:text-primary-400 underline underline-offset-2">API Tokens</a>
                    — copy it once, it's hashed after.
                </li>
                <li>
                    Click <strong>Download CLAUDE.md</strong> above (top-right) and save the file.
                </li>
                <li>
                    Hand the file <em>and</em> your token to your AI agent. The agent reads it
                    once, then knows how to call the API correctly and cost-consciously.
                </li>
            </ol>
            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
                <p class="text-sm text-amber-900 dark:text-amber-200">
                    <strong>Security note.</strong> Treat your API token like a password.
                    Anyone with it can spend your wallet. Issue a separate token per agent
                    so you can revoke individually if one leaks.
                </p>
            </div>
        </div>
    </x-filament::section>

    {{-- ===== File metadata + quick-copy URL ============================= --}}
    <x-filament::section icon="heroicon-o-document">
        <x-slot name="heading">File details</x-slot>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3 text-sm">
            <div>
                <dt class="text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mb-1">Filename</dt>
                <dd class="font-mono text-gray-900 dark:text-gray-100">CLAUDE.md</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mb-1">Encoding</dt>
                <dd class="font-mono text-gray-900 dark:text-gray-100">UTF-8 · Markdown</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mb-1">Base URL embedded</dt>
                <dd class="font-mono text-gray-900 dark:text-gray-100 break-all">{{ $base }}/api/v1</dd>
            </div>
        </dl>
    </x-filament::section>

    {{-- ===== Formatted preview ========================================= --}}
    <x-filament::section icon="heroicon-o-eye">
        <x-slot name="heading">Preview</x-slot>
        <x-slot name="description">What your AI agent will read after you hand them this file.</x-slot>

        <div class="agent-md">
            {!! $this->getRenderedHtml() !!}
        </div>
    </x-filament::section>
</x-filament-panels::page>
