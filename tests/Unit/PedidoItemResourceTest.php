<?php

namespace Tests\Unit;

use App\Http\Resources\PedidoItemResource;
use App\Models\Categoria;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PedidoItemResourceTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_REFERENCIA = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR42mNkYPj/HwADAgH/ox3bWQAAAABJRU5ErkJggg==';

    public function test_imagem_usa_fallback_por_referencia_quando_produto_atual_nao_tem_imagem(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/referencia-resource.png', base64_decode(self::PNG_REFERENCIA));

        $produtoAtual = $this->createProduto('Produto atual sem imagem');
        $variacaoAtual = $this->createVariacao($produtoAtual, 'REF-RESOURCE');

        $produtoComImagem = $this->createProduto('Produto com imagem da referencia');
        $this->createVariacao($produtoComImagem, 'REF-RESOURCE');
        ProdutoImagem::create([
            'id_produto' => $produtoComImagem->id,
            'url' => 'referencia-resource.png',
            'principal' => true,
        ]);

        $item = new PedidoItem([
            'id_variacao' => $variacaoAtual->id,
            'quantidade' => 1,
            'preco_unitario' => 10,
            'subtotal' => 10,
        ]);
        $item->setRelation(
            'variacao',
            $variacaoAtual->fresh()->load('imagem', 'produto.imagemPrincipal', 'produto.imagens', 'atributos')
        );

        $payload = (new PedidoItemResource($item))->resolve();

        $this->assertStringEndsWith('/storage/produtos/referencia-resource.png', $payload['imagem']);
    }

    private function createProduto(string $nome): Produto
    {
        $categoria = Categoria::firstOrCreate(['nome' => 'Categoria Teste']);

        return Produto::create([
            'nome' => $nome,
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
    }

    private function createVariacao(Produto $produto, string $referencia): ProdutoVariacao
    {
        return ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'nome' => 'Padrao',
            'preco' => 10,
            'custo' => 5,
        ]);
    }
}
