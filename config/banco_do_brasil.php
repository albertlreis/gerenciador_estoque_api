<?php

return [
    'extratos' => [
        'enabled' => (bool) env('BB_EXTRATOS_ENABLED', false),
        'env' => env('BB_EXTRATOS_ENV', 'producao'),
        'client_id' => env('BB_EXTRATOS_CLIENT_ID'),
        'client_secret' => env('BB_EXTRATOS_CLIENT_SECRET'),
        'app_key' => env('BB_EXTRATOS_APP_KEY'),
        'oauth_url' => env('BB_EXTRATOS_OAUTH_URL', 'https://oauth.bb.com.br/oauth/token'),
        'base_url' => rtrim((string) env('BB_EXTRATOS_BASE_URL', 'https://api.bb.com.br'), '/'),
        'statement_path' => env('BB_EXTRATOS_STATEMENT_PATH', '/extratos/v1/conta-corrente/agencia/{agencia}/conta/{conta}'),
        'app_key_param' => env('BB_EXTRATOS_APP_KEY_PARAM', 'gw-dev-app-key'),
        'scope' => env('BB_EXTRATOS_SCOPE', 'extrato-info'),
        'timeout' => (int) env('BB_EXTRATOS_TIMEOUT', 30),
        'connect_timeout' => (int) env('BB_EXTRATOS_CONNECT_TIMEOUT', 10),
        'retry_times' => (int) env('BB_EXTRATOS_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('BB_EXTRATOS_RETRY_SLEEP_MS', 500),
    ],
];
