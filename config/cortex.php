<?php

return [

    // JSON output contract version exposed to external agents.
    // 1.2 — scribe key_ideas carry contributing_personas; cortex:discuss --json
    // adds a cost_estimate block.
    'api_version' => '1.2',

    /*
    |--------------------------------------------------------------------------
    | Orchestration
    |--------------------------------------------------------------------------
    */

    // How many recent messages are fed to a persona as context.
    'context_message_limit' => (int) env('CORTEX_CONTEXT_MESSAGE_LIMIT', 30),

    // Hard ceiling on rounds per turn (runaway-loop protection).
    'max_rounds' => 200,

    // Allowed round presets surfaced in the UI.
    'round_presets' => [1, 2, 5, 10, 20, 50, 100, 200],

    // Token budgets for a single generation.
    'persona_max_tokens' => (int) env('CORTEX_PERSONA_MAX_TOKENS', 1200),
    'persona_temperature' => (float) env('CORTEX_PERSONA_TEMPERATURE', 0.8),
    'scribe_max_tokens' => (int) env('CORTEX_SCRIBE_MAX_TOKENS', 6000),

    // When a persona's own model fails or returns nothing, retry once on this
    // model so the boardroom keeps the voice instead of dropping it.
    'fallback_model' => env('CORTEX_FALLBACK_MODEL', 'gpt-4o-mini'),

    // Cheap model that classifies a topic and auto-picks the best-fit panel.
    'router_model' => env('CORTEX_ROUTER_MODEL', 'gpt-4o-mini'),

    // `--strong` runs each panel persona on its provider's flagship model.
    'flagship_models' => [
        'anthropic' => 'claude-opus-4-7',
        'openai' => 'o3',
        'xai' => 'grok-3',
        'google' => 'gemini-2.5-pro',
        'mistral' => 'mistral-large-latest',
        'deepseek' => 'deepseek-chat',
    ],

    // `--architect`: a model designs a question-specific panel of roles, and
    // those generated roles run (round-robin) on this spread of models.
    'architect_model' => env('CORTEX_ARCHITECT_MODEL', 'claude-sonnet-4-6'),
    'architect_panel_models' => [
        'claude-sonnet-4-6', 'gpt-4.1', 'grok-3', 'gemini-2.5-flash', 'mistral-large-latest', 'deepseek-chat',
    ],

    // `cortex:benchmark` — the single-model control and the neutral evaluator.
    'benchmark_control_model' => env('CORTEX_BENCHMARK_CONTROL_MODEL', 'claude-opus-4-7'),
    'benchmark_evaluator_model' => env('CORTEX_BENCHMARK_EVALUATOR_MODEL', 'claude-sonnet-4-6'),

    // Default number of messages between scribe summaries.
    'default_scribe_interval' => (int) env('CORTEX_DEFAULT_SCRIBE_INTERVAL', 50),

    // The scribe also produces an interim summary every N rounds; a guaranteed
    // cumulative final summary always runs when a discussion ends.
    'scribe_round_interval' => (int) env('CORTEX_SCRIBE_ROUND_INTERVAL', 2),

    // Personas deliberate in this language (token-efficient); the scribe
    // summarises into the user-facing output language.
    'deliberation_language' => env('CORTEX_DELIBERATION_LANGUAGE', 'English'),
    'output_language' => env('CORTEX_OUTPUT_LANGUAGE', 'English'),

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    // Per-chat cost ceiling in EUR; the chat is paused when exceeded.
    'budget_limit' => (float) env('CORTEX_DEFAULT_BUDGET_LIMIT', 10.00),

    // Daily spend ceiling across all chats; new turns are refused above it.
    'daily_budget_limit' => (float) env('CORTEX_DAILY_BUDGET_LIMIT', 25.00),

    'max_messages_per_minute' => (int) env('CORTEX_MAX_MESSAGES_PER_MINUTE', 60),

    // Max new turns started per minute, platform-wide.
    'max_turns_per_minute' => (int) env('CORTEX_MAX_TURNS_PER_MINUTE', 20),

    // Cache key for the global "stop all AI calls" kill switch.
    'kill_switch_key' => 'cortex:kill_switch',

    /*
    |--------------------------------------------------------------------------
    | PowerShell executor sidecar
    |--------------------------------------------------------------------------
    */

    'powershell' => [
        'url' => env('POWERSHELL_EXECUTOR_URL', 'http://127.0.0.1:7878'),
        'token' => env('POWERSHELL_EXECUTOR_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | URL fetcher
    |--------------------------------------------------------------------------
    */

    'url_fetch' => [
        'timeout' => 20,
        'max_content_length' => 20000,
    ],
];
