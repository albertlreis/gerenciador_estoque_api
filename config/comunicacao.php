<?php

return [
    'processing_enabled' => env('SIERRA_COMMS_PROCESSING_ENABLED', false),
    'real_send_enabled' => env('SIERRA_COMMS_REAL_SEND_ENABLED', false),
    'channels' => [
        'email' => env('SIERRA_COMMS_EMAIL_ENABLED', false),
        'sms' => env('SIERRA_COMMS_SMS_ENABLED', false),
        'whatsapp' => env('SIERRA_COMMS_WHATSAPP_ENABLED', false),
    ],
    'templates' => [
        'pedido_status_email' => env('SIERRA_TEMPLATE_CODE_PEDIDO_STATUS_EMAIL', 'sierra_pedido_status_email'),
        'cobranca_sms' => env('SIERRA_TEMPLATE_CODE_COBRANCA_SMS', 'sierra_cobranca_sms'),
        'cobranca_whatsapp' => env('SIERRA_TEMPLATE_CODE_COBRANCA_WPP', 'sierra_cobranca_whatsapp'),
    ],
];
