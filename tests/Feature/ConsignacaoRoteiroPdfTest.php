<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ClienteEndereco;
use App\Models\Consignacao;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Models\Usuario;
use App\Services\PdfImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsignacaoRoteiroPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII=';

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

    public function test_view_do_roteiro_de_consignacao_renderiza_imagem_embutida(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/variacoes/roteiro-consignacao.png', base64_decode(self::PNG_1X1));

        [$pedidoId, $variacaoId] = $this->criarPedidoConsignado('pendente', PedidoStatus::CONSIGNADO);

        ProdutoVariacaoImagem::create([
            'id_variacao' => $variacaoId,
            'url' => '/storage/produtos/variacoes/roteiro-consignacao.png',
        ]);

        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',
            'statusAtual',
            'consignacoes.deposito',
            'consignacoes.produtoVariacao.imagem',
            'consignacoes.produtoVariacao.produto.imagemPrincipal',
            'consignacoes.produtoVariacao.produto',
            'consignacoes.produtoVariacao.atributos',
            'consignacoes.produtoVariacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

        $pdfImageService = app(PdfImageService::class);
        $pedido->consignacoes->each(function ($consignacao) use ($pdfImageService) {
            $consignacao->setAttribute('pdf_imagem_data_uri', $pdfImageService->fromProdutoVariacao($consignacao->produtoVariacao));
        });

        $html = view('exports.roteiro-consignacao', [
            'pedido' => $pedido,
            'grupos' => $pedido->consignacoes->groupBy(fn($item) => $item->deposito->nome ?? 'Sem depósito'),
            'geradoEm' => now('America/Belem')->format('d/m/Y H:i'),
            'tituloRoteiro' => 'Roteiro de consignação',
        ])->render();

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Rua Consignacao PDF', $html);
        $this->assertStringContainsString('101', $html);
        $this->assertStringContainsString('Sala 1', $html);
        $this->assertStringContainsString('Bairro Consignacao', $html);
        $this->assertStringContainsString('Belem/PA', $html);
        $this->assertStringContainsString('CEP 66000101', $html);
    }

    public function test_view_do_roteiro_de_entrega_renderiza_imagem_embutida_do_produto_como_fallback(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/roteiro-pedido.png', base64_decode(self::PNG_1X1));

        [$pedidoId, $produtoId] = $this->criarPedidoComItem();

        ProdutoImagem::create([
            'id_produto' => $produtoId,
            'url' => 'roteiro-pedido.png',
            'principal' => true,
        ]);

        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',
            'itens.variacao.imagem',
            'itens.variacao.produto.imagemPrincipal',
            'itens.variacao.produto',
            'itens.variacao.atributos',
            'itens.variacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

        $pdfImageService = app(PdfImageService::class);
        $pedido->itens->each(function ($item) use ($pdfImageService) {
            $item->setAttribute('pdf_imagem_data_uri', $pdfImageService->fromProdutoVariacao($item->variacao));
        });

        $html = view('exports.roteiro-pedido', [
            'pedido' => $pedido,
            'grupos' => $pedido->itens->groupBy('id_deposito'),
            'geradoEm' => now('America/Belem')->format('d/m/Y H:i'),
        ])->render();

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Rua Pedido PDF', $html);
        $this->assertStringContainsString('202', $html);
        $this->assertStringContainsString('Sala 2', $html);
        $this->assertStringContainsString('Bairro Pedido', $html);
        $this->assertStringContainsString('Belem/PA', $html);
        $this->assertStringContainsString('CEP 66000202', $html);
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
        $this->criarEnderecoPrincipal($cliente, [
            'cep' => '66000101',
            'endereco' => 'Rua Consignacao PDF',
            'numero' => '101',
            'complemento' => 'Sala 1',
            'bairro' => 'Bairro Consignacao',
            'cidade' => 'Belem',
            'estado' => 'PA',
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

        return [$pedido->id, $variacao->id];
    }

    private function criarPedidoComItem(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Pedido PDF',
            'email' => uniqid('pedido-pdf-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $cliente = Cliente::create([
            'nome' => 'Cliente Pedido PDF',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $this->criarEnderecoPrincipal($cliente, [
            'cep' => '66000202',
            'endereco' => 'Rua Pedido PDF',
            'numero' => '202',
            'complemento' => 'Sala 2',
            'bairro' => 'Bairro Pedido',
            'cidade' => 'Belem',
            'estado' => 'PA',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Pedido PDF']);
        $produto = Produto::create([
            'nome' => 'Produto Pedido PDF',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'PED-001',
            'nome' => 'Variacao Pedido PDF',
            'preco' => 150,
            'custo' => 90,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Pedido PDF']);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 150,
            'prazo_dias_uteis' => 15,
        ]);

        PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 150,
            'subtotal' => 150,
        ]);

        return [$pedido->id, $produto->id];
    }

    private function criarEnderecoPrincipal(Cliente $cliente, array $dados): void
    {
        ClienteEndereco::create($dados + [
            'cliente_id' => $cliente->id,
            'principal' => true,
            'fingerprint' => hash('sha256', 'cliente-endereco-' . $cliente->id . '-' . ($dados['endereco'] ?? '')),
        ]);
    }
}
