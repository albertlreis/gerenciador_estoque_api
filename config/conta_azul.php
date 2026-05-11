<?php

return [

    'base_url' => rtrim((string) env('CONTA_AZUL_BASE_URL', 'https://api-v2.contaazul.com'), '/'),

    'auth_url' => rtrim((string) env('CONTA_AZUL_AUTH_URL', 'https://auth.contaazul.com'), '/'),

    'authorize_path' => env('CONTA_AZUL_AUTHORIZE_PATH', '/login'),

    'token_path' => env('CONTA_AZUL_TOKEN_PATH', '/oauth2/token'),

    'client_id' => env('CONTA_AZUL_CLIENT_ID'),

    'client_secret' => env('CONTA_AZUL_CLIENT_SECRET'),

    'redirect_uri' => env('CONTA_AZUL_REDIRECT_URI'),

    'scope' => env(
        'CONTA_AZUL_SCOPE',
        'openid profile aws.cognito.signin.user.admin'
    ),

    'timeout' => (int) env('CONTA_AZUL_TIMEOUT', 30),

    'connect_timeout' => (int) env('CONTA_AZUL_CONNECT_TIMEOUT', 10),

    'retry' => [
        'times' => (int) env('CONTA_AZUL_RETRY_TIMES', 3),
        'sleep_ms' => (int) env('CONTA_AZUL_RETRY_SLEEP_MS', 500),
    ],

    'throttle_seconds_per_connection' => (float) env('CONTA_AZUL_THROTTLE_SECONDS', 0.5),

    'pagination' => [
        'page_size' => (int) env('CONTA_AZUL_PAGE_SIZE', 50),
        'page_param' => env('CONTA_AZUL_PAGE_PARAM', 'pagina'),
        'page_size_param' => env('CONTA_AZUL_PAGE_SIZE_PARAM', 'tamanho_pagina'),
    ],

    'healthcheck_page_size' => (int) env('CONTA_AZUL_HEALTHCHECK_PAGE_SIZE', 10),

    /**
     * Importação por entidade: método HTTP, path opcional (sobrescreve paths.*),
     * query/body mesclados a cada página. Datas relativas para buscas (vendas/financeiro).
     */
    'import' => [
        'pessoa' => [
            'method' => 'GET',
            'query' => [],
            'body' => [],
        ],
        'produto' => [
            'method' => 'GET',
            'query' => [],
            'body' => [],
        ],
        'venda' => [
            'method' => env('CONTA_AZUL_IMPORT_VENDA_METHOD', 'GET'),
            'query' => [],
            'body' => [],
            'date_start_days_ago' => (int) env('CONTA_AZUL_IMPORT_VENDA_DAYS', 730),
            'date_query_keys' => ['data_inicio', 'data_fim'],
        ],
        'titulo' => [
            'method' => env('CONTA_AZUL_IMPORT_TITULO_METHOD', 'GET'),
            'query' => [],
            'body' => [],
            'date_start_days_ago' => (int) env('CONTA_AZUL_IMPORT_FIN_DAYS', 730),
            'date_query_keys' => ['data_vencimento_de', 'data_vencimento_ate'],
        ],
        'nota' => [
            'method' => 'GET',
            'query' => [],
            'body' => [],
            'date_start_days_ago' => (int) env('CONTA_AZUL_IMPORT_NOTA_DAYS', 730),
            'date_query_keys' => ['data_inicial', 'data_final'],
        ],
    ],

    /** Caminhos relativos à API (ajustáveis sem alterar código). */
    'paths' => [
        'pessoas' => env('CONTA_AZUL_PATH_PESSOAS', '/v1/pessoas'),
        'produtos' => env('CONTA_AZUL_PATH_PRODUTOS', '/v1/produtos'),
        'vendas_busca' => env('CONTA_AZUL_PATH_VENDAS', '/v1/venda/busca'),
        'titulos_list' => env('CONTA_AZUL_PATH_TITULOS_LIST', env('CONTA_AZUL_PATH_FINANCEIRO', '/v1/financeiro/eventos-financeiros/contas-a-receber/buscar')),
        'notas_list' => env('CONTA_AZUL_PATH_NOTAS_LIST', env('CONTA_AZUL_PATH_NOTAS', '/v1/notas-fiscais')),
        /** Exportação (criação) — podem divergir da importação/consulta. */
        'venda_create' => env('CONTA_AZUL_PATH_VENDA_CREATE', '/v1/venda'),
        'titulos_create' => env('CONTA_AZUL_PATH_TITULOS_CREATE', env('CONTA_AZUL_PATH_FIN_CREATE', '/v1/financeiro/eventos-financeiros/contas-a-receber')),
        'baixa_create' => env('CONTA_AZUL_PATH_BAIXA_CREATE', '/v1/financeiro/eventos-financeiros/parcelas/{parcela_id}/baixa'),
    ],

    /** URL do front para onde o callback OAuth redireciona (ex.: http://localhost:5173/integracoes/conta-azul). */
    'oauth_front_redirect' => env('CONTA_AZUL_OAUTH_FRONT_REDIRECT', env('FRONT_URL', 'http://localhost:5173') . '/integracoes/conta-azul'),

    'flags' => [
        'importacao_ativa' => filter_var(env('CONTA_AZUL_IMPORT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'exportacao_ativa' => filter_var(env('CONTA_AZUL_EXPORT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'reconciliacao_ativa' => filter_var(env('CONTA_AZUL_RECONCILE_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

];
