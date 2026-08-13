<?php

namespace App\Console\Commands\ContaAzul;

use App\Integrations\ContaAzul\Support\ContaAzulRuntimeState;
use App\Services\AuditoriaLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ContaAzulRepararConexaoLegadaCommand extends Command
{
    private const REPAIR_LOCK_KEY = 'conta_azul:reparar_conexao_legada:lock';

    private const HISTORY_TABLES = [
        'conta_azul_mapeamentos',
        'conta_azul_import_batches',
        'conta_azul_sync_logs',
        'conta_azul_reconciliation_states',
        'conta_azul_cobrancas',
        'stg_conta_azul_pessoas',
        'stg_conta_azul_produtos',
        'stg_conta_azul_vendas',
        'stg_conta_azul_financeiro',
        'stg_conta_azul_contas_pagar',
        'stg_conta_azul_parcelas',
        'stg_conta_azul_baixas',
        'stg_conta_azul_contas_financeiras',
        'stg_conta_azul_saldos_contas_financeiras',
        'stg_conta_azul_categorias_financeiras',
        'stg_conta_azul_centros_custo',
        'stg_conta_azul_formas_pagamento',
        'stg_conta_azul_notas',
    ];

    private const STAGING_TABLES = [
        'stg_conta_azul_pessoas',
        'stg_conta_azul_produtos',
        'stg_conta_azul_vendas',
        'stg_conta_azul_financeiro',
        'stg_conta_azul_contas_pagar',
        'stg_conta_azul_parcelas',
        'stg_conta_azul_baixas',
        'stg_conta_azul_contas_financeiras',
        'stg_conta_azul_saldos_contas_financeiras',
        'stg_conta_azul_categorias_financeiras',
        'stg_conta_azul_centros_custo',
        'stg_conta_azul_formas_pagamento',
        'stg_conta_azul_notas',
    ];

    protected $signature = 'conta-azul:reparar-conexao-legada
        {--connection=1 : ID da unica conexao legada esperada}
        {--nome= : Nome da loja que recebera a conexao e o historico}
        {--execute : Persiste o reparo depois de todas as validacoes}
        {--dry-run : Forca a simulacao mesmo quando --execute for informado}';

    protected $description = 'Classifica com seguranca a unica conexao Conta Azul legada e seu historico em uma loja';

    public function handle(AuditoriaLogService $auditoria): int
    {
        $connectionId = filter_var($this->option('connection'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $nome = trim((string) $this->option('nome'));
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');

        if (! $connectionId || $nome === '') {
            $this->error('Informe --connection e --nome com valores validos.');

            return self::FAILURE;
        }

        $repairLock = Cache::lock(self::REPAIR_LOCK_KEY, 600);
        $importLock = Cache::lock(ContaAzulRuntimeState::AUTO_FINANCE_IMPORT_LOCK_KEY, 600);

        if (! $repairLock->get()) {
            $this->error('Outro reparo de conexao legada ja esta em andamento.');

            return self::FAILURE;
        }

        if (! $importLock->get()) {
            $repairLock->release();
            $this->error('A importacao financeira Conta Azul esta em andamento. Tente novamente depois.');

            return self::FAILURE;
        }

        try {
            $plano = $this->inspecionar((int) $connectionId, $nome);

            if ($plano['estado'] === 'ja_reparado') {
                $this->info('A conexao e o historico ja estao vinculados corretamente.');
                $this->exibirResumo($plano);

                return self::SUCCESS;
            }

            if (! $execute) {
                $this->info('Dry-run concluido. Nenhum dado foi alterado.');
                $this->exibirResumo($plano);

                return self::SUCCESS;
            }

            $resultado = DB::transaction(function () use ($connectionId, $nome, $auditoria): array {
                $planoAtual = $this->inspecionar((int) $connectionId, $nome);
                if ($planoAtual['estado'] !== 'pronto') {
                    throw new RuntimeException('O estado mudou depois do dry-run; o reparo foi cancelado.');
                }

                $tokenAntes = $this->tokenFingerprint((int) $connectionId);
                $lojaId = DB::table('lojas')->insertGetId([
                    'codigo' => str_replace('-', '', (string) Str::uuid()),
                    'nome' => $nome,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $alterados = [];
                foreach (self::HISTORY_TABLES as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'loja_id')) {
                        continue;
                    }

                    $alterados[$table] = DB::table($table)
                        ->whereNull('loja_id')
                        ->update(['loja_id' => $lojaId]);
                }

                if (Schema::hasTable('notas_fiscais') && Schema::hasColumn('notas_fiscais', 'loja_id')) {
                    $alterados['notas_fiscais'] = DB::table('notas_fiscais')
                        ->where('origem', 'conta_azul')
                        ->whereNull('loja_id')
                        ->update(['loja_id' => $lojaId]);
                }

                if (Schema::hasTable('auditoria_logs')) {
                    $alterados['auditoria_logs'] = DB::affectingStatement(
                        "UPDATE auditoria_logs
                         SET context_json = JSON_SET(COALESCE(context_json, JSON_OBJECT()), '$.loja_id', CAST(? AS UNSIGNED))
                         WHERE modulo = 'conta_azul'
                           AND JSON_EXTRACT(context_json, '$.loja_id') IS NULL",
                        [$lojaId]
                    );
                }

                $alterados['conta_azul_conexoes'] = DB::table('conta_azul_conexoes')
                    ->where('id', (int) $connectionId)
                    ->whereNull('loja_id')
                    ->update(['loja_id' => $lojaId, 'updated_at' => now()]);

                if ($alterados['conta_azul_conexoes'] !== 1) {
                    throw new RuntimeException('A conexao legada nao foi vinculada; o reparo foi revertido.');
                }

                if (! hash_equals($tokenAntes, $this->tokenFingerprint((int) $connectionId))) {
                    throw new RuntimeException('O token foi alterado durante o reparo; a transacao foi revertida.');
                }

                $auditoria->registrar([
                    'tipo' => 'operacao',
                    'categoria' => 'integracao',
                    'nivel' => 'info',
                    'modulo' => 'conta_azul',
                    'acao' => 'classificar_conexao_legada',
                    'status' => 'concluido',
                    'label' => 'Conexao Conta Azul legada classificada',
                    'message' => 'Conexao legada e historico vinculados a uma loja ativa.',
                    'source_system' => 'estoque',
                    'source_kind' => 'business_event',
                    'source_table' => 'conta_azul_conexoes',
                    'source_id' => (string) $connectionId,
                    'source_uid' => hash('sha256', "conta-azul|classificar-conexao-legada|{$connectionId}|{$lojaId}"),
                    'entity_type' => 'lojas',
                    'entity_id' => (string) $lojaId,
                    'context_json' => [
                        'loja_id' => $lojaId,
                        'conexao_id' => (int) $connectionId,
                    ],
                    'metadata_json' => [
                        'nome_loja' => $nome,
                        'registros_classificados' => $alterados,
                        'token_preservado' => true,
                    ],
                ]);

                return [
                    'estado' => 'reparado',
                    'loja_id' => $lojaId,
                    'conexao_id' => (int) $connectionId,
                    'nome' => $nome,
                    'registros_classificados' => $alterados,
                    'token_preservado' => true,
                ];
            }, 3);

            $this->info('Reparo concluido com sucesso.');
            $this->exibirResumo($resultado);

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $importLock->release();
            $repairLock->release();
        }
    }

    private function inspecionar(int $connectionId, string $nome): array
    {
        if (! Schema::hasTable('lojas') || ! Schema::hasTable('conta_azul_conexoes') || ! Schema::hasTable('conta_azul_tokens')) {
            throw new RuntimeException('As tabelas necessarias para o reparo nao estao disponiveis.');
        }

        $lojas = DB::table('lojas')->orderBy('id')->get(['id', 'nome', 'ativo']);
        $conexoes = DB::table('conta_azul_conexoes')->orderBy('id')->get(['id', 'loja_id', 'status']);
        $tokens = DB::table('conta_azul_tokens')->get(['id', 'conexao_id']);

        if ($lojas->count() === 1
            && $conexoes->count() === 1
            && (int) $conexoes->first()->id === $connectionId
            && (int) $conexoes->first()->loja_id === (int) $lojas->first()->id
            && (string) $lojas->first()->nome === $nome
            && (bool) $lojas->first()->ativo
            && $tokens->count() === 1
            && (int) $tokens->first()->conexao_id === $connectionId) {
            $pendencias = $this->contagensHistoricas(null, true);
            if (array_sum($pendencias) !== 0) {
                throw new RuntimeException('O reparo esta parcial: ainda existem registros Conta Azul sem loja.');
            }

            return [
                'estado' => 'ja_reparado',
                'loja_id' => (int) $lojas->first()->id,
                'conexao_id' => $connectionId,
                'nome' => $nome,
                'registros_sem_loja' => $pendencias,
                'token_preservado' => true,
            ];
        }

        if ($lojas->isNotEmpty()) {
            throw new RuntimeException('O reparo exige que nao exista nenhuma loja cadastrada.');
        }

        if ($conexoes->count() !== 1
            || (int) $conexoes->first()->id !== $connectionId
            || $conexoes->first()->loja_id !== null) {
            throw new RuntimeException('O reparo exige exatamente a conexao legada informada, ainda sem loja.');
        }

        if ($tokens->count() !== 1 || (int) $tokens->first()->conexao_id !== $connectionId) {
            throw new RuntimeException('O reparo exige exatamente um token associado a conexao legada.');
        }

        $atribuidos = $this->contagensHistoricas(null, false);
        if (array_sum($atribuidos) !== 0) {
            throw new RuntimeException('Existem registros Conta Azul ja atribuidos a uma loja; o reparo foi cancelado.');
        }

        $this->validarConflitosUnicidade();

        return [
            'estado' => 'pronto',
            'conexao_id' => $connectionId,
            'nome' => $nome,
            'registros_sem_loja' => $this->contagensHistoricas(null, true),
            'token_preservado' => true,
        ];
    }

    private function contagensHistoricas(?int $lojaId, bool $semLoja): array
    {
        $contagens = [];

        foreach (self::HISTORY_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'loja_id')) {
                continue;
            }

            $query = DB::table($table);
            $contagens[$table] = $semLoja
                ? $query->whereNull('loja_id')->count()
                : ($lojaId === null ? $query->whereNotNull('loja_id')->count() : $query->where('loja_id', $lojaId)->count());
        }

        if (Schema::hasTable('notas_fiscais') && Schema::hasColumn('notas_fiscais', 'loja_id')) {
            $query = DB::table('notas_fiscais')->where('origem', 'conta_azul');
            $contagens['notas_fiscais'] = $semLoja
                ? $query->whereNull('loja_id')->count()
                : ($lojaId === null ? $query->whereNotNull('loja_id')->count() : $query->where('loja_id', $lojaId)->count());
        }

        if (Schema::hasTable('auditoria_logs')) {
            $query = DB::table('auditoria_logs')->where('modulo', 'conta_azul');
            $contagens['auditoria_logs'] = $semLoja
                ? $query->whereNull('context_json->loja_id')->count()
                : ($lojaId === null ? $query->whereNotNull('context_json->loja_id')->count() : $query->where('context_json->loja_id', $lojaId)->count());
        }

        return $contagens;
    }

    private function validarConflitosUnicidade(): void
    {
        foreach (self::STAGING_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'identificador_externo')) {
                continue;
            }

            $duplicado = DB::table($table)
                ->select('identificador_externo')
                ->whereNull('loja_id')
                ->groupBy('identificador_externo')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicado) {
                throw new RuntimeException("A tabela {$table} possui identificadores duplicados que impedem a classificacao.");
            }
        }

        if (Schema::hasTable('conta_azul_reconciliation_states')) {
            $duplicado = DB::table('conta_azul_reconciliation_states')
                ->select('recurso')
                ->whereNull('loja_id')
                ->groupBy('recurso')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicado) {
                throw new RuntimeException('Existem estados de reconciliacao duplicados que impedem a classificacao.');
            }
        }
    }

    private function tokenFingerprint(int $connectionId): string
    {
        $token = DB::table('conta_azul_tokens')
            ->where('conexao_id', $connectionId)
            ->first();

        if (! $token) {
            throw new RuntimeException('Token da conexao legada nao encontrado.');
        }

        return hash('sha256', serialize((array) $token));
    }

    private function exibirResumo(array $resumo): void
    {
        $this->line(json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
