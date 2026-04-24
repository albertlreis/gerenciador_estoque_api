<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProdutoImagemResetRelinkCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_relink_religa_produto_por_codigo_unico_variacao_por_sku_e_permanece_idempotente(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $produtoId = $this->criarProduto([
            'nome' => 'Mesa Relink',
            'codigo_produto' => 'COD-RELINK',
        ]);

        $variacaoId = $this->criarVariacao($produtoId, [
            'nome' => 'Mesa Relink Azul',
            'referencia' => 'REF-RELINK-AZUL',
            'sku_interno' => 'SKU-RELINK-AZUL',
            'chave_variacao' => 'relink|azul',
        ]);

        Storage::disk('public')->put('produtos/relink-produto.jpg', 'produto-relink');
        Storage::disk('public')->put('produtos/variacoes/relink-variacao.jpg', 'variacao-relink');

        $manifestPath = $this->gravarManifesto('20260424-110000', [
            [
                'tipo' => 'produto',
                'imagem_id_antiga' => 1,
                'produto_id_antigo' => 99,
                'variacao_id_antiga' => null,
                'codigo_produto' => 'COD-RELINK',
                'produto_nome' => 'Mesa Relink',
                'produto_nome_normalizado' => 'mesa relink',
                'variacao_nome' => null,
                'sku_interno' => null,
                'chave_variacao' => null,
                'referencia' => null,
                'url_armazenada' => 'relink-produto.jpg',
                'caminho_relativo' => 'produtos/relink-produto.jpg',
                'principal' => true,
                'arquivo_existe' => true,
                'sha256' => hash('sha256', 'produto-relink'),
            ],
            [
                'tipo' => 'variacao',
                'imagem_id_antiga' => 2,
                'produto_id_antigo' => 99,
                'variacao_id_antiga' => 199,
                'codigo_produto' => 'COD-RELINK',
                'produto_nome' => 'Mesa Relink',
                'produto_nome_normalizado' => 'mesa relink',
                'variacao_nome' => 'Mesa Relink Azul',
                'sku_interno' => 'SKU-RELINK-AZUL',
                'chave_variacao' => 'relink|azul',
                'referencia' => 'REF-RELINK-AZUL',
                'url_armazenada' => '/storage/produtos/variacoes/relink-variacao.jpg',
                'caminho_relativo' => 'produtos/variacoes/relink-variacao.jpg',
                'principal' => null,
                'arquivo_existe' => true,
                'sha256' => hash('sha256', 'variacao-relink'),
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-24 11:05:00'));

        $this->artisan('produtos:relink-imagens-reset', ['manifest_path' => $manifestPath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('produto_imagens', [
            'id_produto' => $produtoId,
            'url' => 'relink-produto.jpg',
            'principal' => 1,
        ]);

        $this->assertDatabaseHas('produto_variacao_imagens', [
            'id_variacao' => $variacaoId,
            'url' => '/storage/produtos/variacoes/relink-variacao.jpg',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-24 11:05:01'));

        $this->artisan('produtos:relink-imagens-reset', ['manifest_path' => $manifestPath])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('produto_imagens')->where('id_produto', $produtoId)->count());
        $this->assertSame(1, DB::table('produto_variacao_imagens')->where('id_variacao', $variacaoId)->count());
    }

    public function test_relink_resolve_produto_duplicado_por_nome_normalizado(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $produtoA = $this->criarProduto([
            'nome' => 'Mesa Arvore Clara',
            'codigo_produto' => 'COD-DUP',
        ]);
        $produtoB = $this->criarProduto([
            'nome' => 'Mesa Árvore Escura',
            'codigo_produto' => 'COD-DUP',
        ]);

        Storage::disk('public')->put('produtos/duplicado-heu.jpg', 'produto-heuristico');
        $manifestPath = $this->gravarManifesto('20260424-111000', [
            [
                'tipo' => 'produto',
                'imagem_id_antiga' => 10,
                'produto_id_antigo' => 110,
                'variacao_id_antiga' => null,
                'codigo_produto' => 'COD-DUP',
                'produto_nome' => 'Mesa Arvore Escura',
                'produto_nome_normalizado' => 'mesa arvore escura',
                'variacao_nome' => null,
                'sku_interno' => null,
                'chave_variacao' => null,
                'referencia' => null,
                'url_armazenada' => 'duplicado-heu.jpg',
                'caminho_relativo' => 'produtos/duplicado-heu.jpg',
                'principal' => true,
                'arquivo_existe' => true,
                'sha256' => hash('sha256', 'produto-heuristico'),
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-24 11:10:00'));

        $this->artisan('produtos:relink-imagens-reset', ['manifest_path' => $manifestPath])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('produto_imagens', [
            'id_produto' => $produtoA,
            'url' => 'duplicado-heu.jpg',
        ]);

        $this->assertDatabaseHas('produto_imagens', [
            'id_produto' => $produtoB,
            'url' => 'duplicado-heu.jpg',
            'principal' => 1,
        ]);
    }

    public function test_relink_mantem_produto_ambiguo_em_pendencia_sem_vinculo_incorreto(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $produtoA = $this->criarProduto([
            'nome' => 'Mesa Azul',
            'codigo_produto' => 'COD-AMB',
        ]);
        $produtoB = $this->criarProduto([
            'nome' => 'Mesa Azul',
            'codigo_produto' => 'COD-AMB',
        ]);

        Storage::disk('public')->put('produtos/ambiguo.jpg', 'produto-ambiguo');
        $manifestPath = $this->gravarManifesto('20260424-111500', [
            [
                'tipo' => 'produto',
                'imagem_id_antiga' => 20,
                'produto_id_antigo' => 120,
                'variacao_id_antiga' => null,
                'codigo_produto' => 'COD-AMB',
                'produto_nome' => 'Mesa Azul',
                'produto_nome_normalizado' => 'mesa azul',
                'variacao_nome' => null,
                'sku_interno' => null,
                'chave_variacao' => null,
                'referencia' => null,
                'url_armazenada' => 'ambiguo.jpg',
                'caminho_relativo' => 'produtos/ambiguo.jpg',
                'principal' => true,
                'arquivo_existe' => true,
                'sha256' => hash('sha256', 'produto-ambiguo'),
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-24 11:15:00'));

        $this->artisan('produtos:relink-imagens-reset', ['manifest_path' => $manifestPath])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('produto_imagens')->whereIn('id_produto', [$produtoA, $produtoB])->count());

        $pendingCsv = (string) Storage::disk('local')->get('operations/reset-imagens/20260424-111500/relink-20260424-111500/pendencias.csv');
        $this->assertStringContainsString('multiplos_matches', $pendingCsv);
        $this->assertStringContainsString('ambiguo.jpg', $pendingCsv);
    }

    public function test_relink_faz_fallback_de_variacao_por_chave_e_por_codigo_referencia(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $produtoChave = $this->criarProduto([
            'nome' => 'Mesa Chave',
            'codigo_produto' => 'COD-CHAVE',
        ]);
        $variacaoChave = $this->criarVariacao($produtoChave, [
            'nome' => 'Mesa Chave Verde',
            'referencia' => 'REF-CHAVE',
            'sku_interno' => null,
            'chave_variacao' => 'mesa|verde',
        ]);

        $produtoPar = $this->criarProduto([
            'nome' => 'Mesa Par',
            'codigo_produto' => 'COD-PAR',
        ]);
        $variacaoPar = $this->criarVariacao($produtoPar, [
            'nome' => 'Mesa Par Ambar',
            'referencia' => 'REF-PAR',
            'sku_interno' => null,
            'chave_variacao' => null,
        ]);

        Storage::disk('public')->put('produtos/variacoes/chave.jpg', 'variacao-chave');
        Storage::disk('public')->put('produtos/variacoes/par.jpg', 'variacao-par');

        $manifestPath = $this->gravarManifesto('20260424-112000', [
            [
                'tipo' => 'variacao',
                'imagem_id_antiga' => 30,
                'produto_id_antigo' => 130,
                'variacao_id_antiga' => 230,
                'codigo_produto' => 'COD-CHAVE',
                'produto_nome' => 'Mesa Chave',
                'produto_nome_normalizado' => 'mesa chave',
                'variacao_nome' => 'Mesa Chave Verde',
                'sku_interno' => 'SKU-INEXISTENTE',
                'chave_variacao' => 'mesa|verde',
                'referencia' => 'REF-CHAVE',
                'url_armazenada' => '/storage/produtos/variacoes/chave.jpg',
                'caminho_relativo' => 'produtos/variacoes/chave.jpg',
                'principal' => null,
                'arquivo_existe' => true,
                'sha256' => hash('sha256', 'variacao-chave'),
            ],
            [
                'tipo' => 'variacao',
                'imagem_id_antiga' => 31,
                'produto_id_antigo' => 131,
                'variacao_id_antiga' => 231,
                'codigo_produto' => 'COD-PAR',
                'produto_nome' => 'Mesa Par',
                'produto_nome_normalizado' => 'mesa par',
                'variacao_nome' => 'Mesa Par Ambar',
                'sku_interno' => null,
                'chave_variacao' => null,
                'referencia' => 'REF-PAR',
                'url_armazenada' => '/storage/produtos/variacoes/par.jpg',
                'caminho_relativo' => 'produtos/variacoes/par.jpg',
                'principal' => null,
                'arquivo_existe' => true,
                'sha256' => hash('sha256', 'variacao-par'),
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-24 11:20:00'));

        $this->artisan('produtos:relink-imagens-reset', ['manifest_path' => $manifestPath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('produto_variacao_imagens', [
            'id_variacao' => $variacaoChave,
            'url' => '/storage/produtos/variacoes/chave.jpg',
        ]);
        $this->assertDatabaseHas('produto_variacao_imagens', [
            'id_variacao' => $variacaoPar,
            'url' => '/storage/produtos/variacoes/par.jpg',
        ]);

        $summary = json_decode(
            (string) Storage::disk('local')->get('operations/reset-imagens/20260424-112000/relink-20260424-112000/summary.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(1, $summary['estrategias']['variacao.chave_variacao']);
        $this->assertSame(1, $summary['estrategias']['variacao.codigo_produto_referencia']);
    }

    public function test_relink_reporta_arquivo_ausente_sem_criar_vinculo(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $produtoId = $this->criarProduto([
            'nome' => 'Mesa Arquivo Ausente',
            'codigo_produto' => 'COD-AUS',
        ]);

        $manifestPath = $this->gravarManifesto('20260424-112500', [
            [
                'tipo' => 'produto',
                'imagem_id_antiga' => 40,
                'produto_id_antigo' => 140,
                'variacao_id_antiga' => null,
                'codigo_produto' => 'COD-AUS',
                'produto_nome' => 'Mesa Arquivo Ausente',
                'produto_nome_normalizado' => 'mesa arquivo ausente',
                'variacao_nome' => null,
                'sku_interno' => null,
                'chave_variacao' => null,
                'referencia' => null,
                'url_armazenada' => 'ausente.jpg',
                'caminho_relativo' => 'produtos/ausente.jpg',
                'principal' => true,
                'arquivo_existe' => false,
                'sha256' => null,
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-24 11:25:00'));

        $this->artisan('produtos:relink-imagens-reset', ['manifest_path' => $manifestPath])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('produto_imagens', [
            'id_produto' => $produtoId,
            'url' => 'ausente.jpg',
        ]);

        $pendingCsv = (string) Storage::disk('local')->get('operations/reset-imagens/20260424-112500/relink-20260424-112500/pendencias.csv');
        $this->assertStringContainsString('arquivo_ausente', $pendingCsv);
        $this->assertStringContainsString('ausente.jpg', $pendingCsv);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function gravarManifesto(string $timestamp, array $items): string
    {
        $path = "operations/reset-imagens/{$timestamp}/manifest.json";
        Storage::disk('local')->put($path, json_encode([
            'manifest_version' => 1,
            'operacao' => 'export',
            'gerado_em' => now()->toIso8601String(),
            'items_total' => count($items),
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function criarProduto(array $overrides = []): int
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Relink ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Relink ' . uniqid(),
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
            'nome' => 'Produto Relink ' . uniqid(),
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
            'nome' => 'Variacao Relink',
            'preco' => 120,
            'custo' => 60,
            'codigo_barras' => null,
            'sku_interno' => null,
            'chave_variacao' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
