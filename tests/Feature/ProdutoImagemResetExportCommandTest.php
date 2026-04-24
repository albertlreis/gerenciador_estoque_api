<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProdutoImagemResetExportCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exporta_manifesto_com_imagens_de_produto_e_variacao(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-04-24 10:30:45'));
        $this->limparTabelasImagem();

        $produtoId = $this->criarProduto([
            'nome' => 'Mesa Alpha',
            'codigo_produto' => 'COD-ALPHA',
        ]);

        $variacaoId = $this->criarVariacao($produtoId, [
            'nome' => 'Mesa Alpha Azul',
            'referencia' => 'REF-ALPHA-AZUL',
            'sku_interno' => 'SKU-ALPHA-AZUL',
            'chave_variacao' => 'alpha|azul',
        ]);

        Storage::disk('public')->put('produtos/produto-alpha.jpg', 'conteudo-produto-alpha');
        Storage::disk('public')->put('produtos/variacoes/variacao-alpha.jpg', 'conteudo-variacao-alpha');

        DB::table('produto_imagens')->insert([
            'id_produto' => $produtoId,
            'url' => 'produto-alpha.jpg',
            'principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('produto_variacao_imagens')->insert([
            'id_variacao' => $variacaoId,
            'url' => '/storage/produtos/variacoes/variacao-alpha.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('produtos:export-imagens-reset')
            ->assertExitCode(0);

        $runDirectory = 'operations/reset-imagens/20260424-103045';
        $manifestPath = "{$runDirectory}/manifest.json";
        $summaryPath = "{$runDirectory}/summary.json";
        $pendingPath = "{$runDirectory}/pendencias.csv";

        Storage::disk('local')->assertExists($manifestPath);
        Storage::disk('local')->assertExists($summaryPath);
        Storage::disk('local')->assertExists($pendingPath);

        $manifest = json_decode((string) Storage::disk('local')->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $summary = json_decode((string) Storage::disk('local')->get($summaryPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $manifest['items_total']);
        $this->assertCount(2, $manifest['items']);
        $this->assertSame(1, $summary['por_tipo']['produto']);
        $this->assertSame(1, $summary['por_tipo']['variacao']);
        $this->assertSame(0, $summary['arquivos_ausentes']);

        $produtoItem = collect($manifest['items'])->firstWhere('tipo', 'produto');
        $variacaoItem = collect($manifest['items'])->firstWhere('tipo', 'variacao');

        $this->assertSame('COD-ALPHA', $produtoItem['codigo_produto']);
        $this->assertSame('Mesa Alpha', $produtoItem['produto_nome']);
        $this->assertSame('mesa alpha', $produtoItem['produto_nome_normalizado']);
        $this->assertSame('produtos/produto-alpha.jpg', $produtoItem['caminho_relativo']);
        $this->assertTrue($produtoItem['principal']);
        $this->assertTrue($produtoItem['arquivo_existe']);
        $this->assertSame(hash('sha256', 'conteudo-produto-alpha'), $produtoItem['sha256']);

        $this->assertSame('SKU-ALPHA-AZUL', $variacaoItem['sku_interno']);
        $this->assertSame('alpha|azul', $variacaoItem['chave_variacao']);
        $this->assertSame('REF-ALPHA-AZUL', $variacaoItem['referencia']);
        $this->assertSame('produtos/variacoes/variacao-alpha.jpg', $variacaoItem['caminho_relativo']);
        $this->assertTrue($variacaoItem['arquivo_existe']);
        $this->assertSame(hash('sha256', 'conteudo-variacao-alpha'), $variacaoItem['sha256']);

        $csv = trim((string) Storage::disk('local')->get($pendingPath));
        $this->assertSame('operacao,motivo,tipo,imagem_id_antiga,produto_id_antigo,variacao_id_antiga,codigo_produto,produto_nome,variacao_nome,sku_interno,chave_variacao,referencia,url_armazenada,caminho_relativo,detalhe,candidatos', $csv);
    }

    public function test_export_falha_por_padrao_quando_arquivo_esta_ausente_mas_override_permite_continuar(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-04-24 10:31:00'));
        $this->limparTabelasImagem();

        $produtoId = $this->criarProduto([
            'nome' => 'Mesa Sem Arquivo',
            'codigo_produto' => 'COD-MISS',
        ]);

        DB::table('produto_imagens')->insert([
            'id_produto' => $produtoId,
            'url' => 'sem-arquivo.jpg',
            'principal' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('produtos:export-imagens-reset')
            ->assertExitCode(1);

        $runDirectory = 'operations/reset-imagens/20260424-103100';
        $summary = json_decode((string) Storage::disk('local')->get("{$runDirectory}/summary.json"), true, 512, JSON_THROW_ON_ERROR);
        $csv = (string) Storage::disk('local')->get("{$runDirectory}/pendencias.csv");

        $this->assertSame('failed_missing_files', $summary['status']);
        $this->assertSame(1, $summary['arquivos_ausentes']);
        $this->assertStringContainsString('arquivo_ausente', $csv);
        $this->assertStringContainsString('sem-arquivo.jpg', $csv);

        Carbon::setTestNow(Carbon::parse('2026-04-24 10:31:01'));

        $this->artisan('produtos:export-imagens-reset', ['--allow-missing-files' => true])
            ->assertExitCode(0);

        $overrideSummary = json_decode(
            (string) Storage::disk('local')->get('operations/reset-imagens/20260424-103101/summary.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('ok', $overrideSummary['status']);
        $this->assertTrue($overrideSummary['allow_missing_files']);
    }

    private function criarProduto(array $overrides = []): int
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Reset ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Reset ' . uniqid(),
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('produtos')->insertGetId(array_merge([
            'nome' => 'Produto Reset ' . uniqid(),
            'codigo_produto' => null,
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function criarVariacao(int $produtoId, array $overrides = []): int
    {
        return (int) DB::table('produto_variacoes')->insertGetId(array_merge([
            'produto_id' => $produtoId,
            'referencia' => 'REF-' . uniqid(),
            'nome' => 'Variacao Reset',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'sku_interno' => null,
            'chave_variacao' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function limparTabelasImagem(): void
    {
        DB::table('produto_variacao_imagens')->delete();
        DB::table('produto_imagens')->delete();
    }
}
