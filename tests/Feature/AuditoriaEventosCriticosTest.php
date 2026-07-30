<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditoriaEventosCriticosTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualizar_preco_da_variacao_gera_evento_e_mudanca(): void
    {
        $usuario = $this->autenticarUsuario(['produto_variacoes.editar']);
        [$produto, $variacao] = $this->criarProdutoComVariacao();

        $response = $this->putJson("/api/v1/produtos/{$produto->id}/variacoes/{$variacao->id}", [
            'preco' => 199.90,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'catalogo',
            'action' => 'UPDATE',
            'auditable_type' => 'ProdutoVariacao',
            'auditable_id' => $variacao->id,
            'actor_id' => $usuario->id,
        ]);

        $eventoId = \DB::table('auditoria_eventos')
            ->where('module', 'catalogo')
            ->where('action', 'UPDATE')
            ->where('auditable_type', 'ProdutoVariacao')
            ->where('auditable_id', $variacao->id)
            ->orderByDesc('id')
            ->value('id');

        $this->assertNotNull($eventoId);
        $this->assertDatabaseHas('auditoria_mudancas', [
            'evento_id' => $eventoId,
            'field' => 'preco',
        ]);
    }

    public function test_cancelar_status_de_pedido_gera_evento_cancel(): void
    {
        $usuario = $this->autenticarUsuario();

        $cliente = Cliente::create([
            'nome' => 'Cliente Auditoria',
            'documento' => '12345678900',
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-AUD-001',
            'data_pedido' => now(),
            'valor_total' => 100,
            'prazo_dias_uteis' => 10,
        ]);

        $status = PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => 'pedido_criado',
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);

        $response = $this->deleteJson("/api/v1/pedidos/{$pedido->id}/status-historicos/{$status->id}", [
            'motivo' => 'cancelamento de teste',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'pedidos',
            'action' => 'CANCEL',
            'auditable_type' => 'Pedido',
            'auditable_id' => $pedido->id,
            'actor_id' => $usuario->id,
        ]);
    }

    public function test_criar_movimentacao_de_estoque_gera_evento_create(): void
    {
        $usuario = $this->autenticarUsuario();
        [, $variacao] = $this->criarProdutoComVariacao();
        $deposito = Deposito::create(['nome' => 'Deposito Auditoria']);

        $response = $this->postJson('/api/v1/estoque/movimentacoes', [
            'id_variacao' => $variacao->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => 'entrada',
            'quantidade' => 3,
            'observacao' => 'movimentacao de auditoria',
        ]);

        $response->assertCreated();

        $movimentacaoId = \DB::table('estoque_movimentacoes')->max('id');
        $this->assertNotNull($movimentacaoId);

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'estoque',
            'action' => 'CREATE',
            'auditable_type' => 'EstoqueMovimentacao',
            'auditable_id' => $movimentacaoId,
            'actor_id' => $usuario->id,
        ]);
    }

    public function test_baixa_e_estorno_financeiro_geram_eventos(): void
    {
        $usuario = $this->autenticarUsuario();

        $contaFinanceira = ContaFinanceira::create([
            'nome' => 'Conta Auditoria',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => true,
            'saldo_inicial' => 0,
        ]);

        $create = $this->postJson('/api/v1/financeiro/contas-receber', [
            'descricao' => 'Conta teste auditoria',
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_bruto' => 150.00,
        ]);

        $create->assertCreated();
        $contaId = $create->json('data.id');
        $this->assertNotNull($contaId);

        $pagar = $this->postJson("/api/v1/financeiro/contas-receber/{$contaId}/pagar", [
            'data_pagamento' => now()->toDateString(),
            'valor' => 50.00,
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);

        $pagar->assertOk();

        $pagamentoId = \DB::table('contas_receber_pagamentos')
            ->where('conta_receber_id', $contaId)
            ->value('id');
        $this->assertNotNull($pagamentoId);

        $estorno = $this->deleteJson("/api/v1/financeiro/contas-receber/{$contaId}/pagamentos/{$pagamentoId}");
        $estorno->assertOk();

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'financeiro',
            'action' => 'STATUS_CHANGE',
            'auditable_type' => 'ContaReceber',
            'auditable_id' => $contaId,
            'actor_id' => $usuario->id,
        ]);

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'financeiro',
            'action' => 'REVERSAL',
            'auditable_type' => 'ContaReceber',
            'auditable_id' => $contaId,
            'actor_id' => $usuario->id,
        ]);
    }

    private function autenticarUsuario(array $permissoes = []): Usuario
    {
        $this->garantirTabelasPermissao();

        $usuario = Usuario::create([
            'nome' => 'Usuario Auditoria',
            'email' => 'auditoria.' . uniqid() . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put("permissoes_usuario_{$usuario->id}", $permissoes, now()->addHour());

        return $usuario;
    }

    private function criarProdutoComVariacao(): array
    {
        $categoria = Categoria::create(['nome' => 'Categoria Auditoria']);

        $produto = Produto::create([
            'nome' => 'Produto Auditoria',
            'descricao' => 'Produto para testes',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-AUD-' . uniqid(),
            'nome' => 'Variacao Auditoria',
            'preco' => 100,
            'custo' => 50,
        ]);

        return [$produto, $variacao];
    }

    private function garantirTabelasPermissao(): void
    {
        if (!Schema::hasTable('acesso_permissoes')) {
            Schema::create('acesso_permissoes', function ($table) {
                $table->id();
                $table->string('slug')->nullable();
            });
        }

        if (!Schema::hasTable('acesso_usuario_perfil')) {
            Schema::create('acesso_usuario_perfil', function ($table) {
                $table->unsignedBigInteger('id_usuario')->nullable();
                $table->unsignedBigInteger('id_perfil')->nullable();
            });
        }

        if (!Schema::hasTable('acesso_perfil_permissao')) {
            Schema::create('acesso_perfil_permissao', function ($table) {
                $table->unsignedBigInteger('id_perfil')->nullable();
                $table->unsignedBigInteger('id_permissao')->nullable();
            });
        }
    }
}
