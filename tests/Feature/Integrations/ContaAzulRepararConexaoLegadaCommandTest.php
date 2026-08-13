<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Models\AuditoriaLog;
use App\Models\Loja;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContaAzulRepararConexaoLegadaCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const NOME_LOJA = 'G. P COMERCIO VAREJISTA DE MOVEIS LTDA';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'stg_conta_azul_notas',
            'stg_conta_azul_baixas',
            'stg_conta_azul_parcelas',
            'stg_conta_azul_contas_pagar',
            'stg_conta_azul_financeiro',
            'stg_conta_azul_vendas',
            'stg_conta_azul_produtos',
            'stg_conta_azul_pessoas',
            'stg_conta_azul_formas_pagamento',
            'stg_conta_azul_saldos_contas_financeiras',
            'stg_conta_azul_centros_custo',
            'stg_conta_azul_categorias_financeiras',
            'stg_conta_azul_contas_financeiras',
            'conta_azul_sync_logs',
            'conta_azul_import_batches',
            'conta_azul_cobrancas',
            'conta_azul_reconciliation_states',
            'conta_azul_mapeamentos',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        if (Schema::hasTable('notas_fiscais')) {
            DB::table('notas_fiscais')->where('origem', 'conta_azul')->delete();
        }
        if (Schema::hasTable('auditoria_logs')) {
            DB::table('auditoria_logs')->where('modulo', 'conta_azul')->delete();
        }

        DB::table('conta_azul_tokens')->delete();
        DB::table('conta_azul_conexoes')->delete();
        DB::table('lojas')->delete();
    }

    public function test_dry_run_execucao_e_repeticao_preservam_token_e_classificam_historico(): void
    {
        $conexao = $this->legacyConnectionWithToken();
        $this->seedLegacyHistory();
        $tokenAntes = DB::table('conta_azul_tokens')->where('conexao_id', $conexao->id)->first();

        $this->artisan('conta-azul:reparar-conexao-legada', [
            '--connection' => $conexao->id,
            '--nome' => self::NOME_LOJA,
        ])->assertSuccessful();

        $this->assertDatabaseCount('lojas', 0);
        $this->assertDatabaseHas('conta_azul_conexoes', ['id' => $conexao->id, 'loja_id' => null]);

        $this->artisan('conta-azul:reparar-conexao-legada', [
            '--connection' => $conexao->id,
            '--nome' => self::NOME_LOJA,
            '--execute' => true,
        ])->assertSuccessful();

        $loja = Loja::query()->sole();
        $this->assertSame(self::NOME_LOJA, $loja->nome);
        $this->assertTrue($loja->ativo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $loja->codigo);
        $this->assertDatabaseHas('conta_azul_conexoes', ['id' => $conexao->id, 'loja_id' => $loja->id]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', ['id_externo' => 'pessoa-legada', 'loja_id' => $loja->id]);
        $this->assertDatabaseHas('stg_conta_azul_pessoas', ['identificador_externo' => 'pessoa-legada', 'loja_id' => $loja->id]);
        $this->assertDatabaseHas('notas_fiscais', ['chave_acesso' => 'nota-legada', 'loja_id' => $loja->id]);
        $this->assertSame(
            $loja->id,
            (int) DB::table('auditoria_logs')->where('message', 'historico legado')->value('context_json->loja_id')
        );

        $tokenDepois = DB::table('conta_azul_tokens')->where('conexao_id', $conexao->id)->first();
        $this->assertSame((array) $tokenAntes, (array) $tokenDepois);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'classificar_conexao_legada',
            'status' => 'concluido',
        ]);

        $this->artisan('conta-azul:reparar-conexao-legada', [
            '--connection' => $conexao->id,
            '--nome' => self::NOME_LOJA,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('lojas', 1);
        $this->assertSame(1, AuditoriaLog::query()->where('acao', 'classificar_conexao_legada')->count());
    }

    public function test_recusa_conflito_de_unicidade_sem_gravar(): void
    {
        $conexao = $this->legacyConnectionWithToken();
        DB::table('conta_azul_reconciliation_states')->insert([
            ['loja_id' => null, 'recurso' => 'financeiro', 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()],
            ['loja_id' => null, 'recurso' => 'financeiro', 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('conta-azul:reparar-conexao-legada', [
            '--connection' => $conexao->id,
            '--nome' => self::NOME_LOJA,
            '--execute' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('lojas', 0);
        $this->assertDatabaseHas('conta_azul_conexoes', ['id' => $conexao->id, 'loja_id' => null]);
    }

    public function test_recusa_quando_ja_existe_loja_ou_mais_de_uma_conexao(): void
    {
        $conexao = $this->legacyConnectionWithToken();
        Loja::query()->create(['codigo' => 'existente', 'nome' => 'Loja existente', 'ativo' => true]);

        $this->artisan('conta-azul:reparar-conexao-legada', [
            '--connection' => $conexao->id,
            '--nome' => self::NOME_LOJA,
        ])->assertFailed();

        Loja::query()->delete();
        ContaAzulConexao::query()->create(['loja_id' => null, 'status' => 'inativa', 'ambiente' => 'producao']);

        $this->artisan('conta-azul:reparar-conexao-legada', [
            '--connection' => $conexao->id,
            '--nome' => self::NOME_LOJA,
        ])->assertFailed();

        $this->assertDatabaseCount('lojas', 0);
        $this->assertDatabaseHas('conta_azul_conexoes', ['id' => $conexao->id, 'loja_id' => null]);
    }

    private function legacyConnectionWithToken(): ContaAzulConexao
    {
        $conexao = ContaAzulConexao::query()->create([
            'loja_id' => null,
            'status' => 'ativa',
            'ambiente' => 'producao',
        ]);
        ContaAzulToken::query()->create([
            'conexao_id' => $conexao->id,
            'access_token' => 'access-token-legado',
            'refresh_token' => 'refresh-token-legado',
            'expires_at' => now()->addHour(),
            'scope' => 'financeiro',
            'ultimo_refresh_em' => now(),
        ]);

        return $conexao;
    }

    private function seedLegacyHistory(): void
    {
        DB::table('conta_azul_mapeamentos')->insert([
            'loja_id' => null,
            'tipo_entidade' => 'pessoa',
            'id_local' => 1,
            'id_externo' => 'pessoa-legada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stg_conta_azul_pessoas')->insert([
            'loja_id' => null,
            'identificador_externo' => 'pessoa-legada',
            'payload_json' => json_encode(['nome' => 'Pessoa legada'], JSON_THROW_ON_ERROR),
            'hash_payload' => hash('sha256', 'pessoa-legada'),
            'status_conciliacao' => 'novo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notas_fiscais')->insert([
            'loja_id' => null,
            'chave_acesso' => 'nota-legada',
            'origem' => 'conta_azul',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditoriaLog::query()->create([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'info',
            'modulo' => 'conta_azul',
            'acao' => 'import',
            'status' => 'sucesso',
            'message' => 'historico legado',
            'source_system' => 'estoque',
            'source_kind' => 'legacy_table',
            'retention_days' => 365,
        ]);
    }
}
