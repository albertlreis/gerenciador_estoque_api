<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContaAzulStagingScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_deduplica_financeiro_limpa_nao_financeiro_e_preserva_mapeamentos(): void
    {
        $migration = require database_path('migrations/2026_07_15_130000_normalize_conta_azul_staging_scope.php');

        DB::table('stg_conta_azul_financeiro')->insert(
            $this->stagingRow('titulo-1', ['descricao' => 'Conta a receber preservada'])
        );
        DB::table('stg_conta_azul_produtos')->insert(
            $this->stagingRow('produto-1', ['nome' => 'Produto temporario'])
        );
        DB::table('stg_conta_azul_vendas')->insert(
            $this->stagingRow('venda-1', ['numero' => 'VENDA-1'])
        );
        DB::table('conta_azul_mapeamentos')->insert([
            'loja_id' => null,
            'tipo_entidade' => 'venda',
            'id_local' => 123,
            'id_externo' => 'venda-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('stg_conta_azul_financeiro', 'loja_scope_id'));
        $this->assertSame(1, DB::table('stg_conta_azul_financeiro')->where('identificador_externo', 'titulo-1')->count());
        $payload = json_decode(
            DB::table('stg_conta_azul_financeiro')->where('identificador_externo', 'titulo-1')->value('payload_json'),
            true
        );
        $this->assertSame('Conta a receber preservada', $payload['descricao']);
        $this->assertSame(0, DB::table('stg_conta_azul_produtos')->count());
        $this->assertSame(0, DB::table('stg_conta_azul_vendas')->count());
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => 'venda',
            'id_local' => 123,
            'id_externo' => 'venda-1',
        ]);

        $this->expectException(QueryException::class);
        DB::table('stg_conta_azul_financeiro')->insert(
            $this->stagingRow('titulo-1', ['descricao' => 'Duplicata bloqueada'])
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stagingRow(string $externalId, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return [
            'loja_id' => null,
            'identificador_externo' => $externalId,
            'payload_json' => $json,
            'hash_payload' => hash('sha256', $json),
            'status_conciliacao' => 'novo',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
