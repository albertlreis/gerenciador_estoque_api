<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW vw_grafana_auditoria_logs AS
SELECT
    id,
    occurred_at,
    tipo,
    categoria,
    nivel,
    modulo,
    acao,
    status,
    source_system,
    source_kind,
    origem,
    method,
    route,
    actor_id,
    entity_type,
    entity_id,
    COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.request_id')),
        JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.request_id'))
    ) AS request_id,
    COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.service')),
        JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.service'))
    ) AS service,
    LEFT(message, 500) AS message_excerpt,
    retention_days
FROM auditoria_logs
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW vw_grafana_auditoria_resumo AS
SELECT
    TIMESTAMP(DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00')) AS time_bucket,
    source_system,
    source_kind,
    categoria,
    nivel,
    modulo,
    acao,
    COUNT(*) AS total
FROM auditoria_logs
GROUP BY
    TIMESTAMP(DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00')),
    source_system,
    source_kind,
    categoria,
    nivel,
    modulo,
    acao
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW vw_grafana_browser_errors AS
SELECT
    id,
    occurred_at,
    nivel,
    status AS event_type,
    route,
    COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.request_id')),
        JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.request_id'))
    ) AS request_id,
    JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.url')) AS url,
    JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) AS source,
    JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.lineno')) AS lineno,
    JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.colno')) AS colno,
    LEFT(message, 500) AS message_excerpt,
    LEFT(JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.stack')), 1000) AS stack_excerpt
FROM auditoria_logs
WHERE source_system = 'front'
  AND source_kind = 'browser_error'
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS vw_grafana_browser_errors');
        DB::statement('DROP VIEW IF EXISTS vw_grafana_auditoria_resumo');
        DB::statement('DROP VIEW IF EXISTS vw_grafana_auditoria_logs');
    }
};
