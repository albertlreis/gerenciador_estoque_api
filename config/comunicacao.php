<?php

return [
    'templates' => [
        'pedido_status_email' => env('SIERRA_TEMPLATE_CODE_PEDIDO_STATUS_EMAIL', 'sierra_pedido_status_email'),
        'cobranca_sms' => env('SIERRA_TEMPLATE_CODE_COBRANCA_SMS', 'sierra_cobranca_sms'),
        'cobranca_whatsapp' => env('SIERRA_TEMPLATE_CODE_COBRANCA_WPP', 'sierra_cobranca_whatsapp'),
    ],
];

