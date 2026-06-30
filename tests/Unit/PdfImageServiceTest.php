<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Services\PdfImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfImageServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII=';
    private const PNG_VARIACAO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR42mP8z8BQDwAFgwJ/lb4qmgAAAABJRU5ErkJggg==';
    private const PNG_PRODUTO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    private const PNG_REFERENCIA = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR42mNkYPj/HwADAgH/ox3bWQAAAABJRU5ErkJggg==';

    public function test_converte_url_storage_em_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/teste.png', base64_decode(self::PNG_1X1));

        $service = app(PdfImageService::class);
        $dataUri = $service->toDataUri('/storage/produtos/teste.png');

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_converte_caminho_absoluto_de_container_em_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/teste-container.png', base64_decode(self::PNG_1X1));

        $service = app(PdfImageService::class);
        $dataUri = $service->toDataUri('/var/www/html/public/storage/produtos/teste-container.png');

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function test_retorna_null_para_arquivo_inexistente(): void
    {
        Storage::fake('public');

        $service = app(PdfImageService::class);

        $this->assertNull($service->toDataUri('/storage/produtos/inexistente.png'));
        $this->assertNull($service->toDataUri(''));
        $this->assertNull($service->toDataUri(null));
    }

    public function test_to_pdf_src_retorna_placeholder_quando_imagem_nao_existe(): void
    {
        Storage::fake('public');

        $service = app(PdfImageService::class);
        $dataUri = $service->toPdfSrc('/storage/produtos/inexistente.png');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
        $this->assertSame($service->placeholderDataUri(), $dataUri);
    }

    public function test_resolve_imagem_da_variacao_com_prioridade(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/produto.png', base64_decode(self::PNG_PRODUTO));
        Storage::disk('public')->put('produtos/referencia.png', base64_decode(self::PNG_REFERENCIA));
        Storage::disk('public')->put('produtos/variacoes/variacao.png', base64_decode(self::PNG_VARIACAO));

        $produto = $this->createProduto('Produto atual');
        $variacao = $this->createVariacao($produto, 'REF-PRIORIDADE');
        ProdutoImagem::create(['id_produto' => $produto->id, 'url' => 'produto.png', 'principal' => true]);
        ProdutoVariacaoImagem::create(['id_variacao' => $variacao->id, 'url' => '/storage/produtos/variacoes/variacao.png']);

        $produtoDuplicado = $this->createProduto('Produto duplicado');
        $this->createVariacao($produtoDuplicado, 'REF-PRIORIDADE');
        ProdutoImagem::create(['id_produto' => $produtoDuplicado->id, 'url' => 'referencia.png', 'principal' => true]);

        $service = app(PdfImageService::class);

        $this->assertSame(
            $this->pngDataUri(self::PNG_VARIACAO),
            $service->fromProdutoVariacao($variacao->fresh()->load('imagem', 'produto.imagemPrincipal'))
        );
    }

    public function test_resolve_imagem_do_produto_da_variacao_para_pdfs_operacionais(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/produto-pdf.png', base64_decode(self::PNG_PRODUTO));
        Storage::disk('public')->put('produtos/variacoes/variacao-pdf.png', base64_decode(self::PNG_VARIACAO));

        $produto = $this->createProduto('Produto PDF');
        $variacao = $this->createVariacao($produto, 'REF-PDF-PRODUTO');
        ProdutoImagem::create(['id_produto' => $produto->id, 'url' => 'produto-pdf.png', 'principal' => true]);
        ProdutoVariacaoImagem::create([
            'id_variacao' => $variacao->id,
            'url' => '/storage/produtos/variacoes/variacao-pdf.png',
            'principal' => true,
            'ordem' => 0,
        ]);

        $service = app(PdfImageService::class);

        $this->assertSame(
            $this->pngDataUri(self::PNG_PRODUTO),
            $service->fromProdutoDaVariacao($variacao->fresh()->load('imagem', 'produto.imagemPrincipal'))
        );
    }

    public function test_resolve_imagem_do_produto_quando_variacao_nao_tem_imagem(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/produto-fallback.png', base64_decode(self::PNG_PRODUTO));
        Storage::disk('public')->put('produtos/referencia.png', base64_decode(self::PNG_REFERENCIA));

        $produto = $this->createProduto('Produto atual');
        $variacao = $this->createVariacao($produto, 'REF-PRODUTO');
        ProdutoImagem::create(['id_produto' => $produto->id, 'url' => 'produto-fallback.png', 'principal' => true]);

        $produtoDuplicado = $this->createProduto('Produto duplicado');
        $this->createVariacao($produtoDuplicado, 'REF-PRODUTO');
        ProdutoImagem::create(['id_produto' => $produtoDuplicado->id, 'url' => 'referencia.png', 'principal' => true]);

        $service = app(PdfImageService::class);

        $this->assertSame(
            $this->pngDataUri(self::PNG_PRODUTO),
            $service->fromProdutoVariacao($variacao->fresh()->load('imagem', 'produto.imagemPrincipal'))
        );
    }

    public function test_resolve_imagem_de_produto_com_mesma_referencia_quando_item_atual_nao_tem_imagem(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/referencia.png', base64_decode(self::PNG_REFERENCIA));

        $produto = $this->createProduto('Produto atual');
        $variacao = $this->createVariacao($produto, 'REF-DUPLICADA');

        $produtoDuplicado = $this->createProduto('Produto duplicado');
        $this->createVariacao($produtoDuplicado, 'REF-DUPLICADA');
        ProdutoImagem::create(['id_produto' => $produtoDuplicado->id, 'url' => 'referencia.png', 'principal' => true]);

        $service = app(PdfImageService::class);

        $this->assertSame(
            $this->pngDataUri(self::PNG_REFERENCIA),
            $service->fromProdutoVariacao($variacao->fresh()->load('imagem', 'produto.imagemPrincipal'))
        );
    }

    public function test_retorna_null_quando_variacao_nao_tem_imagem_valida(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/variacoes/duplicada.png', base64_decode(self::PNG_REFERENCIA));

        $produto = $this->createProduto('Produto atual');
        $variacao = $this->createVariacao($produto, 'REF-SEM-IMAGEM');
        ProdutoImagem::create(['id_produto' => $produto->id, 'url' => 'inexistente.png', 'principal' => true]);

        $produtoDuplicado = $this->createProduto('Produto duplicado');
        $variacaoDuplicada = $this->createVariacao($produtoDuplicado, 'REF-SEM-IMAGEM');
        ProdutoImagem::create(['id_produto' => $produtoDuplicado->id, 'url' => '', 'principal' => true]);
        ProdutoVariacaoImagem::create(['id_variacao' => $variacaoDuplicada->id, 'url' => '/storage/produtos/variacoes/duplicada.png']);
        ProdutoVariacaoImagem::create(['id_variacao' => $variacao->id, 'url' => '/storage/produtos/variacoes/inexistente.png']);

        $service = app(PdfImageService::class);

        $this->assertNull($service->fromProdutoVariacao($variacao->fresh()->load('imagem', 'produto.imagemPrincipal')));
        $this->assertNull($service->fromProdutoVariacao(null));
    }

    public function test_from_produto_variacao_or_placeholder_retorna_placeholder_sem_imagem_valida(): void
    {
        Storage::fake('public');

        $produto = $this->createProduto('Produto sem imagem');
        $variacao = $this->createVariacao($produto, 'REF-PLACEHOLDER');

        $service = app(PdfImageService::class);

        $this->assertSame(
            $service->placeholderDataUri(),
            $service->fromProdutoVariacaoOrPlaceholder($variacao->fresh()->load('imagem', 'produto.imagemPrincipal'))
        );
    }

    private function createProduto(string $nome, bool $ativo = true): Produto
    {
        $categoria = Categoria::firstOrCreate(['nome' => 'Categoria Teste']);

        return Produto::create([
            'nome' => $nome,
            'id_categoria' => $categoria->id,
            'ativo' => $ativo,
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

    private function pngDataUri(string $base64): string
    {
        return 'data:image/png;base64,' . $base64;
    }
}
