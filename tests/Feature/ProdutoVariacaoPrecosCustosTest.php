<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoPrecosCustosTest extends TestCase
{
    private function autenticar(array $permissoes = ['produtos.precos_custos']): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Precos Custos',
            'email' => 'precos.custos.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }

    private function criarVariacao(array $overrides = []): int
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => $overrides['categoria_nome'] ?? 'Categoria Precos',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => $overrides['fornecedor_nome'] ?? 'Fornecedor Precos',
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => $overrides['produto_nome'] ?? 'Mesa Lunar',
            'codigo_produto' => $overrides['codigo_produto'] ?? 'ML-001',
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
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

        if (!empty($overrides['produto_imagem_url'])) {
            DB::table('produto_imagens')->insert([
                'id_produto' => $produtoId,
                'url' => $overrides['produto_imagem_url'],
                'principal' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => $overrides['referencia'] ?? 'REF-PRECO-001',
            'sku_interno' => $overrides['sku_interno'] ?? 'SKU-PRECO-001',
            'chave_variacao' => $overrides['chave_variacao'] ?? 'MESA|COR:AZUL',
            'nome' => $overrides['variacao_nome'] ?? 'Azul',
            'preco' => $overrides['preco'] ?? 100,
            'custo' => array_key_exists('custo', $overrides) ? $overrides['custo'] : 60,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produto_variacao_atributos')->insert([
            'id_variacao' => $variacaoId,
            'atributo' => 'cor',
            'valor' => $overrides['cor'] ?? 'Azul',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $depositoId = DB::table('depositos')->insertGetId([
            'nome' => 'Deposito Precos',
            'endereco' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('estoque')->updateOrInsert(
            ['id_variacao' => $variacaoId, 'id_deposito' => $depositoId],
            [
                'quantidade' => $overrides['estoque'] ?? 7,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if (!empty($overrides['outlet_restante']) || array_key_exists('outlet_restante', $overrides)) {
            $motivoId = DB::table('outlet_motivos')->insertGetId([
                'nome' => 'Outlet Precos ' . uniqid(),
                'slug' => 'outlet-precos-' . uniqid(),
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('produto_variacao_outlets')->insert([
                'produto_variacao_id' => $variacaoId,
                'motivo_id' => $motivoId,
                'quantidade' => max(1, (int) $overrides['outlet_restante']),
                'quantidade_restante' => (int) $overrides['outlet_restante'],
                'usuario_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $variacaoId;
    }

    public function test_lista_precos_custos_paginada_com_metricas(): void
    {
        $this->autenticar();
        $this->criarVariacao(['produto_imagem_url' => 'mesa-lunar.jpg']);

        $response = $this->getJson('/api/v1/variacoes/precos-custos?search=Mesa&page=1&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.produto_nome', 'Mesa Lunar')
            ->assertJsonPath('data.0.categoria_nome', 'Categoria Precos')
            ->assertJsonPath('data.0.fornecedor_nome', 'Fornecedor Precos')
            ->assertJsonPath('data.0.preco', 100)
            ->assertJsonPath('data.0.custo', 60)
            ->assertJsonPath('data.0.lucro', 40)
            ->assertJsonPath('data.0.margem_percentual', 40)
            ->assertJsonPath('data.0.estoque_total', 7)
            ->assertJsonPath('data.0.atributos_resumo', 'cor: Azul');

        $this->assertStringContainsString('mesa-lunar.jpg', (string) $response->json('data.0.produto_imagem_url'));
    }

    public function test_filtro_sem_custo_retorna_apenas_variacoes_sem_custo(): void
    {
        $this->autenticar();
        $semCustoId = $this->criarVariacao([
            'produto_nome' => 'Produto Sem Custo',
            'referencia' => 'SEM-CUSTO',
            'sku_interno' => 'SKU-SEM-CUSTO',
            'chave_variacao' => 'SEM-CUSTO',
            'custo' => null,
        ]);
        $this->criarVariacao([
            'produto_nome' => 'Produto Com Custo',
            'referencia' => 'COM-CUSTO',
            'sku_interno' => 'SKU-COM-CUSTO',
            'chave_variacao' => 'COM-CUSTO',
            'custo' => 50,
        ]);

        $response = $this->getJson('/api/v1/variacoes/precos-custos?sem_custo=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $semCustoId);
    }

    public function test_filtro_sem_preco_retorna_apenas_variacoes_sem_preco(): void
    {
        $this->autenticar();
        $semPrecoId = $this->criarVariacao([
            'produto_nome' => 'Produto Sem Preco',
            'referencia' => 'SEM-PRECO',
            'sku_interno' => 'SKU-SEM-PRECO',
            'chave_variacao' => 'SEM-PRECO',
            'preco' => 0,
        ]);
        $this->criarVariacao([
            'produto_nome' => 'Produto Com Preco',
            'referencia' => 'COM-PRECO',
            'sku_interno' => 'SKU-COM-PRECO',
            'chave_variacao' => 'COM-PRECO',
            'preco' => 100,
        ]);

        $response = $this->getJson('/api/v1/variacoes/precos-custos?sem_preco=1');

        $response->assertOk();
        $this->assertContains($semPrecoId, collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains('COM-PRECO', collect($response->json('data'))->pluck('referencia')->all());
    }

    public function test_referencia_exata_nao_usa_like(): void
    {
        $this->autenticar();
        $exataId = $this->criarVariacao([
            'referencia' => 'REF-EXATA',
            'sku_interno' => 'SKU-REF-EXATA',
            'chave_variacao' => 'REF-EXATA',
        ]);
        $this->criarVariacao([
            'referencia' => 'REF-EXATA-A',
            'sku_interno' => 'SKU-REF-EXATA-A',
            'chave_variacao' => 'REF-EXATA-A',
        ]);

        $response = $this->getJson('/api/v1/variacoes/precos-custos?referencia_exata=REF-EXATA');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $exataId)
            ->assertJsonPath('data.0.referencia', 'REF-EXATA');
    }

    public function test_filtro_outlet_retorna_apenas_outlet_ativo(): void
    {
        $this->autenticar();
        $outletAtivoId = $this->criarVariacao([
            'referencia' => 'OUTLET-ATIVO',
            'sku_interno' => 'SKU-OUTLET-ATIVO',
            'chave_variacao' => 'OUTLET-ATIVO',
            'outlet_restante' => 1,
        ]);
        $this->criarVariacao([
            'referencia' => 'OUTLET-INATIVO',
            'sku_interno' => 'SKU-OUTLET-INATIVO',
            'chave_variacao' => 'OUTLET-INATIVO',
            'outlet_restante' => 0,
        ]);

        $response = $this->getJson('/api/v1/variacoes/precos-custos?outlet=1');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($outletAtivoId, $ids);
        $this->assertNotContains('OUTLET-INATIVO', collect($response->json('data'))->pluck('referencia')->all());
    }

    public function test_filtro_margem_nao_afeta_mais_a_listagem(): void
    {
        $this->autenticar();
        $variacaoId = $this->criarVariacao([
            'referencia' => 'MARGEM-IGNORADA',
            'sku_interno' => 'SKU-MARGEM-IGNORADA',
            'chave_variacao' => 'MARGEM-IGNORADA',
            'preco' => 100,
            'custo' => 99,
        ]);

        $response = $this->getJson('/api/v1/variacoes/precos-custos?referencia_exata=MARGEM-IGNORADA&margem_min=90&margem_max=95');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $variacaoId);
    }

    public function test_bulk_custo_sem_motivo_e_preco_exige_motivo(): void
    {
        $this->autenticar();
        $variacaoId = $this->criarVariacao();

        $this->patchJson('/api/v1/produto-variacoes/precos-custos/bulk', [
            'items' => [
                ['id' => $variacaoId, 'custo' => 70],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'custo' => 70,
        ]);

        $this->patchJson('/api/v1/produto-variacoes/precos-custos/bulk', [
            'items' => [
                ['id' => $variacaoId, 'preco' => 120],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['motivo']);

        $this->patchJson('/api/v1/produto-variacoes/precos-custos/bulk', [
            'motivo' => 'Reajuste tabela Junho/2026',
            'items' => [
                ['id' => $variacaoId, 'preco' => 120],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'preco' => 120,
        ]);

        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'produto_variacoes',
            'acao' => 'update',
            'label' => 'AlteraÃ§Ã£o em lote de preÃ§os e custos',
            'entity_id' => (string) $variacaoId,
        ]);
    }
}
