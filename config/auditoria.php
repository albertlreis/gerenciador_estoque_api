<?php

return [
    'default_ignored_fields' => [
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
    ],

    'sensitive_fields' => [
        'password',
        'senha',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'client_secret',
        'authorization',
        'api_key',
        'x_api_key',
    ],

    'entity_sensitive_fields' => [
        \App\Models\Usuario::class => ['senha'],
        \App\Models\AcessoUsuario::class => ['senha'],
    ],
];
