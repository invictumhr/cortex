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
        'anthropic' => 'claude-opus-4-8',
        'openai' => 'gpt-5.5',
        'xai' => 'grok-4.3',
        'google' => 'gemini-2.5-pro',
        'mistral' => 'mistral-medium-latest',
        'deepseek' => 'deepseek-v4-pro',
    ],

    // `--architect`: a model designs a question-specific panel of roles, and
    // those generated roles run (round-robin) on this spread of models.
    'architect_model' => env('CORTEX_ARCHITECT_MODEL', 'claude-sonnet-4-6'),
    'architect_panel_models' => [
        'claude-sonnet-4-6', 'gpt-5.4', 'grok-4.3', 'gemini-3.5-flash', 'mistral-large-latest', 'deepseek-v4-flash',
    ],

    // `cortex:benchmark` — the single-model control and the neutral evaluator.
    'benchmark_control_model' => env('CORTEX_BENCHMARK_CONTROL_MODEL', 'claude-opus-4-7'),
    'benchmark_evaluator_model' => env('CORTEX_BENCHMARK_EVALUATOR_MODEL', 'claude-sonnet-4-6'),

    // Model tiers — BoardroomComposer picks from a tier based on the first
    // message's complexity. "Small" is for quick/casual prompts, "medium"
    // is the default, "flagship" matches what --strong / --strong tier
    // would have used. Unknown models fall through (e.g. an admin can add a
    // new model without touching this list; it just won't be auto-picked).
    'model_tiers' => [
        'small' => [
            'gpt-5.4-nano',
            'gpt-5.4-mini',
            'claude-haiku-4-5-20251001',
            'gemini-2.5-flash-lite',
            'gemini-2.5-flash',
            'mistral-small-latest',
            'deepseek-v4-flash',
        ],
        'medium' => [
            'gpt-5.4',
            'claude-sonnet-4-6',
            'grok-4.3',
            'mistral-large-latest',
            'gemini-3.5-flash',
            'deepseek-v4-flash',
        ],
        'flagship' => [
            'gpt-5.5',
            'o3',
            'claude-opus-4-8',
            'gemini-2.5-pro',
            'grok-4.3',
            'mistral-medium-latest',
        ],
    ],

    // Default number of messages between scribe summaries.
    'default_scribe_interval' => (int) env('CORTEX_DEFAULT_SCRIBE_INTERVAL', 50),

    // The scribe also produces an interim summary every N rounds; a guaranteed
    // cumulative final summary always runs when a discussion ends.
    'scribe_round_interval' => (int) env('CORTEX_SCRIBE_ROUND_INTERVAL', 2),

    // For continuous (web) chats only: every N rounds the loop auto-pauses and
    // forces a final scribe synthesis + Chair verdict. The user clicks Resume
    // to continue from the next round. Set to 0 to disable (loop runs forever
    // until user pauses). Default 2 — same cadence as scribe_round_interval
    // so the user sees a checkpoint roughly every couple of minutes.
    'continuous_checkpoint_round_interval' => (int) env('CORTEX_CONTINUOUS_CHECKPOINT_ROUNDS', 2),

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
    | Billing (multi-user SaaS)
    |--------------------------------------------------------------------------
    | All amounts in EUR. The wallet ledger is the source of truth — these
    | values control how new rows get priced and reserved.
    */

    'billing' => [
        // Margin multiplier applied to provider_cost when debiting the user.
        // Tier-based: enterprise kicks in for users who've spent more than
        // `enterprise_threshold_eur` in the trailing 30 days.
        'margin_hobby' => (float) env('CORTEX_MARGIN_HOBBY', 2.0),
        'margin_enterprise' => (float) env('CORTEX_MARGIN_ENTERPRISE', 1.7),
        'enterprise_threshold_eur' => (float) env('CORTEX_ENTERPRISE_THRESHOLD', 100.0),

        // Free credit policy (set once per user on signup).
        // Option B from the design: small immediate bonus + larger one after
        // a real first deposit, so abuse is bounded but trial isn't gated.
        'free_credit_signup' => (float) env('CORTEX_FREE_CREDIT_SIGNUP', 0.50),
        'free_credit_first_deposit' => (float) env('CORTEX_FREE_CREDIT_FIRST_DEPOSIT', 1.00),
        'first_deposit_threshold' => (float) env('CORTEX_FIRST_DEPOSIT_THRESHOLD', 5.0),

        // Minimum top-up — Paddle's fee floor makes amounts below this unprofitable.
        'min_topup_eur' => (float) env('CORTEX_MIN_TOPUP', 5.0),

        // Pre-flight estimate uses p90 historical output tokens — fallback for
        // a fresh install with no history.
        'estimate_fallback_output_tokens' => (int) env('CORTEX_ESTIMATE_FALLBACK_OUTPUT_TOKENS', 800),

        // Snapshot pricing TTL. Cortex absorbs any provider price change inside
        // this window so a user doesn't get "insufficient funds" mid-run.
        // Shorter than the Cortex panel's 24h to reduce margin drift exposure.
        'rate_snapshot_ttl_hours' => (int) env('CORTEX_RATE_SNAPSHOT_TTL_HOURS', 6),

        // Poison pill — if actual cost overshoots reserved cost by this factor,
        // the debit still goes through but an admin alert fires. Doesn't pause
        // the user's discussion (poor UX), just surfaces the drift to ops.
        'poison_pill_ratio' => (float) env('CORTEX_POISON_PILL_RATIO', 1.3),

        // Minimum available balance required to even attempt sending a new
        // message. Bigger than a single token but well below a real persona
        // call — catches the "wallet is empty" case fast and returns a 402.
        'min_send_balance' => (float) env('CORTEX_MIN_SEND_BALANCE', 0.05),

        // UI low-balance warning threshold — banner appears in the chat
        // header when available balance drops below this. Doesn't block.
        'low_balance_warning' => (float) env('CORTEX_LOW_BALANCE_WARNING', 0.50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paddle payment integration (merchant of record for EU VAT)
    |--------------------------------------------------------------------------
    | Set these in .env once you have a Paddle vendor account:
    |   PADDLE_VENDOR_ID=...
    |   PADDLE_WEBHOOK_SECRET=...     (used to verify webhook signatures)
    |   PADDLE_PRICE_TOPUP_5=pri_…    (one Paddle "Price" object per top-up tier)
    |   PADDLE_PRICE_TOPUP_10=pri_…
    |   PADDLE_PRICE_TOPUP_25=pri_…
    |   PADDLE_PRICE_TOPUP_50=pri_…
    */

    'paddle' => [
        'vendor_id' => env('PADDLE_VENDOR_ID'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        // Map Paddle Price IDs to the EUR amount they top up. Keys are
        // arbitrary labels for the UI; values are [price_id, eur].
        'topup_tiers' => [
            '5_eur'  => ['price_id' => env('PADDLE_PRICE_TOPUP_5'),  'amount' => 5.0],
            '10_eur' => ['price_id' => env('PADDLE_PRICE_TOPUP_10'), 'amount' => 10.0],
            '25_eur' => ['price_id' => env('PADDLE_PRICE_TOPUP_25'), 'amount' => 25.0],
            '50_eur' => ['price_id' => env('PADDLE_PRICE_TOPUP_50'), 'amount' => 50.0],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API rate limits (default per token; admin can raise per-token)
    |--------------------------------------------------------------------------
    */

    'api_rate_limit' => [
        'per_minute' => (int) env('CORTEX_API_RATE_PER_MINUTE', 10),
        'per_hour' => (int) env('CORTEX_API_RATE_PER_HOUR', 50),
    ],

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
