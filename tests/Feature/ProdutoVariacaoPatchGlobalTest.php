<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoPatchGlobalTest extends TestCase
{
    private function autenticar(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Patch Variacao',
            'email' => 'patch.variacao.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }

    private function criarProdutoVariacaoComCarrinhos(): array
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Cat Patch',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Patch',
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
            'nome' => 'Produto Patch',
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

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'PATCH-001',
            'nome' => 'Var Patch',
            'preco' => 100.00,
            'custo' => 30.00,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $usuarioCarrinho = DB::table('acesso_usuarios')->insertGetId([
            'nome' => 'Usuario Carrinho',
            'email' => 'usuario.carrinho.' . uniqid() . '@example.test',
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
                'outlet_id' => 999,
                'id_deposito' => null,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'subtotal' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        return [$variacaoId, $carrinhoRascunhoId, $carrinhoFinalizadoId];
    }

    public function test_patch_preco_exige_audit_motivo(): void
    {
        $this->autenticar(['produto_variacoes.editar']);
        [$variacaoId] = $this->criarProdutoVariacaoComCarrinhos();

        $response = $this->patchJson("/api/v1/produto-variacoes/{$variacaoId}", [
            'preco' => 120.00,
            'audit' => [
                'origin' => 'checkout',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.audit.motivo.0', 'O motivo é obrigatório para alteração de preço.');
    }

    public function test_patch_preco_cria_auditoria_e_sincroniza_carrinho_rascunho(): void
    {
        $this->autenticar(['produto_variacoes.editar']);
        [$variacaoId, $carrinhoRascunhoId, $carrinhoFinalizadoId] = $this->criarProdutoVariacaoComCarrinhos();

        $response = $this->patchJson("/api/v1/produto-variacoes/{$variacaoId}", [
            'preco' => 150.00,
            'audit' => [
                'label' => 'Alteração de preço no checkout',
                'motivo' => 'Cliente VIP',
                'origin' => 'checkout',
                'metadata' => [
                    'carrinho_id' => $carrinhoRascunhoId,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('preco', '150.00');

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'produto_variacoes',
            'action' => 'update',
            'label' => 'Alteração de preço no checkout',
            'auditable_id' => $variacaoId,
        ]);

        $eventoId = DB::table('auditoria_eventos')
            ->where('module', 'produto_variacoes')
            ->where('auditable_id', $variacaoId)
            ->latest('id')
            ->value('id');

        $this->assertDatabaseHas('auditoria_mudancas', [
            'evento_id' => $eventoId,
            'campo' => 'preco',
            'old_value' => '100',
            'new_value' => '150',
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinhoRascunhoId,
            'id_variacao' => $variacaoId,
            'outlet_id' => null,
            'preco_unitario' => 150.00,
            'subtotal' => 300.00,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinhoFinalizadoId,
            'id_variacao' => $variacaoId,
            'outlet_id' => null,
            'preco_unitario' => 100.00,
            'subtotal' => 200.00,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinhoRascunhoId,
            'id_variacao' => $variacaoId,
            'outlet_id' => 999,
            'preco_unitario' => 100.00,
            'subtotal' => 100.00,
        ]);
    }

    public function test_patch_bloqueia_sem_permissao(): void
    {
        $this->autenticar([]);
        [$variacaoId] = $this->criarProdutoVariacaoComCarrinhos();

        $this->patchJson("/api/v1/produto-variacoes/{$variacaoId}", [
            'referencia' => 'SEM-PERM',
        ])->assertForbidden();
    }
}
