<?php

namespace Tests\Feature;

use App\Services\Import\ProdutoUpsertService;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoUpdateTest extends TestCase
{
    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.variacao.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
    }

    private function criarProdutoBase(): array
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Teste',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Teste',
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
            'nome' => 'Produto Teste',
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

        return [$produtoId, $now];
    }

    public function test_patch_variacoes_bulk_atualiza_em_lote(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId1 = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OLD-1',
            'nome' => 'Variacao 1',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variacaoId2 = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OLD-2',
            'nome' => 'Variacao 2',
            'preco' => 200,
            'custo' => 80,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = [
            [
                'id' => $variacaoId1,
                'preco' => 150,
                'custo' => 60,
                'referencia' => 'REF-NEW-1',
                'codigo_barras' => '123',
                'ativo' => 0,
                'motivo_desativacao' => 'Fora de linha',
                'audit' => [
                    'motivo' => 'Ajuste de tabela',
                    'origin' => 'cadastro',
                ],
            ],
            [
                'id' => $variacaoId2,
                'preco' => 250,
                'custo' => 90,
                'referencia' => 'REF-NEW-2',
                'codigo_barras' => '456',
                'audit' => [
                    'motivo' => 'Ajuste de tabela',
                    'origin' => 'cadastro',
                ],
            ],
        ];

        $response = $this->patchJson("/api/v1/produtos/{$produtoId}/variacoes/bulk", $payload);

        $response
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId1,
            'referencia' => 'REF-NEW-1',
            'preco' => 150,
            'custo' => 60,
            'ativo' => 0,
            'motivo_desativacao' => 'Fora de linha',
        ]);

        $eventoId = DB::table('auditoria_logs')
            ->where('modulo', 'produto_variacoes')
            ->where('entity_id', (string) $variacaoId1)
            ->latest('id')
            ->value('id');

        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $eventoId,
            'campo' => 'ativo',
        ]);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId2,
            'referencia' => 'REF-NEW-2',
            'preco' => 250,
            'custo' => 90,
        ]);
    }

    public function test_patch_variacoes_bulk_permite_referencia_repetida_em_outra_variacao(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();
        [$outroProdutoId] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-DUP-BULK',
            'nome' => 'Variacao editada',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $outraVariacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $outroProdutoId,
            'referencia' => 'REF-DUP-BULK',
            'nome' => 'Outra variacao',
            'preco' => 80,
            'custo' => 30,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->patchJson("/api/v1/produtos/{$produtoId}/variacoes/bulk", [[
            'id' => $variacaoId,
            'referencia' => 'REF-DUP-BULK',
            'preco' => 100,
            'custo' => 50,
        ]]);

        $response->assertOk()
            ->assertJsonPath('message', 'Variações salvas com sucesso.');

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'referencia' => 'REF-DUP-BULK',
        ]);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $outraVariacaoId,
            'referencia' => 'REF-DUP-BULK',
        ]);
    }

    public function test_patch_variacoes_bulk_permite_sku_interno_repetido(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();
        [$outroProdutoId] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-SKU-ORIG',
            'sku_interno' => 'SKU-ORIG',
            'nome' => 'Variacao editada',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produto_variacoes')->insert([
            'produto_id' => $outroProdutoId,
            'referencia' => 'REF-SKU-DUP',
            'sku_interno' => 'SKU-DUP',
            'nome' => 'Outra variacao',
            'preco' => 80,
            'custo' => 30,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->patchJson("/api/v1/produtos/{$produtoId}/variacoes/bulk", [[
            'id' => $variacaoId,
            'referencia' => 'REF-SKU-ORIG',
            'sku_interno' => 'SKU-DUP',
            'preco' => 100,
            'custo' => 50,
        ]]);

        $response->assertOk()
            ->assertJsonPath('message', 'Variações salvas com sucesso.');

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'sku_interno' => 'SKU-DUP',
        ]);
    }

    public function test_put_variacao_individual_atualiza_campos(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OLD',
            'nome' => 'Variacao',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = [
            'referencia' => 'REF-NEW',
            'preco' => 120,
            'custo' => 55,
            'codigo_barras' => '789',
            'audit' => [
                'motivo' => 'Correção de cadastro',
                'origin' => 'cadastro',
            ],
        ];

        $response = $this->putJson("/api/v1/produtos/{$produtoId}/variacoes/{$variacaoId}", $payload);

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $variacaoId,
                'referencia' => 'REF-NEW',
            ]);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'referencia' => 'REF-NEW',
            'preco' => 120,
            'custo' => 55,
            'codigo_barras' => '789',
        ]);
    }

    public function test_put_variacao_individual_permite_sku_interno_repetido(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();
        [$outroProdutoId] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-PUT-SKU-ORIG',
            'sku_interno' => 'SKU-PUT-ORIG',
            'nome' => 'Variacao put',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produto_variacoes')->insert([
            'produto_id' => $outroProdutoId,
            'referencia' => 'REF-PUT-SKU-DUP',
            'sku_interno' => 'SKU-PUT-DUP',
            'nome' => 'Outra variacao put',
            'preco' => 90,
            'custo' => 30,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->putJson("/api/v1/produtos/{$produtoId}/variacoes/{$variacaoId}", [
            'referencia' => 'REF-PUT-SKU-ORIG',
            'sku_interno' => 'SKU-PUT-DUP',
            'preco' => 100,
            'custo' => 40,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $variacaoId,
                'sku_interno' => 'SKU-PUT-DUP',
            ]);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'sku_interno' => 'SKU-PUT-DUP',
        ]);
    }

    public function test_localizacao_por_sku_interno_repetido_usa_variacao_mais_recente(): void
    {
        [$produtoAntigoId, $now] = $this->criarProdutoBase();
        [$produtoRecenteId] = $this->criarProdutoBase();

        DB::table('produto_variacoes')->insert([
            'produto_id' => $produtoAntigoId,
            'referencia' => 'REF-SKU-MATCH-OLD',
            'sku_interno' => 'SKU-MATCH-DUP',
            'nome' => 'Variacao antiga',
            'preco' => 80,
            'custo' => 30,
            'codigo_barras' => null,
            'created_at' => $now->copy()->subDay(),
            'updated_at' => $now->copy()->subDay(),
        ]);

        $variacaoRecenteId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoRecenteId,
            'referencia' => 'REF-SKU-MATCH-NEW',
            'sku_interno' => 'SKU-MATCH-DUP',
            'nome' => 'Variacao recente',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variacao = app(ProdutoUpsertService::class)->localizarVariacaoPorIdentidade([
            'sku_interno' => 'SKU-MATCH-DUP',
        ]);

        $this->assertSame($variacaoRecenteId, $variacao?->id);
    }

    public function test_post_variacao_rejeita_preco_vazio_e_aceita_zero(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.criar'], now()->addHour());

        [$produtoId] = $this->criarProdutoBase();

        $this->postJson("/api/v1/produtos/{$produtoId}/variacoes", [
            'referencia' => 'REF-SEM-PRECO',
            'preco' => null,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['preco'])
            ->assertJsonFragment(['Informe o preço da variação.']);

        $this->postJson("/api/v1/produtos/{$produtoId}/variacoes", [
            'referencia' => 'REF-PRECO-ZERO',
            'preco' => 0,
        ])->assertCreated()
            ->assertJsonPath('data.preco', 0);
    }

    public function test_patch_variacoes_bulk_exige_motivo_quando_preco_muda(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-MOTIVO-OLD',
            'nome' => 'Variacao motivo',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->patchJson("/api/v1/produtos/{$produtoId}/variacoes/bulk", [[
            'id' => $variacaoId,
            'referencia' => 'REF-MOTIVO-OLD',
            'preco' => 120,
            'custo' => 50,
        ]]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audit.motivo'])
            ->assertJsonFragment(['Informe o motivo da alteração de preço.']);
    }

    public function test_patch_variacoes_bulk_com_motivo_audita_e_sincroniza_carrinho_rascunho(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-BULK-CARRINHO',
            'nome' => 'Variacao carrinho',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $usuarioCarrinho = DB::table('acesso_usuarios')->insertGetId([
            'nome' => 'Usuario Carrinho Bulk',
            'email' => 'usuario.carrinho.bulk.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $carrinhoRascunhoId = DB::table('carrinhos')->insertGetId([
            'status' => 'rascunho',
            'id_usuario' => $usuarioCarrinho,
            'id_cliente' => null,
            'id_parceiro' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $carrinhoFinalizadoId = DB::table('carrinhos')->insertGetId([
            'status' => 'finalizado',
            'id_usuario' => $usuarioCarrinho,
            'id_cliente' => null,
            'id_parceiro' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $motivoOutletId = DB::table('outlet_motivos')->insertGetId([
            'nome' => 'Outlet Bulk',
            'slug' => 'outlet-bulk-' . uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $outletId = DB::table('produto_variacao_outlets')->insertGetId([
            'produto_variacao_id' => $variacaoId,
            'motivo_id' => $motivoOutletId,
            'quantidade' => 1,
            'quantidade_restante' => 1,
            'usuario_id' => $usuarioCarrinho,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('carrinho_itens')->insert([
            [
                'id_carrinho' => $carrinhoRascunhoId,
                'id_variacao' => $variacaoId,
                'outlet_id' => null,
                'id_deposito' => null,
                'quantidade' => 2,
                'preco_unitario' => 100,
                'subtotal' => 200,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id_carrinho' => $carrinhoFinalizadoId,
                'id_variacao' => $variacaoId,
                'outlet_id' => null,
                'id_deposito' => null,
                'quantidade' => 2,
                'preco_unitario' => 100,
                'subtotal' => 200,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id_carrinho' => $carrinhoRascunhoId,
                'id_variacao' => $variacaoId,
                'outlet_id' => $outletId,
                'id_deposito' => null,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'subtotal' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->patchJson("/api/v1/produtos/{$produtoId}/variacoes/bulk", [[
            'id' => $variacaoId,
            'referencia' => 'REF-BULK-CARRINHO',
            'preco' => 150,
            'custo' => 50,
            'audit' => [
                'label' => 'Alteração de preço no cadastro de produtos',
                'motivo' => 'Ajuste de tabela',
                'origin' => 'cadastro',
            ],
        ]]);

        $response->assertOk()
            ->assertJsonPath('message', 'Variações salvas com sucesso.');

        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'produto_variacoes',
            'acao' => 'update',
            'label' => 'Alteração de preço no cadastro de produtos',
            'entity_id' => (string) $variacaoId,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinhoRascunhoId,
            'id_variacao' => $variacaoId,
            'outlet_id' => null,
            'preco_unitario' => 150,
            'subtotal' => 300,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinhoFinalizadoId,
            'id_variacao' => $variacaoId,
            'outlet_id' => null,
            'preco_unitario' => 100,
            'subtotal' => 200,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinhoRascunhoId,
            'id_variacao' => $variacaoId,
            'outlet_id' => $outletId,
            'preco_unitario' => 100,
            'subtotal' => 100,
        ]);
    }

    public function test_put_variacao_individual_exige_motivo_quando_preco_muda(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.editar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-PUT-MOTIVO',
            'nome' => 'Variacao put motivo',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->putJson("/api/v1/produtos/{$produtoId}/variacoes/{$variacaoId}", [
            'referencia' => 'REF-PUT-MOTIVO',
            'preco' => 130,
            'custo' => 40,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audit.motivo'])
            ->assertJsonFragment(['Informe o motivo da alteração de preço.']);
    }
}
