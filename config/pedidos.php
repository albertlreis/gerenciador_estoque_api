<?php

return [
    'fluxo_operacional_v2_enabled' => filter_var(
        env('PEDIDOS_FLUXO_OPERACIONAL_V2_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),
];
