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
        'page_size_param' => env('CONTA_AZUL_PAGE_SIZE_PARAM', 'tamanhoPagina'),
    ],

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
            'date_query_keys' => ['dataInicio', 'dataFim'],
        ],
        'titulo' => [
            'method' => env('CONTA_AZUL_IMPORT_TITULO_METHOD', 'POST'),
            'query' => [],
            'body' => [
                'tipoConsulta' => env('CONTA_AZUL_IMPORT_TITULO_TIPO', 'TODOS'),
            ],
            'date_start_days_ago' => (int) env('CONTA_AZUL_IMPORT_FIN_DAYS', 730),
            'date_query_keys' => ['dataInicio', 'dataFim'],
        ],
        'baixa' => [
            'method' => env('CONTA_AZUL_IMPORT_BAIXA_METHOD', 'POST'),
            'query' => [],
            'body' => [
                'tipoConsulta' => env('CONTA_AZUL_IMPORT_BAIXA_TIPO', 'BAIXA'),
            ],
            'date_start_days_ago' => (int) env('CONTA_AZUL_IMPORT_BAIXA_DAYS', 730),
            'date_query_keys' => ['dataInicio', 'dataFim'],
        ],
        'nota' => [
            'method' => 'GET',
            'query' => [],
            'body' => [],
            'date_start_days_ago' => (int) env('CONTA_AZUL_IMPORT_NOTA_DAYS', 730),
            'date_query_keys' => ['dataInicio', 'dataFim'],
        ],
    ],

    /** Caminhos relativos à API (ajustáveis sem alterar código). */
    'paths' => [
        'pessoas' => env('CONTA_AZUL_PATH_PESSOAS', '/v1/pessoas'),
        'produtos' => env('CONTA_AZUL_PATH_PRODUTOS', '/v1/produtos'),
        'vendas_busca' => env('CONTA_AZUL_PATH_VENDAS', '/v1/venda/busca'),
        'financeiro' => env('CONTA_AZUL_PATH_FINANCEIRO', '/v1/financeiro/eventos-financeiros/consulta'),
        'baixas' => env('CONTA_AZUL_PATH_BAIXAS', '/v1/financeiro/eventos-financeiros/consulta'),
        'notas' => env('CONTA_AZUL_PATH_NOTAS', '/v1/notas'),
        /** Exportação (criação) — podem divergir da importação/consulta. */
        'venda_create' => env('CONTA_AZUL_PATH_VENDA_CREATE', '/v1/venda'),
        'financeiro_create' => env('CONTA_AZUL_PATH_FIN_CREATE', '/v1/financeiro/titulos'),
        'baixa_create' => env('CONTA_AZUL_PATH_BAIXA_CREATE', '/v1/financeiro/baixas'),
    ],

    /** URL do front para onde o callback OAuth redireciona (ex.: http://localhost:5173/integracoes/conta-azul). */
    'oauth_front_redirect' => env('CONTA_AZUL_OAUTH_FRONT_REDIRECT', env('FRONT_URL', 'http://localhost:5173') . '/integracoes/conta-azul'),

    'flags' => [
        'importacao_ativa' => filter_var(env('CONTA_AZUL_IMPORT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'exportacao_ativa' => filter_var(env('CONTA_AZUL_EXPORT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'reconciliacao_ativa' => filter_var(env('CONTA_AZUL_RECONCILE_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

];
