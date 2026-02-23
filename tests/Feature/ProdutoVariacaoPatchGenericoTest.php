<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoPatchGenericoTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_preco_exige_audit_motivo(): void
    {
        $this->autenticarComPermissoes(['produto_variacoes.editar']);
        $variacao = $this->criarVariacao();

        $response = $this->patchJson("/api/v1/produto-variacoes/{$variacao->id}", [
            'preco' => 139.90,
            'audit' => [
                'origin' => 'checkout',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audit.motivo']);
    }

    public function test_patch_preco_cria_auditoria_e_mudanca(): void
    {
        $usuario = $this->autenticarComPermissoes(['produto_variacoes.editar']);
        $variacao = $this->criarVariacao(100.00);

        $this->patchJson("/api/v1/produto-variacoes/{$variacao->id}", [
            'preco' => 150.00,
            'audit' => [
                'label' => 'Alteração de preço no checkout',
                'motivo' => 'Negociação com cliente',
                'origin' => 'checkout',
                'metadata' => [
                    'carrinho_id' => 12,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'produto_variacoes',
            'action' => 'UPDATE',
            'auditable_type' => 'ProdutoVariacao',
            'auditable_id' => $variacao->id,
            'actor_id' => $usuario->id,
            'label' => 'Alteração de preço no checkout',
        ]);

        $eventoId = \DB::table('auditoria_eventos')
            ->where('module', 'produto_variacoes')
            ->where('auditable_id', $variacao->id)
            ->latest('id')
            ->value('id');

        $this->assertDatabaseHas('auditoria_mudancas', [
            'evento_id' => $eventoId,
            'field' => 'preco',
        ]);
    }

    public function test_patch_preco_sincroniza_carrinhos_rascunho_sem_outlet(): void
    {
        $usuario = $this->autenticarComPermissoes(['produto_variacoes.editar']);
        $variacao = $this->criarVariacao(200.00);

        $carrinhoRascunho = \DB::table('carrinhos')->insertGetId([
            'status' => 'rascunho',
            'id_usuario' => $usuario->id,
            'id_cliente' => null,
            'id_parceiro' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $carrinhoFinalizado = \DB::table('carrinhos')->insertGetId([
            'status' => 'finalizado',
            'id_usuario' => $usuario->id,
            'id_cliente' => null,
            'id_parceiro' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemRascunhoId = \DB::table('carrinho_itens')->insertGetId([
            'id_carrinho' => $carrinhoRascunho,
            'id_variacao' => $variacao->id,
            'outlet_id' => null,
            'id_deposito' => null,
            'quantidade' => 2,
            'preco_unitario' => 200.00,
            'subtotal' => 400.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $motivoId = \DB::table('outlet_motivos')->insertGetId([
            'slug' => 'motivo-sync-teste-' . uniqid(),
            'nome' => 'Motivo Sync Teste',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outletId = \DB::table('produto_variacao_outlets')->insertGetId([
            'produto_variacao_id' => $variacao->id,
            'motivo_id' => $motivoId,
            'quantidade' => 2,
            'quantidade_restante' => 2,
            'usuario_id' => $usuario->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemOutletId = \DB::table('carrinho_itens')->insertGetId([
            'id_carrinho' => $carrinhoRascunho,
            'id_variacao' => $variacao->id,
            'outlet_id' => $outletId,
            'id_deposito' => null,
            'quantidade' => 2,
            'preco_unitario' => 180.00,
            'subtotal' => 360.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemFinalizadoId = \DB::table('carrinho_itens')->insertGetId([
            'id_carrinho' => $carrinhoFinalizado,
            'id_variacao' => $variacao->id,
            'outlet_id' => null,
            'id_deposito' => null,
            'quantidade' => 2,
            'preco_unitario' => 200.00,
            'subtotal' => 400.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/produto-variacoes/{$variacao->id}", [
            'preco' => 99.90,
            'audit' => [
                'motivo' => 'Ajuste comercial',
                'origin' => 'checkout',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $itemRascunhoId,
            'preco_unitario' => 99.90,
            'subtotal' => 199.80,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $itemOutletId,
            'preco_unitario' => 180.00,
            'subtotal' => 360.00,
        ]);

        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $itemFinalizadoId,
            'preco_unitario' => 200.00,
            'subtotal' => 400.00,
        ]);
    }

    public function test_sem_permissao_retorna_403(): void
    {
        $this->autenticarComPermissoes(['avisos.view']);
        $variacao = $this->criarVariacao();

        $this->patchJson("/api/v1/produto-variacoes/{$variacao->id}", [
            'preco' => 120,
            'audit' => ['motivo' => 'Teste'],
        ])->assertStatus(403);
    }

    private function autenticarComPermissoes(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Patch',
            'email' => 'variacao.patch.' . uniqid() . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put("permissoes_usuario_{$usuario->id}", $permissoes, now()->addHour());

        return $usuario;
    }

    private function criarVariacao(float $preco = 100.00): ProdutoVariacao
    {
        $categoria = Categoria::create(['nome' => 'Categoria Patch']);
        $produto = Produto::create([
            'nome' => 'Produto Patch',
            'descricao' => null,
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        return ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-PATCH-' . uniqid(),
            'nome' => 'Variacao Patch',
            'preco' => $preco,
            'custo' => 50,
        ]);
    }
}
