<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\ConsignacaoDevolucao;
use App\Models\Deposito;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Models\Usuario;
use App\Http\Controllers\ConsignacaoController;
use App\Http\Controllers\PedidoController;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsignacaoRoteiroPdfTest extends TestCase
{
    use RefreshDatabase;

    private const WEBP_1X1 = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

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

    public function test_endpoint_de_consignacao_gera_pdf_com_imagem_webp(): void
    {
        Storage::fake('public');
        [$pedidoId, $consignacao] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        Storage::disk('public')->put('produtos/variacoes/roteiro.webp', base64_decode(self::WEBP_1X1));
        ProdutoVariacaoImagem::create([
            'id_variacao' => $consignacao->produto_variacao_id,
            'url' => '/storage/produtos/variacoes/roteiro.webp',
            'principal' => true,
            'ordem' => 0,
        ]);

        $response = $this->get("/api/v1/consignacoes/{$pedidoId}/pdf");

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
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

    public function test_post_devolucao_com_roteiro_persiste_devolucao_e_movimentacao_antes_do_pdf(): void
    {
        [$pedidoId, $consignacao, $deposito] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO, 2);
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

        $response = $this->post("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro", [
            'itens' => [[
                'consignacao_id' => $consignacao->id,
                'quantidade' => 2,
                'deposito_id' => $deposito->id,
            ]],
        ]);

        $response->assertOk();
        $this->assertStringContainsString("roteiro-de-devolucao-{$pedidoId}.pdf", (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('consignacao_devolucoes', [
            'consignacao_id' => $consignacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
        ]);
        $this->assertNotNull(ConsignacaoDevolucao::query()->where('consignacao_id', $consignacao->id)->value('estoque_movimentacao_id'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
    }

    public function test_post_devolucao_com_roteiro_rejeita_item_de_outro_pedido_sem_persistir(): void
    {
        [$pedidoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);
        [, $consignacaoDeOutroPedido, $deposito] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        $response = $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro", [
            'itens' => [[
                'consignacao_id' => $consignacaoDeOutroPedido->id,
                'quantidade' => 1,
                'deposito_id' => $deposito->id,
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('itens');
        $this->assertDatabaseCount('consignacao_devolucoes', 0);
    }

    public function test_post_devolucao_com_roteiro_inclui_historico_selecionado_sem_nova_movimentacao_historica(): void
    {
        [$pedidoId, $consignacao, $deposito] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO, 2);
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
        $consignacaoHistorica = Consignacao::create([
            'pedido_id' => $pedidoId,
            'produto_variacao_id' => $consignacao->produto_variacao_id,
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => 'devolvido',
        ]);
        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacaoHistorica->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
        ]);

        $response = $this->post("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro", [
            'itens' => [[
                'consignacao_id' => $consignacao->id,
                'quantidade' => 1,
                'deposito_id' => $deposito->id,
            ]],
            'consignacao_ids_historico' => [$consignacaoHistorica->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('consignacao_devolucoes', 2);
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
    }

    public function test_post_devolucao_com_roteiro_rejeita_saldo_excedido_item_finalizado_e_deposito_invalido(): void
    {
        [$pedidoId, $consignacao, $deposito] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro", [
            'itens' => [[
                'consignacao_id' => $consignacao->id,
                'quantidade' => 2,
                'deposito_id' => $deposito->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('itens');

        $consignacao->update(['status' => 'devolvido']);
        $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro", [
            'itens' => [[
                'consignacao_id' => $consignacao->id,
                'quantidade' => 1,
                'deposito_id' => $deposito->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('itens');

        $this->postJson("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro", [
            'itens' => [[
                'consignacao_id' => $consignacao->id,
                'quantidade' => 1,
                'deposito_id' => 999999,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('itens.0.deposito_id');
        $this->assertDatabaseCount('consignacao_devolucoes', 0);
    }

    public function test_reimpressao_do_roteiro_usa_todas_as_devolucoes_ativas_sem_criar_movimentacao(): void
    {
        [$pedidoId, $consignacao, $deposito] = $this->criarPedidoConsignado(
            'devolvido',
            PedidoStatus::DEVOLUCAO_CONSIGNACAO,
            4
        );
        $segundoDeposito = Deposito::create(['nome' => 'Depósito Histórico']);
        $segundaConsignacao = Consignacao::create([
            'pedido_id' => $pedidoId,
            'produto_variacao_id' => $consignacao->produto_variacao_id,
            'deposito_id' => $deposito->id,
            'quantidade' => 3,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => 'devolvido',
        ]);

        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
        ]);
        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $segundoDeposito->id,
            'quantidade' => 2,
        ]);
        ConsignacaoDevolucao::create([
            'consignacao_id' => $segundaConsignacao->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $segundoDeposito->id,
            'quantidade' => 3,
        ]);
        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => auth()->id(),
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'cancelada_em' => now(),
        ]);

        $pedido = Pedido::with([
            'consignacoes.deposito',
            'consignacoes.devolucoes.deposito',
            'consignacoes.produtoVariacao.produto',
        ])->findOrFail($pedidoId);
        $method = new \ReflectionMethod(ConsignacaoController::class, 'gruposRoteiroDevolucoesRegistradas');
        $method->setAccessible(true);
        $grupos = $method->invoke(app(ConsignacaoController::class), $pedido->consignacoes);

        $this->assertSame(1, (int) $grupos->get($deposito->nome)->sole()->quantidade_roteiro);
        $this->assertSame(5, (int) $grupos->get($segundoDeposito->nome)->sole()->quantidade_roteiro);

        $quantidadeDevolucoesAntes = ConsignacaoDevolucao::query()->count();
        $quantidadeMovimentacoesAntes = EstoqueMovimentacao::query()->count();
        $response = $this->get("/api/v1/consignacoes/pedidos/{$pedidoId}/devolucoes/roteiro");

        $response->assertOk();
        $this->assertStringContainsString(
            "roteiro-de-devolucao-{$pedidoId}-2-via.pdf",
            (string) $response->headers->get('content-disposition')
        );
        $this->assertSame($quantidadeDevolucoesAntes, ConsignacaoDevolucao::query()->count());
        $this->assertSame($quantidadeMovimentacoesAntes, EstoqueMovimentacao::query()->count());
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

        $consignacao = Consignacao::create([
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
