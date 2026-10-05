<?php
/**
 * AI API configuration for Kilo Code and OpenCode Zen
 */
return [
    'kilo' => [
        'endpoint' => 'https://api.kilo.ai/api/gateway/chat/completions',
        'api_key'  => env('KILO_API_KEY', ''),
        'models'   => [
            'free'   => 'kilo-auto/free',
            'efficient' => 'kilo-auto/efficient',
            'balanced'   => 'kilo-auto/balanced',
        ],
        'rate_limit' => [
            'requests_per_hour' => 200,
            'burst'            => 10,
        ],
    ],
    
    'zen' => [
        'endpoint' => 'https://opencode.ai/zen/v1/chat/completions',
        'api_key'  => env('OPENCODE_ZEN_KEY', ''),
        'models'   => [
            'free' => [
                'deepseek-v4-flash-free',
                'space-bunny-free',
                'mimo-v2.6-flash-free',
                'nemotron-3-ultra-free',
                'ling-3.1-flash-free',
            ],
        ],
        'rate_limit' => [
            'requests_per_day' => 100,
        ],
    ],
    
    'master_prompt' => env('MASTER_PROMPT', ''),
    
    'jwt_secret' => env('JWT_SECRET', 'change-me-in-production'),
];