<?php

namespace App\Services;

use App\Models\AssistenciaChamado;
use App\Models\ProdutoVariacao;
use App\Support\Auditoria\LaravelLogFileParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditoriaLogBackfillService
{
    public function __construct(
        private readonly AuditoriaLogService $auditoria,
        private readonly LaravelLogFileParser $parser
    ) {
    }

    /**
     * @return array<string,int>
     */
    public function backfill(?Carbon $cutoff = null, bool $includeFiles = true): array
    {
        $cutoff ??= now();

        $stats = [
            'auditoria_eventos' => $this->auditoriaEventos($cutoff),
            'financeiro_auditorias' => $this->financeiroAuditorias($cutoff),
            'activity_log' => $this->activityLog($cutoff),
            'estoque_logs' => $this->estoqueLogs($cutoff),
            'assistencia_chamado_logs' => $this->assistenciaLogs($cutoff),
            'produto_variacao_dimensao_auditorias' => $this->produtoDimensaoAuditorias($cutoff),
            'conta_azul_sync_logs' => $this->contaAzulSyncLogs($cutoff),
            'conta_azul_import_batches' => $this->contaAzulBatches($cutoff),
            'google_calendar_logs' => $this->googleCalendarLogs($cutoff),
            'logs_metricas' => $this->logsMetricas($cutoff),
        ];

        if ($includeFiles) {
            $stats['log_files'] = $this->logFiles();
        }

        return $stats;
    }

    private function auditoriaEventos(Carbon $cutoff): int
    {
        return $this->chunk('auditoria_eventos', $cutoff, function ($row): void {
            $mudancas = Schema::hasTable('auditoria_mudancas')
                ? DB::table('auditoria_mudancas')->where('evento_id', $row->id)->get()
                    ->map(fn ($m) => [
                        'campo' => $m->campo,
                        'old_value' => $m->old_value,
                        'new_value' => $m->new_value,
                        'value_type' => $m->value_type,
                    ])->all()
                : [];

            $this->auditoria->registrar([
                'occurred_at' => $row->created_at,
                'tipo' => 'auditoria',
                'categoria' => 'negocio',
                'modulo' => $row->module,
                'acao' => $row->action,
                'label' => $row->label,
                'message' => $row->label,
                'actor_type' => $row->actor_type,
                'actor_id' => $row->actor_id,
                'actor_name' => $row->actor_name,
                'entity_type' => $row->auditable_type,
                'entity_id' => $row->auditable_id,
                'route' => $row->route,
                'method' => $row->method,
                'ip' => $row->ip,
                'user_agent' => $row->user_agent,
                'origem' => $row->origin,
                'metadata_json' => $this->decode($row->metadata_json ?? null),
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'auditoria_eventos',
                'source_id' => $row->id,
                'retention_days' => 365,
            ], $mudancas);
        });
    }

    private function financeiroAuditorias(Carbon $cutoff): int
    {
        return $this->chunk('financeiro_auditorias', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->created_at,
                'tipo' => 'auditoria',
                'categoria' => 'negocio',
                'modulo' => 'financeiro',
                'acao' => $row->acao,
                'label' => 'Auditoria financeira',
                'message' => "Financeiro: {$row->acao}",
                'actor_id' => $row->usuario_id,
                'entity_type' => $row->entidade_type,
                'entity_id' => $row->entidade_id,
                'ip' => $row->ip,
                'user_agent' => $row->user_agent,
                'context_json' => [
                    'antes' => $this->decode($row->antes_json ?? null),
                    'depois' => $this->decode($row->depois_json ?? null),
                ],
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'financeiro_auditorias',
                'source_id' => $row->id,
                'retention_days' => 365,
            ]);
        });
    }

    private function activityLog(Carbon $cutoff): int
    {
        $table = (string) config('activitylog.table_name', 'activity_log');

        return $this->chunk($table, $cutoff, function ($row) use ($table): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->created_at,
                'tipo' => 'auditoria',
                'categoria' => 'negocio',
                'modulo' => $row->log_name,
                'acao' => $row->event ?? 'activity',
                'label' => $row->description,
                'message' => $row->description,
                'actor_type' => $row->causer_type,
                'actor_id' => $row->causer_id,
                'entity_type' => $row->subject_type,
                'entity_id' => $row->subject_id,
                'metadata_json' => $this->decode($row->properties ?? null),
                'context_json' => ['batch_uuid' => $row->batch_uuid ?? null],
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => $table,
                'source_id' => $row->id,
                'retention_days' => 365,
            ]);
        });
    }

    private function estoqueLogs(Carbon $cutoff): int
    {
        return $this->chunk('estoque_logs', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->created_at,
                'tipo' => 'auditoria',
                'categoria' => 'negocio',
                'modulo' => 'estoque',
                'acao' => $row->acao,
                'label' => 'Log de estoque',
                'message' => "Estoque: {$row->acao}",
                'actor_id' => $row->id_usuario,
                'ip' => $row->ip,
                'user_agent' => $row->user_agent,
                'context_json' => $this->decode($row->payload ?? null),
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'estoque_logs',
                'source_id' => $row->id,
                'retention_days' => 365,
            ]);
        });
    }

    private function assistenciaLogs(Carbon $cutoff): int
    {
        return $this->chunk('assistencia_chamado_logs', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->created_at,
                'tipo' => 'evento',
                'categoria' => 'negocio',
                'modulo' => 'assistencias',
                'acao' => 'status',
                'status' => $row->status_para,
                'label' => 'Log de assistencia',
                'message' => $row->mensagem,
                'actor_id' => $row->usuario_id,
                'entity_type' => AssistenciaChamado::class,
                'entity_id' => $row->chamado_id,
                'context_json' => [
                    'item_id' => $row->item_id,
                    'status_de' => $row->status_de,
                    'status_para' => $row->status_para,
                    'meta' => $this->decode($row->meta_json ?? null),
                ],
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'assistencia_chamado_logs',
                'source_id' => $row->id,
                'retention_days' => 365,
            ], [[
                'campo' => 'status',
                'old' => $row->status_de,
                'new' => $row->status_para,
                'value_type' => 'string',
            ]]);
        });
    }

    private function produtoDimensaoAuditorias(Carbon $cutoff): int
    {
        return $this->chunk('produto_variacao_dimensao_auditorias', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->created_at,
                'tipo' => 'auditoria',
                'categoria' => 'negocio',
                'modulo' => 'produto_variacoes',
                'acao' => $row->acao,
                'label' => 'Auditoria de dimensoes de produto',
                'message' => "Dimensao {$row->campo_destino}",
                'entity_type' => ProdutoVariacao::class,
                'entity_id' => $row->variacao_id,
                'context_json' => [
                    'produto_variacao_atributo_id' => $row->produto_variacao_atributo_id,
                    'atributo_legado' => $row->atributo_legado,
                    'valor_legado' => $row->valor_legado,
                ],
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'produto_variacao_dimensao_auditorias',
                'source_id' => $row->id,
                'retention_days' => 365,
            ], [[
                'campo' => $row->campo_destino ?: 'dimensao',
                'old' => $row->valor_anterior,
                'new' => $row->valor_final,
                'value_type' => 'decimal',
            ]]);
        });
    }

    private function contaAzulSyncLogs(Carbon $cutoff): int
    {
        return $this->chunk('conta_azul_sync_logs', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->executado_em ?? $row->created_at,
                'tipo' => 'integracao',
                'categoria' => 'integracao',
                'nivel' => $row->status === 'erro' ? 'error' : 'info',
                'modulo' => 'conta_azul',
                'acao' => $row->direcao,
                'status' => $row->status,
                'label' => 'Log de sincronizacao Conta Azul',
                'message' => $row->erro_mensagem ?: $row->resposta_resumo,
                'entity_type' => $row->tipo_entidade,
                'entity_id' => $row->id_local,
                'context_json' => (array) $row,
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'conta_azul_sync_logs',
                'source_id' => $row->id,
                'retention_days' => 365,
            ]);
        });
    }

    private function contaAzulBatches(Carbon $cutoff): int
    {
        return $this->chunk('conta_azul_import_batches', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->finalizado_em ?? $row->iniciado_em ?? $row->created_at,
                'tipo' => 'integracao',
                'categoria' => 'integracao',
                'nivel' => $row->status === 'erro' ? 'error' : 'info',
                'modulo' => 'conta_azul',
                'acao' => 'import_batch',
                'status' => $row->status,
                'label' => 'Batch de importacao Conta Azul',
                'message' => "Batch Conta Azul #{$row->id}",
                'context_json' => (array) $row,
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'conta_azul_import_batches',
                'source_id' => $row->id,
                'retention_days' => 365,
                'replace_existing' => true,
            ]);
        });
    }

    private function googleCalendarLogs(Carbon $cutoff): int
    {
        return $this->chunk('google_calendar_logs', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->executado_em ?? $row->created_at,
                'tipo' => 'integracao',
                'categoria' => 'integracao',
                'nivel' => $row->status === 'erro' ? 'error' : 'info',
                'modulo' => 'google_calendar',
                'acao' => $row->acao,
                'status' => $row->status,
                'label' => 'Log Google Agenda',
                'message' => $row->erro_mensagem ?: $row->response_resumo,
                'actor_id' => $row->usuario_id,
                'entity_type' => 'google_calendar_event',
                'entity_id' => $row->event_id,
                'context_json' => (array) $row,
                'source_system' => 'estoque',
                'source_kind' => 'legacy_table',
                'source_table' => 'google_calendar_logs',
                'source_id' => $row->id,
                'retention_days' => 365,
            ]);
        });
    }

    private function logsMetricas(Carbon $cutoff): int
    {
        return $this->chunk('logs_metricas', $cutoff, function ($row): void {
            $this->auditoria->registrar([
                'occurred_at' => $row->criado_em,
                'tipo' => 'metrica',
                'categoria' => 'metrica',
                'modulo' => 'monitoramento',
                'acao' => $row->origem,
                'status' => $row->status,
                'label' => $row->chave,
                'message' => "{$row->origem}: {$row->status}",
                'actor_id' => $row->usuario_id,
                'context_json' => [
                    'chave' => $row->chave,
                    'origem' => $row->origem,
                    'duracao_ms' => $row->duracao_ms,
                ],
                'source_system' => 'auth',
                'source_kind' => 'legacy_table',
                'source_table' => 'logs_metricas',
                'source_id' => $row->id,
                'retention_days' => 90,
            ]);
        }, 'criado_em');
    }

    private function logFiles(): int
    {
        $count = 0;
        $sources = [
            ['system' => 'estoque', 'dir' => storage_path('logs')],
            ['system' => 'auth', 'dir' => base_path('..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs')],
        ];

        foreach ($sources as $source) {
            if (!is_dir($source['dir'])) {
                continue;
            }

            foreach (glob($source['dir'] . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
                $channel = str_starts_with(basename($file), 'estoque') ? 'estoque' : 'laravel';
                foreach ($this->parser->parse($file, $source['system'], $channel) as $payload) {
                    $this->auditoria->registrar($payload);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param callable(object):void $callback
     */
    private function chunk(string $table, Carbon $cutoff, callable $callback, string $dateColumn = 'created_at'): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $count = 0;
        $query = DB::table($table)->orderBy('id');

        if (Schema::hasColumn($table, $dateColumn)) {
            $query->where($dateColumn, '<=', $cutoff);
        }

        $query->chunkById(500, function ($rows) use (&$count, $callback): void {
            foreach ($rows as $row) {
                $callback($row);
                $count++;
            }
        });

        return $count;
    }

    private function decode(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}
