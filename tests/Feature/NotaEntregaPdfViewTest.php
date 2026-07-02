<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Models\Usuario;
use App\Services\PdfImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotaEntregaPdfViewTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII=';

    public function test_view_da_nota_de_entrega_renderiza_imagem_embutida_resolvida(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/nota-entrega.png', base64_decode(self::PNG_1X1));

        $cliente = new Cliente([
            'nome' => 'Cliente Nota Entrega',
            'documento' => '12345678900',
            'telefone' => '(91) 99999-0000',
        ]);

        $pedido = new Pedido([
            'id' => 77,
            'numero_externo' => 'NE-77',
            'data_pedido' => '2026-07-02',
            'observacoes' => 'Observacao do pedido',
        ]);
        $pedido->setRelation('cliente', $cliente);
        $pedido->setRelation('usuario', new Usuario(['nome' => 'Vendedor Teste']));
        $pedido->setRelation('parceiro', null);

        $produto = new Produto(['nome' => 'Produto com imagem']);
        $produto->setRelation('imagemPrincipal', new ProdutoImagem([
            'url' => 'nota-entrega.png',
            'principal' => true,
        ]));

        $variacao = new ProdutoVariacao([
            'referencia' => 'REF-NOTA',
            'nome' => 'Padrao',
            'nome_completo' => 'Produto com imagem / Padrao',
        ]);
        $variacao->setRelation('produto', $produto);
        $variacao->setRelation('imagem', new ProdutoVariacaoImagem());

        $item = new ProdutoEntregaItem();
        $item->setAttribute('nota_quantidade', 1);
        $item->setAttribute(
            'pdf_imagem_data_uri',
            app(PdfImageService::class)->fromProdutoVariacaoProdutoFirstOrPlaceholder($variacao)
        );
        $item->setRelation('variacao', $variacao);
        $item->setRelation('pedidoItem', null);

        $html = view('exports.nota-entrega-pedido', [
            'pedido' => $pedido,
            'itens' => collect([$item]),
            'geradoEm' => now('America/Belem')->format('d/m/Y H:i'),
            'observacaoNota' => null,
            'registrarEntrega' => false,
            'enderecoEntrega' => null,
        ])->render();

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('Produto com imagem', $html);
        $this->assertStringContainsString('REF-NOTA', $html);
    }
}
