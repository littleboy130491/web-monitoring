<?php

return [

    /*
     | AI analysis of monitoring results. Runs only when a decision provider
     | key (JEV_API_KEY or OPENROUTER_API_KEY) is configured.
     */
    'enabled' => (bool) env('AI_ANALYSIS_ENABLED', true),

    /*
     | Queue an analysis automatically after every monitoring check.
     | When false, run it manually (php artisan monitor:analyze, admin panel or MCP).
     */
    'auto_analyze' => (bool) env('AI_AUTO_ANALYZE', true),

    /*
     | Which service answers the decision questions (severity, urgency, ...):
     | "auto" uses TypeSafe directly when JEV_API_KEY is set, otherwise OpenRouter.
     | Allowed: auto, jev, openrouter.
     */
    'decision_provider' => env('AI_DECISION_PROVIDER', 'auto'),

    'jev' => [
        'api_key' => env('JEV_API_KEY'),
        'url' => env('JEV_API_URL', 'https://api.typesafe.ai/v1/systemone'),
        'model' => env('JEV_MODEL', 'jev-latest'),

        // Send screenshots to Jev directly (experimental in Jev 1.13/1.14, needs a
        // model that accepts images, e.g. jev-preview). Used only when no OpenRouter
        // vision model is available; falls back to text-only if Jev rejects it.
        'images' => (bool) env('JEV_IMAGES', false),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api'),

        // Jev served through OpenRouter's decisions API
        'decision_model' => env('OPENROUTER_DECISION_MODEL', 'typesafe/jev-1.13'),

        // Vision model that reads screenshots (Jev's own image input is experimental)
        'vision_model' => env('OPENROUTER_VISION_MODEL', 'anthropic/claude-haiku-5.5'),
    ],

    /*
     | Request timeout in seconds for each AI call.
     */
    'timeout' => (int) env('AI_TIMEOUT', 60),

    /*
     | Characters of visible page text included in the analysis.
     */
    'page_text_chars' => (int) env('AI_PAGE_TEXT_CHARS', 4000),

    /*
     | Send an admin notification when severity reaches this level
     | (none, low, medium, high, critical), or whenever the site looks compromised.
     */
    'notify_min_severity' => env('AI_NOTIFY_MIN_SEVERITY', 'high'),
];
