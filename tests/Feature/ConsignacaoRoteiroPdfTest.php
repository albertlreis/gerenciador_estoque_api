<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsignacaoRoteiroPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_de_consignacao_baixa_com_nome_de_roteiro_de_consignacao(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        $response = $this->get("/api/v1/consignacoes/{$pedidoId}/pdf");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-consignacao-{$pedidoId}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_roteiro_do_pedido_usa_nome_de_devolucao_quando_status_finalizado(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('devolvido', PedidoStatus::DEVOLUCAO_CONSIGNACAO);

        $response = $this->get("/api/v1/pedidos/{$pedidoId}/pdf/roteiro");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-devolucao-{$pedidoId}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }

    private function criarPedidoConsignado(string $statusConsignacao, PedidoStatus $statusPedido): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario PDF',
            'email' => uniqid('pdf-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $cliente = Cliente::create([
            'nome' => 'Cliente PDF',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria PDF']);
        $produto = Produto::create([
            'nome' => 'Produto PDF',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'PDF-001',
            'nome' => 'Variacao PDF',
            'preco' => 150,
            'custo' => 90,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito PDF']);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 150,
            'prazo_dias_uteis' => 15,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now()->subDay(),
            'usuario_id' => $usuario->id,
        ]);

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => $statusPedido,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);

        Consignacao::create([
            'pedido_id' => $pedido->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => $statusConsignacao,
        ]);

        return [$pedido->id];
    }
}
