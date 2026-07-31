<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\ConsignacaoDevolucao;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Http\Controllers\ConsignacaoController;
use App\Http\Controllers\PedidoController;
use Illuminate\Http\Request;
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

    public function test_roteiro_de_devolucao_mostra_apenas_o_saldo_ainda_com_o_cliente_nos_dois_endpoints(): void
    {
        [$pedidoId, $consignacao, $deposito] = $this->criarPedidoConsignado(
            'parcial',
            PedidoStatus::DEVOLUCAO_CONSIGNACAO,
            2
        );

        ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'origem_id' => $consignacao->id,
            'pedido_id' => $pedidoId,
            'consignacao_id' => $consignacao->id,
            'id_variacao' => $consignacao->produto_variacao_id,
            'quantidade_total' => 2,
            'quantidade_expedida' => 2,
            'id_deposito_origem' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);

        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
        ]);

        $pedido = Pedido::with(['consignacoes.deposito', 'consignacoes.devolucoes', 'consignacoes.entregaItem'])
            ->findOrFail($pedidoId);
        $request = Request::create('/', 'GET', [
            'destinos_devolucao' => [$consignacao->id => $deposito->id],
        ]);

        foreach ([ConsignacaoController::class, PedidoController::class] as $controller) {
            $method = new \ReflectionMethod($controller, 'gruposRoteiroConsignacao');
            $method->setAccessible(true);
            $grupos = $method->invoke(app($controller), $pedido, true, $request);

            $item = $grupos->flatten(1)->sole();
            $this->assertSame(1, (int) $item->quantidade_roteiro);
        }
    }

    public function test_template_usa_data_uri_da_imagem_e_placeholder_quando_necessario(): void
    {
        [, $consignacao, $deposito] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        $consignacao->load(['produtoVariacao.produto', 'produtoVariacao.estoquesComLocalizacao']);
        $consignacao->setAttribute('pdf_imagem_data_uri', 'data:image/png;base64,aGVsbG8=');

        $html = view('exports.roteiro-consignacao', [
            'pedido' => $consignacao->pedido()->with(['cliente', 'usuario', 'parceiro'])->firstOrFail(),
            'grupos' => collect([$deposito->nome => collect([$consignacao])]),
            'tituloRoteiro' => 'Roteiro de consignação',
        ])->render();

        $this->assertStringContainsString('src="data:image/png;base64,aGVsbG8="', $html);
    }

    public function test_roteiro_de_devolucao_omite_item_totalmente_devolvido_e_consignacao_normal_mantem_quantidade_original(): void
    {
        [$pedidoId, $consignacao, $deposito] = $this->criarPedidoConsignado(
            'devolvido',
            PedidoStatus::DEVOLUCAO_CONSIGNACAO,
            2
        );

        ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'origem_id' => $consignacao->id,
            'pedido_id' => $pedidoId,
            'consignacao_id' => $consignacao->id,
            'id_variacao' => $consignacao->produto_variacao_id,
            'quantidade_total' => 2,
            'quantidade_expedida' => 2,
            'id_deposito_origem' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);
        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
        ]);

        $pedido = Pedido::with(['consignacoes.deposito', 'consignacoes.devolucoes', 'consignacoes.entregaItem'])
            ->findOrFail($pedidoId);
        $method = new \ReflectionMethod(ConsignacaoController::class, 'gruposRoteiroConsignacao');
        $method->setAccessible(true);

        $devolucao = $method->invoke(
            app(ConsignacaoController::class),
            $pedido,
            true,
            Request::create('/', 'GET', ['destinos_devolucao' => [$consignacao->id => $deposito->id]])
        );
        $consignacaoNormal = $method->invoke(
            app(ConsignacaoController::class),
            $pedido,
            false,
            Request::create('/', 'GET')
        );

        $this->assertTrue($devolucao->isEmpty());
        $this->assertSame(2, (int) $consignacaoNormal->flatten(1)->sole()->quantidade);
    }

    private function criarPedidoConsignado(string $statusConsignacao, PedidoStatus $statusPedido, int $quantidade = 1): array
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
            'quantidade' => $quantidade,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => $statusConsignacao,
        ]);

        return [$pedido->id, $consignacao, $deposito];
    }
}
