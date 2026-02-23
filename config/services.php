<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'comms' => [
        'base_url' => env('COMMS_BASE_URL'),
        'api_key' => env('COMMS_API_KEY'),
        'api_secret' => env('COMMS_API_SECRET'),
    ],

    'extrator_pedido' => [
        'url' => env('SERVICES_EXTRATOR_PEDIDO_URL', 'http://127.0.0.1:8010/extrair-pedido'),
        'file_field' => env('SERVICES_EXTRATOR_PEDIDO_FILE_FIELD', 'pdf'),
        'force_local_url' => (bool) env('SERVICES_EXTRATOR_PEDIDO_FORCE_LOCAL_URL', true),
        'timeout' => (int) env('SERVICES_EXTRATOR_PEDIDO_TIMEOUT', 120),
        'retry_times' => (int) env('SERVICES_EXTRATOR_PEDIDO_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('SERVICES_EXTRATOR_PEDIDO_RETRY_SLEEP_MS', 500),
    ],

];
