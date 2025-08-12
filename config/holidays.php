<?php

return [
    'default_uf' => env('HOLIDAYS_DEFAULT_UF', 'PA'),

    // ordem de providers p/ NACIONAIS
    'providers_nacionais' => [
        env('HOLIDAYS_PRIMARY', 'brasilapi'),
        env('HOLIDAYS_FALLBACK', 'nager'),
    ],

    // provider p/ ESTADUAIS (Calendarific recomendado)
    'provider_estadual' => env('HOLIDAYS_STATE_PROVIDER', 'calendarific'),
];
