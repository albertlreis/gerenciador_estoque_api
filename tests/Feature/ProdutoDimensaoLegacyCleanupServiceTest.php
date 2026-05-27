<?php

namespace Tests\Feature;

use App\Services\ProdutoDimensaoLegacyCleanupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProdutoDimensaoLegacyCleanupServiceTest extends TestCase
{
    private function criarVariacao(array $dimensoes = []): int
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Dimensoes ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => 'Produto Dimensoes ' . uniqid(),
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => null,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-DIM-' . uniqid(),
            'sku_interno' => null,
            'chave_variacao' => null,
            'nome' => 'Variacao Dimensoes',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'dimensao_1' => $dimensoes['dimensao_1'] ?? null,
            'dimensao_2' => $dimensoes['dimensao_2'] ?? null,
            'dimensao_3' => $dimensoes['dimensao_3'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function criarAtributo(int $variacaoId, string $atributo, string $valor): int
    {
        $now = now();

        return DB::table('produto_variacao_atributos')->insertGetId([
            'id_variacao' => $variacaoId,
            'atributo' => $atributo,
            'valor' => $valor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_promove_dimensoes_legadas_corrige_campos_e_preserva_atributos_reais(): void
    {
        $variacaoId = $this->criarVariacao([
            'dimensao_1' => 99,
            'dimensao_3' => 70,
        ]);

        $this->criarAtributo($variacaoId, 'largura_cm', '120');
        $this->criarAtributo($variacaoId, 'profundidade_cm', '49');
        $this->criarAtributo($variacaoId, 'altura_cm', '85');
        $this->criarAtributo($variacaoId, 'madeira', 'AC03');

        app(ProdutoDimensaoLegacyCleanupService::class)->executar();

        $variacao = DB::table('produto_variacoes')->where('id', $variacaoId)->first();

        $this->assertSame(120.0, (float) $variacao->dimensao_1);
        $this->assertSame(49.0, (float) $variacao->dimensao_2);
        $this->assertSame(85.0, (float) $variacao->dimensao_3);

        foreach (['largura_cm', 'profundidade_cm', 'altura_cm'] as $atributo) {
            $this->assertDatabaseMissing('produto_variacao_atributos', [
                'id_variacao' => $variacaoId,
                'atributo' => $atributo,
            ]);
        }

        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'madeira',
            'valor' => 'AC03',
        ]);

        $logId = DB::table('auditoria_logs')
            ->where('modulo', 'produto_variacoes')
            ->where('acao', 'corrigido_atributo_venceu')
            ->where('entity_id', (string) $variacaoId)
            ->where('context_json->atributo_legado', 'largura_cm')
            ->value('id');

        $this->assertNotNull($logId);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'dimensao_1',
            'old_value' => '99.00',
            'new_value' => '120.00',
        ]);
    }

    public function test_preserva_alias_dimensional_divergente_para_evitar_perda_de_dados(): void
    {
        $variacaoId = $this->criarVariacao();

        $this->criarAtributo($variacaoId, 'dimensao_1', '120');
        $this->criarAtributo($variacaoId, 'largura_cm', '130');

        app(ProdutoDimensaoLegacyCleanupService::class)->executar();

        $variacao = DB::table('produto_variacoes')->where('id', $variacaoId)->first();

        $this->assertSame(120.0, (float) $variacao->dimensao_1);
        $this->assertDatabaseMissing('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'dimensao_1',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'largura_cm',
            'valor' => '130',
        ]);
        $logId = DB::table('auditoria_logs')
            ->where('modulo', 'produto_variacoes')
            ->where('acao', 'bloqueado_conflito_alias')
            ->where('entity_id', (string) $variacaoId)
            ->where('context_json->atributo_legado', 'largura_cm')
            ->value('id');

        $this->assertNotNull($logId);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'dimensao_1',
            'new_value' => '120.00',
        ]);

        app(ProdutoDimensaoLegacyCleanupService::class)->executar();
        $variacaoAposSegundaExecucao = DB::table('produto_variacoes')->where('id', $variacaoId)->first();

        $this->assertSame(120.0, (float) $variacaoAposSegundaExecucao->dimensao_1);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'largura_cm',
            'valor' => '130',
        ]);
    }
}
