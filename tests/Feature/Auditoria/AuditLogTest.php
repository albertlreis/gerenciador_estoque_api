<?php

namespace Tests\Feature\Auditoria;

use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(array $permissoes = []): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Auditoria',
            'email' => 'auditoria.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }

    public function test_atualizacao_de_variacao_gera_audit_log_com_before_after(): void
    {
        $usuario = $this->autenticar(['produto_variacoes.editar']);

        $categoria = Categoria::create(['nome' => 'Categoria Auditoria']);
        $produto = Produto::create([
            'nome' => 'Produto Auditoria',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'AUD-001',
            'nome' => 'Variacao Auditoria',
            'preco' => 100,
            'custo' => 40,
        ]);

        $response = $this->putJson("/api/v1/produtos/{$produto->id}/variacoes/{$variacao->id}", [
            'referencia' => 'AUD-002',
            'preco' => 125,
            'custo' => 55,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $usuario->id,
            'action' => 'updated',
            'auditable_type' => ProdutoVariacao::class,
            'auditable_id' => $variacao->id,
            'method' => 'PUT',
        ]);

        $log = \DB::table('audit_logs')
            ->where('auditable_type', ProdutoVariacao::class)
            ->where('auditable_id', $variacao->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $oldValues = json_decode((string) $log->old_values, true);
        $newValues = json_decode((string) $log->new_values, true);

        $this->assertSame('AUD-001', $oldValues['referencia']);
        $this->assertSame('AUD-002', $newValues['referencia']);
        $this->assertSame(125, $newValues['preco']);
        $this->assertSame('variacoes.update', $log->route);
    }

    public function test_finalizacao_de_pedido_gera_logs_de_created_e_finalized(): void
    {
        $usuario = $this->autenticar([
            'pedidos.visualizar',
            'carrinhos.finalizar',
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Auditoria',
            'documento' => '12345678901',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Pedido']);
        $produto = Produto::create([
            'nome' => 'Produto Pedido',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'PED-AUD-001',
            'nome' => 'Variacao Pedido',
            'preco' => 220,
            'custo' => 150,
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
            'preco_unitario' => 220,
            'subtotal' => 220,
        ]);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'observacoes' => 'Pedido auditado',
            'registrar_movimentacao' => false,
        ]);

        $response->assertStatus(201);

        $pedidoId = (int) (data_get($response->json(), 'pedido.id') ?? data_get($response->json(), 'data.id'));

        $this->assertNotSame(0, $pedidoId);
        $this->assertDatabaseHas('pedidos', ['id' => $pedidoId]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $usuario->id,
            'action' => 'created',
            'auditable_type' => Pedido::class,
            'auditable_id' => $pedidoId,
            'method' => 'POST',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $usuario->id,
            'action' => 'finalized',
            'auditable_type' => Pedido::class,
            'auditable_id' => $pedidoId,
            'method' => 'POST',
        ]);

        $finalizedLog = \DB::table('audit_logs')
            ->where('auditable_type', Pedido::class)
            ->where('auditable_id', $pedidoId)
            ->where('action', 'finalized')
            ->latest('id')
            ->first();

        $newValues = json_decode((string) $finalizedLog->new_values, true);

        $this->assertFalse($newValues['modo_consignacao']);
        $this->assertFalse($newValues['registrar_movimentacao']);
        $this->assertCount(1, $newValues['itens']);
        $this->assertStringContainsString('pedidos', (string) $finalizedLog->route);
    }
}
