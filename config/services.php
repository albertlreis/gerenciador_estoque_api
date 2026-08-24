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

    'pdf_images' => [
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', env(
                'PDF_IMAGE_ALLOWED_HOSTS',
                'estoque.sierra.acadsoft.com.br,hml-estoque.sierra.acadsoft.com.br'
            ))
        ))),
        'timeout_seconds' => (int) env('PDF_IMAGE_TIMEOUT_SECONDS', 5),
        'max_bytes' => (int) env('PDF_IMAGE_MAX_BYTES', 8388608),
    ],

];
