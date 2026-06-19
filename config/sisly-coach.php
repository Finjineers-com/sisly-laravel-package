<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic API
    |--------------------------------------------------------------------------
    | API key must be set as a server-side secret. Never expose in mobile
    | binaries, web bundles, or committed env files.
    */
    'anthropic' => [
        'api_key'            => env('ANTHROPIC_API_KEY'),
        'coach_model'        => env('SISLY_COACH_MODEL', 'claude-sonnet-4-6'),
        'safety_model'       => env('SISLY_SAFETY_MODEL', 'claude-haiku-4-5'),
        'max_tokens_coach'   => 400,
        'max_tokens_safety'  => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sisly Content Library API
    |--------------------------------------------------------------------------
    | The endpoint used to resolve coach prescriptions to real 2-min assets.
    | Coach-to-content-type mapping is fixed per the product spec and must
    | not be changed without product sign-off.
    */
    'content_api' => [
        'base_url' => env('SISLY_CONTENT_API_URL', 'https://api.sisly.ai'),
        'endpoint' => '/api/v1/insights/by-type',
        'timeout'  => 10, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Coach → Content-Type Mapping
    |--------------------------------------------------------------------------
    | Maps each coach_id to the content_type param sent to the content API.
    | Frozen — do not change without product sign-off.
    */
    'coach_content_type_map' => [
        'meetly'  => 'Meetings',
        'presso'  => 'Too much',
        'loopy'   => 'Quiet mind',
        'boostly' => 'Confidence',
        'vento'   => 'Let it out',
    ],

    /*
    |--------------------------------------------------------------------------
    | CoachState Storage
    |--------------------------------------------------------------------------
    | Driver options: 'database' (default) or 'cache'.
    | 'database' is recommended for production (durable, queryable).
    | 'cache' can be used with Redis for lower latency if the host app accepts
    | the risk of state loss on cache eviction.
    |
    | ttl_seconds: how long a session's state is kept after the last message.
    | Default 24 hours. A session beyond this TTL starts fresh.
    */
    'state' => [
        'driver'      => env('SISLY_STATE_DRIVER', 'database'),
        'ttl_seconds' => env('SISLY_STATE_TTL', 86400), // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    | Set enabled to false if you want to define your own routes and only use
    | the package services directly.
    | prefix: the URL prefix for all package routes.
    | middleware: add your own auth/throttle middleware here.
    */
    'routing' => [
        'enabled'    => true,
        'prefix'     => env('SISLY_ROUTE_PREFIX', 'api/coach'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    | user_id_param: the request parameter (or JWT claim) the package reads
    | as the opaque user identifier. This must be set by the host app's auth
    | middleware before the request reaches the coach controller.
    | The package never reads Auth::id() — it always uses this param, keeping
    | it compatible with API-token and JWT auth setups.
    */
    'auth' => [
        'user_id_param' => env('SISLY_USER_ID_PARAM', 'sisly_user_id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety & Crisis Resources
    |--------------------------------------------------------------------------
    | HARD GATE: Replace the placeholder crisis copy and helpline numbers with
    | clinically signed-off text before any real user touches this feature.
    |
    | The package will log a warning on boot if 'crisis_signed_off' is false.
    | Set it to true only after you have confirmed:
    |   - Qualified mental-health professional sign-off on copy in EN + AR
    |   - Verified, current UAE crisis helpline numbers
    |
    | crisis_copy is keyed by locale ('en', 'ar').
    */
    'safety' => [
        'crisis_signed_off' => env('SISLY_CRISIS_SIGNED_OFF', false),

        'crisis_copy' => [
            'en' => env(
                'SISLY_CRISIS_COPY_EN',
                "I'm really glad you told me, and I'm a bit worried about you. You deserve real support right now, more than I can give. Please reach out: UAE HOPE line 800 4673, or 999 for emergencies. I'm here with you."
            ),
            'ar' => env(
                'SISLY_CRISIS_COPY_AR',
                "سعيد جداً أنك أخبرتني، وأنا قلق عليك قليلاً. أنت تستحق دعماً حقيقياً الآن، أكثر مما أستطيع تقديمه. أرجوك تواصل: خط الأمل في الإمارات 800 4673، أو 999 للطوارئ. أنا هنا معك."
            ),
        ],

        'crisis_label' => [
            'en' => 'You deserve real support',
            'ar' => 'أنت تستحق دعماً حقيقياً',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Session Memory
    |--------------------------------------------------------------------------
    | If true, the one-line situation_summary carries over to the next session
    | for the same user+coach combination. If false (default), each new
    | session_id starts with a blank summary.
    | Confirm this decision with product before changing.
    */
    'cross_session_memory' => env('SISLY_CROSS_SESSION_MEMORY', false),

    /*
    |--------------------------------------------------------------------------
    | Input Validation
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'max_message_length' => 2000,
    ],

];
