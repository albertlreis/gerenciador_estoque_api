<?php

namespace Tests\Unit;

use App\Models\Produto;
use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoImagem;
use App\Services\PdfImageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfImageServiceTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+4fQAAAAASUVORK5CYII=';

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

    public function test_resolve_imagem_da_variacao_com_prioridade(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/produto.png', base64_decode(self::PNG_1X1));
        Storage::disk('public')->put('produtos/variacoes/variacao.png', base64_decode(self::PNG_1X1));

        $produto = new Produto();
        $produto->setRelation('imagemPrincipal', new ProdutoImagem(['url' => 'produto.png']));

        $variacao = new ProdutoVariacao();
        $variacao->setRelation('produto', $produto);
        $variacao->setRelation('imagem', new ProdutoVariacaoImagem(['url' => '/storage/produtos/variacoes/variacao.png']));

        $service = app(PdfImageService::class);

        $this->assertStringStartsWith('data:image/png;base64,', (string) $service->fromProdutoVariacao($variacao));
    }

    public function test_resolve_imagem_do_produto_quando_variacao_nao_tem_imagem(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('produtos/produto-fallback.png', base64_decode(self::PNG_1X1));

        $produto = new Produto();
        $produto->setRelation('imagemPrincipal', new ProdutoImagem(['url' => 'produto-fallback.png']));

        $variacao = new ProdutoVariacao();
        $variacao->setRelation('produto', $produto);
        $variacao->setRelation('imagem', null);

        $service = app(PdfImageService::class);

        $this->assertStringStartsWith('data:image/png;base64,', (string) $service->fromProdutoVariacao($variacao));
    }

    public function test_retorna_null_quando_variacao_nao_tem_imagem_valida(): void
    {
        Storage::fake('public');

        $produto = new Produto();
        $produto->setRelation('imagemPrincipal', new ProdutoImagem(['url' => 'inexistente.png']));

        $variacao = new ProdutoVariacao();
        $variacao->setRelation('produto', $produto);
        $variacao->setRelation('imagem', new ProdutoVariacaoImagem(['url' => '/storage/produtos/variacoes/inexistente.png']));

        $service = app(PdfImageService::class);

        $this->assertNull($service->fromProdutoVariacao($variacao));
        $this->assertNull($service->fromProdutoVariacao(null));
    }
}
