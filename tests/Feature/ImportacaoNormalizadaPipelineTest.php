<?php

namespace Tests\Feature;

use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\ImportacaoNormalizadaLinha;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoCodigoHistorico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportacaoNormalizadaPipelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function autenticarComoDev(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Dev Importação Normalizada',
            'email' => 'dev.importacao.normalizada.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['estoque.importar_planilha_dev'], now()->addHour());

        return $usuario;
    }

    private function criarPlanilhaFixture(): string
    {
        $headers = [
            'Código',
            'Código origem',
            'Código modelo',
            'Nome',
            'Nome normalizado',
            'Nome base normalizado',
            'Categoria',
            'Categoria normalizada',
            'Categoria oficial',
            'Código produto',
            'Chave produto',
            'Chave variação',
            'SKU interno',
            'Conflito código',
            'Regra categoria',
            'Dimensão 1 (cm)',
            'Dimensão 2 (cm)',
            'Dimensão 3 (cm)',
            'Cor extraída',
            'Lado extraído',
            'Material oficial',
            'Acabamento oficial',
            'Quantidade',
            'Status',
            'Localização',
            'Data Entrada',
            'Valor',
            'Fornecedor',
        ];

        $sheets = [
            'loja' => [
                $headers,
                [
                    'COD-001',
                    'ORIG-001',
                    'MOD-001',
                    'Sofá Alpha Azul',
                    'sofa alpha azul',
                    'sofa alpha',
                    'Estofados',
                    'estofados',
                    'Estofados',
                    'P-001',
                    'ESTOFADOS|SOFA ALPHA',
                    'ESTOFADOS|SOFA ALPHA|COR:AZUL|D1:200|D2:90|D3:80|MAT:TECIDO|ACAB:FOSCO',
                    'SKU-001',
                    '',
                    'ESTOFADOS',
                    200,
                    90,
                    80,
                    'Azul',
                    '',
                    'Tecido',
                    'Fosco',
                    3,
                    'Loja',
                    'A-01-01',
                    '2026-03-10',
                    5990.00,
                    'Fornecedor Sierra',
                ],
                [
                    'COD-002',
                    'ORIG-002',
                    'MOD-002',
                    'Sofá Alpha Verde',
                    'sofa alpha verde',
                    'sofa alpha',
                    'Estofados',
                    'estofados',
                    'Estofados',
                    'P-001',
                    'ESTOFADOS|SOFA ALPHA',
                    'ESTOFADOS|SOFA ALPHA|COR:VERDE|D1:200|D2:90|D3:80|MAT:TECIDO|ACAB:FOSCO',
                    'SKU-002',
                    'SIM',
                    'ESTOFADOS',
                    200,
                    90,
                    80,
                    'Verde',
                    '',
                    'Tecido',
                    'Fosco',
                    2,
                    'Brinde',
                    '',
                    '2026-03-11',
                    5990.00,
                    'Fornecedor Sierra',
                ],
            ],
            'Depósito JB' => [
                $headers,
                [
                    'COD-003',
                    'ORIG-003',
                    'MOD-003',
                    'Sofá Alpha Azul',
                    'sofa alpha azul',
                    'sofa alpha',
                    'Estofados',
                    'estofados',
                    'Estofados',
                    'P-001',
                    'ESTOFADOS|SOFA ALPHA',
                    'ESTOFADOS|SOFA ALPHA|COR:AZUL|D1:200|D2:90|D3:80|MAT:TECIDO|ACAB:FOSCO',
                    'SKU-001',
                    '',
                    'ESTOFADOS',
                    200,
                    90,
                    80,
                    'Azul',
                    '',
                    'Tecido',
                    'Fosco',
                    1,
                    'Depósito',
                    'B-02-01',
                    '2026-03-12',
                    5990.00,
                    'Fornecedor Sierra',
                ],
            ],
        ];

        $spreadsheet = new Spreadsheet();
        $defaultSheet = $spreadsheet->getActiveSheet();
        $sheetIndex = 0;

        foreach ($sheets as $sheetName => $rows) {
            $sheet = $sheetIndex === 0
                ? $defaultSheet
                : $spreadsheet->createSheet($sheetIndex);

            $sheet->setTitle($sheetName);
            $sheet->fromArray($rows, null, 'A1');
            $sheetIndex++;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'importacao-normalizada-');
        if ($tempBase === false) {
            $this->fail('Não foi possível criar o arquivo temporário da planilha de teste.');
        }

        @unlink($tempBase);
        $path = $tempBase . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function criarPlanilhaCargaInicialFixture(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheetProdutos = $spreadsheet->getActiveSheet();
        $sheetProdutos->setTitle('Produtos');
        $sheetProdutos->fromArray([
            [
                'quantidade', 'data_entrada', 'referencia', 'localizacao', 'nome', 'categoria',
                'Madeira', 'Tec. 1', 'Tec. 2', 'Metal / Vidro', 'valor', 'outlet', 'status',
                'diametro_cm', 'largura_cm', 'profundidade_cm', 'altura_cm',
            ],
            [1, '2026-03-12', 'REF-DUP-CARGA', 'A-01-01', 'Poltrona Carga Inicial', 'Poltrona', 'AC01', '', '', '', 1000, '', 'Loja', '', 60, 70, 80],
            [1, '2026-03-12', 'REF-DUP-CARGA', 'A-01-02', 'Poltrona Carga Inicial', 'Poltrona', 'AC02', '', '', '', 1100, '', 'Depósito', '', 60, 70, 80],
            [1, '2026-03-12', 'REF-DUP-CARGA', '', 'Poltrona Carga Inicial', 'Poltrona', 'AC03', '', '', '', 1200, '', 'Vendido', '', 60, 70, 80],
        ], null, 'A1');

        $sheetAdornos = $spreadsheet->createSheet();
        $sheetAdornos->setTitle('Adornos');
        $sheetAdornos->fromArray([
            ['referencia', 'localizacao', 'Nome', 'Fornecedor', 'Unidade', 'Valor Unit', 'Status', 'Custo'],
            ['REF-ADORNO-1', 'B-10-02', 'Vaso Teste', 'Fornecedor Teste', 'UN', 250, 'Loja', 100],
        ], null, 'A1');

        $tempBase = tempnam(sys_get_temp_dir(), 'importacao-carga-inicial-');
        if ($tempBase === false) {
            $this->fail('Não foi possível criar o arquivo temporário da planilha de carga inicial.');
        }

        @unlink($tempBase);
        $path = $tempBase . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    public function test_pipeline_normalizado_respeita_status_revisao_estoque_e_idempotencia(): void
    {
        $this->autenticarComoDev();
        $arquivoPath = $this->criarPlanilhaFixture();

        $upload = new UploadedFile(
            $arquivoPath,
            'importacao-normalizada-fixture.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => $upload,
        ]);

        $response->assertCreated();
        $importacaoId = (int) $response->json('data.id');

        $this->assertSame(3, (int) $response->json('data.linhas_total'));
        $this->assertSame(1, (int) $response->json('data.linhas_pendentes_revisao'));
        $this->assertSame(1, (int) $response->json('data.linhas_com_conflito'));

        $linhasResponse = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/linhas?status_normalizado=Loja");
        $linhasResponse->assertOk();
        $this->assertCount(1, $linhasResponse->json('data.data'));
        $this->assertSame('SKU-001', $linhasResponse->json('data.data.0.sku_interno'));

        $previewResponse = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/preview");
        $previewResponse->assertOk();
        $preview = $previewResponse->json('data.preview');

        $this->assertSame(3, data_get($preview, 'totais.linhas_total'));
        $this->assertSame(2, data_get($preview, 'totais.linhas_que_gerariam_estoque'));
        $this->assertSame(1, data_get($preview, 'totais.linhas_que_nao_gerariam_estoque'));
        $this->assertSame(1, data_get($preview, 'totais.linhas_com_conflito'));
        $this->assertSame(1, data_get($preview, 'totais.linhas_bloqueadas'));
        $this->assertSame(0, data_get($preview, 'totais.linhas_pendentes_revisao'));
        $this->assertSame(2, data_get($preview, 'totais.variacoes_novas'));
        $this->assertSame(1, data_get($preview, 'totais.produtos_pais_novos'));

        $confirmacaoBloqueada = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/confirmar");
        $confirmacaoBloqueada->assertStatus(422);
        $this->assertFalse((bool) $confirmacaoBloqueada->json('sucesso'));

        /** @var ImportacaoNormalizadaLinha $linhaBrinde */
        $linhaBrinde = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $importacaoId)
            ->where('sku_interno', 'SKU-002')
            ->firstOrFail();

        $revisaoResponse = $this->patchJson("/api/v1/importacoes/normalizadas/linhas/{$linhaBrinde->id}/revisao", [
            'status_revisao' => 'aprovado',
            'decisao' => 'manter_separado',
            'motivo' => 'SKU separado e sem geração de estoque por status Brinde.',
        ]);

        $revisaoResponse->assertOk();

        $previewAposRevisao = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/preview");
        $previewAposRevisao->assertOk();
        $this->assertSame(0, data_get($previewAposRevisao->json('data.preview'), 'totais.linhas_bloqueadas'));
        $this->assertSame(0, data_get($previewAposRevisao->json('data.preview'), 'totais.linhas_pendentes_revisao'));
        $this->assertSame(3, data_get($previewAposRevisao->json('data.preview'), 'totais.linhas_validas_para_efetivacao'));

        $confirmacao = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/confirmar");
        $confirmacao->assertOk();
        $this->assertTrue((bool) $confirmacao->json('sucesso'));
        $this->assertSame('confirmada', $confirmacao->json('importacao.status'));

        $efetivacao = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/efetivar");
        $efetivacao->assertOk();
        $this->assertTrue((bool) $efetivacao->json('sucesso'));
        $this->assertSame('efetivada', $efetivacao->json('importacao.status'));

        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'ref_type' => 'importacao_normalizada_linha',
            'ref_id' => $linhaBrinde->id,
        ]);

        /** @var Produto $produto */
        $produto = Produto::query()->where('codigo_produto', 'P-001')->firstOrFail();
        $this->assertSame('P-001', $produto->codigo_produto);
        $this->assertSame(1, Produto::query()->where('codigo_produto', 'P-001')->count());

        /** @var ProdutoVariacao $variacaoEstoque */
        $variacaoEstoque = ProdutoVariacao::query()->where('sku_interno', 'SKU-001')->firstOrFail();
        /** @var ProdutoVariacao $variacaoSemEstoque */
        $variacaoSemEstoque = ProdutoVariacao::query()->where('sku_interno', 'SKU-002')->firstOrFail();

        $this->assertSame($produto->id, $variacaoEstoque->produto_id);
        $this->assertSame($produto->id, $variacaoSemEstoque->produto_id);
        $this->assertTrue($variacaoSemEstoque->conflito_codigo);
        $this->assertSame(2, ProdutoVariacao::query()->where('produto_id', $produto->id)->count());
        $this->assertSame(
            3,
            ProdutoVariacaoCodigoHistorico::query()
                ->whereIn('produto_variacao_id', [$variacaoEstoque->id, $variacaoSemEstoque->id])
                ->count()
        );
        $this->assertSame(
            2,
            EstoqueMovimentacao::query()
                ->where('ref_type', 'importacao_normalizada_linha')
                ->whereIn('ref_id', ImportacaoNormalizadaLinha::query()->where('importacao_id', $importacaoId)->pluck('id'))
                ->count()
        );

        $estoquesVariacao = Estoque::query()->where('id_variacao', $variacaoEstoque->id)->get();
        $this->assertSame(2, $estoquesVariacao->where('quantidade', '>', 0)->count());
        $this->assertSame(4, (int) $estoquesVariacao->sum('quantidade'));
        $this->assertCount(0, Estoque::query()->where('id_variacao', $variacaoSemEstoque->id)->where('quantidade', '>', 0)->get());

        $relatorio = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/relatorio");
        $relatorio->assertOk();
        $this->assertSame(3, $relatorio->json('data.relatorio.total_linhas_processadas'));
        $this->assertSame(3, $relatorio->json('data.relatorio.total_linhas_efetivadas'));
        $this->assertSame(2, $relatorio->json('data.relatorio.total_linhas_que_geraram_estoque'));
        $this->assertSame(1, $relatorio->json('data.relatorio.total_linhas_sem_estoque_por_status'));
        $this->assertSame(2, $relatorio->json('data.relatorio.total_movimentacoes_criadas'));
        $this->assertSame(3, $relatorio->json('data.relatorio.total_codigos_historicos_persistidos'));

        $movimentacoesAntes = EstoqueMovimentacao::query()->count();
        $estoquesAntes = Estoque::query()->count();
        $historicosAntes = ProdutoVariacaoCodigoHistorico::query()->count();

        $efetivacaoIdempotente = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/efetivar");
        $efetivacaoIdempotente->assertOk();
        $this->assertTrue((bool) $efetivacaoIdempotente->json('idempotente'));
        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::query()->count());
        $this->assertSame($estoquesAntes, Estoque::query()->count());
        $this->assertSame($historicosAntes, ProdutoVariacaoCodigoHistorico::query()->count());
    }

    public function test_carga_inicial_sem_sku_cria_novas_variacoes_com_referencia_duplicada(): void
    {
        $this->autenticarComoDev();
        $arquivoPath = $this->criarPlanilhaCargaInicialFixture();

        $upload = new UploadedFile(
            $arquivoPath,
            'importacao-carga-inicial-fixture.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => $upload,
            'modo_carga_inicial' => true,
        ]);

        $response->assertCreated();
        $importacaoId = (int) $response->json('data.id');
        $this->assertSame('planilha_sierra_carga_inicial', $response->json('data.tipo'));
        $this->assertSame(4, (int) $response->json('data.linhas_total'));

        $previewResponse = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/preview");
        $previewResponse->assertOk();
        $this->assertSame(3, data_get($previewResponse->json(), 'data.preview.totais.linhas_que_gerariam_estoque'));
        $this->assertSame(1, data_get($previewResponse->json(), 'data.preview.totais.linhas_que_nao_gerariam_estoque'));

        $confirmacao = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/confirmar", [
            'modo_carga_inicial' => true,
        ]);
        $confirmacao->assertOk();

        $efetivacao = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/efetivar", [
            'modo_carga_inicial' => true,
        ]);
        $efetivacao->assertOk();
        $this->assertTrue((bool) $efetivacao->json('sucesso'));

        $linhasRefDup = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $importacaoId)
            ->where('codigo', 'REF-DUP-CARGA')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $linhasRefDup);
        $this->assertCount(3, $linhasRefDup->pluck('variacao_id_vinculada')->filter()->unique());
        $this->assertCount(4, ProdutoVariacao::query()->count());

        $linhaLoja = $linhasRefDup->firstWhere('localizacao', 'A-01-01');
        $this->assertNotNull($linhaLoja);
        $this->assertNotNull($linhaLoja->variacao_id_vinculada);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $linhaLoja->variacao_id_vinculada,
            'atributo' => 'madeira',
            'valor' => 'AC01',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $linhaLoja->variacao_id_vinculada,
            'atributo' => 'largura_cm',
            'valor' => '60',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $linhaLoja->variacao_id_vinculada,
            'atributo' => 'profundidade_cm',
            'valor' => '70',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $linhaLoja->variacao_id_vinculada,
            'atributo' => 'altura_cm',
            'valor' => '80',
        ]);

        $variacaoLoja = ProdutoVariacao::query()->findOrFail($linhaLoja->variacao_id_vinculada);
        $this->assertSame('60.00', (string) $variacaoLoja->dimensao_1);
        $this->assertSame('70.00', (string) $variacaoLoja->dimensao_2);
        $this->assertSame('80.00', (string) $variacaoLoja->dimensao_3);

        $linhaVendida = $linhasRefDup->firstWhere('status', 'Vendido');
        $this->assertNotNull($linhaVendida);
        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'ref_type' => 'importacao_normalizada_linha',
            'ref_id' => $linhaVendida->id,
        ]);

        $this->assertSame(
            3,
            EstoqueMovimentacao::query()
                ->where('ref_type', 'importacao_normalizada_linha')
                ->whereIn('ref_id', ImportacaoNormalizadaLinha::query()->where('importacao_id', $importacaoId)->pluck('id'))
                ->count()
        );
    }
}
