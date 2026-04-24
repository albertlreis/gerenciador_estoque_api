<?php

namespace Tests\Feature;

use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\ImportacaoNormalizada;
use App\Models\ImportacaoNormalizadaLinha;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
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

    private function criarPlanilhaTemporaria(array $sheets, string $prefix): string
    {
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

        $tempBase = tempnam(sys_get_temp_dir(), $prefix);
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

    private function criarPlanilhaFixture(): string
    {
        $headers = [
            'quantidade',
            'Data NF',
            'codigo',
            'localizacao',
            'Nome',
            'Largura (cm)',
            'Profundidade (cm)',
            'Altura (cm)',
            'Categoria',
            'Madeira',
            'Tec. 1',
            'Tec. 2',
            'Metal / Vidro',
            'Valor',
            'outlet',
            'status',
        ];

        $sheets = [
            'Sierra Loja' => [
                $headers,
                [
                    3,
                    '2026-03-10',
                    'COD-001',
                    'A-01-01',
                    'Sofa Alpha',
                    200,
                    90,
                    80,
                    'Estofados',
                    'MA01',
                    'LINHO',
                    '',
                    '',
                    5990.00,
                    '',
                    'Loja',
                ],
                [
                    2,
                    '2026-03-11',
                    'COD-001',
                    'A-01-02',
                    'Sofa Alpha',
                    200,
                    90,
                    80,
                    'Estofados',
                    'MA02',
                    'LINHO',
                    '',
                    '',
                    5990.00,
                    '',
                    'Brinde',
                ],
            ],
            'Depósito JB' => [
                $headers,
                [
                    1,
                    '2026-03-12',
                    'COD-001',
                    'B-02-01',
                    'Sofa Alpha',
                    200,
                    90,
                    80,
                    'Estofados',
                    'MA01',
                    'LINHO',
                    '',
                    '',
                    5990.00,
                    '',
                    'Depósito',
                ],
            ],
        ];

        return $this->criarPlanilhaTemporaria($sheets, 'importacao-normalizada-');
    }

    private function criarPlanilhaFixtureReimportacao(): string
    {
        $headers = [
            'quantidade',
            'Data NF',
            'codigo',
            'localizacao',
            'Nome',
            'Largura (cm)',
            'Profundidade (cm)',
            'Altura (cm)',
            'Categoria',
            'Madeira',
            'Tec. 1',
            'Tec. 2',
            'Metal / Vidro',
            'Valor',
            'outlet',
            'status',
        ];

        $sheets = [
            'Sierra Loja' => [
                $headers,
                [
                    3,
                    '2026-03-10',
                    'COD-001',
                    'A-01-01',
                    'Sofa Alpha',
                    200,
                    90,
                    80,
                    'Estofados',
                    'MA01',
                    'LINHO',
                    '',
                    '',
                    5990.00,
                    '',
                    'Loja',
                ],
                [
                    2,
                    '2026-03-11',
                    'COD-001',
                    'A-01-02',
                    'Sofa Alpha',
                    200,
                    90,
                    80,
                    'Estofados',
                    'MA02',
                    'LINHO',
                    '',
                    '',
                    5990.00,
                    '',
                    'Brinde',
                ],
                array_fill(0, count($headers), null),
            ],
            'Depósito JB' => [
                $headers,
                [
                    1,
                    '2026-03-12',
                    'COD-001',
                    'B-02-01',
                    'Sofa Alpha',
                    200,
                    90,
                    80,
                    'Estofados',
                    'MA01',
                    'LINHO',
                    '',
                    '',
                    5990.00,
                    '',
                    'Depósito',
                ],
            ],
        ];

        return $this->criarPlanilhaTemporaria($sheets, 'importacao-normalizada-reimportacao-');
    }

    private function criarPlanilhaCargaInicialFixture(): string
    {
        $headers = [
            'quantidade',
            'Data NF',
            'codigo',
            'localizacao',
            'nome',
            'Largura (cm)',
            'Profundidade (cm)',
            'Altura (cm)',
            'Categoria',
            'Madeira',
            'Tec. 1',
            'Tec. 2',
            'Metal / Vidro',
            'valor',
            'outlet',
            'status',
        ];

        $sheets = [
            'Sierra Loja' => [
                $headers,
                [
                    1,
                    '2026-03-12',
                    'POL-900',
                    'A-01-01',
                    'Poltrona Teste',
                    60,
                    70,
                    80,
                    'Poltrona',
                    'AC01',
                    '',
                    '',
                    '',
                    1000,
                    '',
                    'Loja',
                ],
                [
                    1,
                    '2026-03-12',
                    'POL-900',
                    'A-01-02',
                    'Poltrona Teste',
                    60,
                    70,
                    80,
                    'Poltrona',
                    'AC02',
                    '',
                    '',
                    '',
                    1100,
                    '',
                    'Depósito',
                ],
            ],
            'Adornos' => [
                ['codigo', 'nome', 'fornecedor', 'Unidade', 'Valor Unit', 'Status', 'Custo'],
                ['ADOR-001', 'Vaso Teste', 'Fornecedor Teste', 6, 250, 'Loja', 100],
            ],
        ];

        return $this->criarPlanilhaTemporaria($sheets, 'importacao-carga-inicial-');
    }

    public function test_pipeline_sierra_usa_campos_brutos_como_identidade_e_reaproveita_variacoes_por_atributos(): void
    {
        $this->autenticarComoDev();
        $arquivoPath = $this->criarPlanilhaFixture();

        $response = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => new UploadedFile(
                $arquivoPath,
                'importacao-sierra-fixture.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);

        $response->assertCreated();
        $importacaoId = (int) $response->json('data.id');

        $this->assertSame('planilha_sierra_carga_inicial', $response->json('data.tipo'));
        $this->assertSame(3, (int) $response->json('data.linhas_total'));
        $this->assertSame(0, (int) $response->json('data.linhas_pendentes_revisao'));
        $this->assertSame(0, (int) $response->json('data.linhas_com_conflito'));

        $linhas = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $importacaoId)
            ->orderBy('linha_planilha')
            ->get();

        $this->assertCount(3, $linhas);
        $this->assertSame(0, $linhas->whereNotNull('sku_interno')->count());
        $this->assertSame(0, $linhas->whereNotNull('chave_produto')->count());
        $this->assertSame(0, $linhas->whereNotNull('chave_variacao')->count());
        $this->assertSame(0, $linhas->whereNotNull('regra_categoria')->count());

        /** @var ImportacaoNormalizadaLinha $linhaLoja */
        $linhaLoja = $linhas->firstWhere('localizacao', 'A-01-01');
        /** @var ImportacaoNormalizadaLinha $linhaBrinde */
        $linhaBrinde = $linhas->firstWhere('localizacao', 'A-01-02');
        /** @var ImportacaoNormalizadaLinha $linhaDeposito */
        $linhaDeposito = $linhas->firstWhere('localizacao', 'B-02-01');

        $this->assertNotNull($linhaLoja);
        $this->assertNotNull($linhaBrinde);
        $this->assertNotNull($linhaDeposito);

        $this->assertSame('COD-001', $linhaLoja->codigo);
        $this->assertSame('COD-001', $linhaLoja->codigo_produto);
        $this->assertSame('Estofados', $linhaLoja->categoria_oficial);
        $this->assertSame('Sofa Alpha', $linhaLoja->nome_base_normalizado);
        $this->assertSame('2026-03-10', substr((string) $linhaLoja->data_entrada, 0, 10));
        $this->assertSame(200.0, (float) $linhaLoja->dimensao_1);
        $this->assertSame(90.0, (float) $linhaLoja->dimensao_2);
        $this->assertSame(80.0, (float) $linhaLoja->dimensao_3);
        $this->assertSame(3, (int) $linhaLoja->quantidade);

        $previewResponse = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/preview");
        $previewResponse->assertOk();
        $preview = $previewResponse->json('data.preview');

        $this->assertSame(3, data_get($preview, 'totais.linhas_total'));
        $this->assertSame(2, data_get($preview, 'totais.linhas_que_gerariam_estoque'));
        $this->assertSame(1, data_get($preview, 'totais.linhas_que_nao_gerariam_estoque'));
        $this->assertSame(0, data_get($preview, 'totais.linhas_com_conflito'));
        $this->assertSame(0, data_get($preview, 'totais.linhas_bloqueadas'));
        $this->assertSame(0, data_get($preview, 'totais.linhas_pendentes_revisao'));
        $this->assertSame(1, data_get($preview, 'totais.produtos_pais_novos'));
        $this->assertSame(2, data_get($preview, 'totais.variacoes_novas'));

        $confirmacao = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/confirmar");
        $confirmacao->assertOk();
        $this->assertTrue((bool) $confirmacao->json('sucesso'));

        $efetivacao = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/efetivar");
        $efetivacao->assertOk();
        $this->assertTrue((bool) $efetivacao->json('sucesso'));

        $linhaLoja->refresh();
        $linhaBrinde->refresh();
        $linhaDeposito->refresh();

        $this->assertNotNull($linhaLoja->variacao_id_vinculada);
        $this->assertSame($linhaLoja->variacao_id_vinculada, $linhaDeposito->variacao_id_vinculada);
        $this->assertNotSame($linhaLoja->variacao_id_vinculada, $linhaBrinde->variacao_id_vinculada);

        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'ref_type' => 'importacao_normalizada_linha',
            'ref_id' => $linhaBrinde->id,
        ]);

        /** @var Produto $produto */
        $produto = Produto::query()->where('codigo_produto', 'COD-001')->firstOrFail();
        $this->assertSame(1, Produto::query()->where('codigo_produto', 'COD-001')->count());
        $this->assertSame(2, ProdutoVariacao::query()->where('produto_id', $produto->id)->count());

        /** @var ProdutoVariacao $variacaoComEstoque */
        $variacaoComEstoque = ProdutoVariacao::query()->findOrFail($linhaLoja->variacao_id_vinculada);
        /** @var ProdutoVariacao $variacaoSemEstoque */
        $variacaoSemEstoque = ProdutoVariacao::query()->findOrFail($linhaBrinde->variacao_id_vinculada);

        $this->assertSame($produto->id, $variacaoComEstoque->produto_id);
        $this->assertSame($produto->id, $variacaoSemEstoque->produto_id);
        $this->assertNull($variacaoComEstoque->sku_interno);
        $this->assertNull($variacaoComEstoque->chave_variacao);
        $this->assertSame(200.0, (float) $variacaoComEstoque->dimensao_1);
        $this->assertSame(90.0, (float) $variacaoComEstoque->dimensao_2);
        $this->assertSame(80.0, (float) $variacaoComEstoque->dimensao_3);

        $this->assertSame(
            2,
            EstoqueMovimentacao::query()
                ->where('ref_type', 'importacao_normalizada_linha')
                ->whereIn('ref_id', $linhas->pluck('id'))
                ->count()
        );

        $estoquesVariacao = Estoque::query()->where('id_variacao', $variacaoComEstoque->id)->get();
        $this->assertSame(2, $estoquesVariacao->where('quantidade', '>', 0)->count());
        $this->assertSame(4, (int) $estoquesVariacao->sum('quantidade'));
        $this->assertCount(0, Estoque::query()->where('id_variacao', $variacaoSemEstoque->id)->where('quantidade', '>', 0)->get());

        $arquivoReimportacaoPath = $this->criarPlanilhaFixtureReimportacao();
        $segundaImportacao = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => new UploadedFile(
                $arquivoReimportacaoPath,
                'importacao-sierra-reimportacao.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);
        $segundaImportacao->assertCreated();
        $importacaoIdReimportada = (int) $segundaImportacao->json('data.id');

        $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoIdReimportada}/confirmar")->assertOk();
        $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoIdReimportada}/efetivar")->assertOk();

        $linhasReimportadas = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $importacaoIdReimportada)
            ->get()
            ->keyBy('localizacao');

        $this->assertSame($linhaLoja->produto_id_vinculada, $linhasReimportadas['A-01-01']->produto_id_vinculada);
        $this->assertSame($linhaLoja->variacao_id_vinculada, $linhasReimportadas['A-01-01']->variacao_id_vinculada);
        $this->assertSame($linhaBrinde->variacao_id_vinculada, $linhasReimportadas['A-01-02']->variacao_id_vinculada);
        $this->assertSame($linhaDeposito->variacao_id_vinculada, $linhasReimportadas['B-02-01']->variacao_id_vinculada);
        $this->assertSame(1, Produto::query()->where('codigo_produto', 'COD-001')->count());
        $this->assertSame(2, ProdutoVariacao::query()->where('produto_id', $produto->id)->count());
    }

    public function test_carga_inicial_sierra_usa_unidade_em_adornos_e_separa_variacoes_por_atributos(): void
    {
        $this->autenticarComoDev();
        $arquivoPath = $this->criarPlanilhaCargaInicialFixture();

        $response = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => new UploadedFile(
                $arquivoPath,
                'importacao-carga-inicial-fixture.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);

        $response->assertCreated();
        $importacaoId = (int) $response->json('data.id');

        $this->assertSame('planilha_sierra_carga_inicial', $response->json('data.tipo'));
        $this->assertSame(3, (int) $response->json('data.linhas_total'));

        $previewResponse = $this->getJson("/api/v1/importacoes/normalizadas/{$importacaoId}/preview");
        $previewResponse->assertOk();
        $this->assertSame(3, data_get($previewResponse->json(), 'data.preview.totais.linhas_que_gerariam_estoque'));
        $this->assertSame(0, data_get($previewResponse->json(), 'data.preview.totais.linhas_que_nao_gerariam_estoque'));

        /** @var ImportacaoNormalizadaLinha $linhaAdorno */
        $linhaAdorno = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $importacaoId)
            ->where('aba_origem', 'Adornos')
            ->firstOrFail();

        $this->assertSame('ADOR-001', $linhaAdorno->codigo);
        $this->assertSame('ADOR-001', $linhaAdorno->codigo_produto);
        $this->assertSame('Adornos', $linhaAdorno->categoria_oficial);
        $this->assertSame('Vaso Teste', $linhaAdorno->nome_base_normalizado);
        $this->assertSame(6, (int) $linhaAdorno->quantidade);
        $this->assertNull($linhaAdorno->sku_interno);

        $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/confirmar")->assertOk();
        $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoId}/efetivar")->assertOk();

        $linhasPoltrona = ImportacaoNormalizadaLinha::query()
            ->where('importacao_id', $importacaoId)
            ->where('codigo', 'POL-900')
            ->get();

        $this->assertCount(2, $linhasPoltrona);
        $this->assertCount(2, $linhasPoltrona->pluck('variacao_id_vinculada')->filter()->unique());

        $linhaAdorno->refresh();
        $this->assertNotNull($linhaAdorno->variacao_id_vinculada);

        $estoqueAdorno = Estoque::query()
            ->where('id_variacao', $linhaAdorno->variacao_id_vinculada)
            ->sum('quantidade');

        $this->assertSame(6, (int) $estoqueAdorno);
        $this->assertSame(
            3,
            EstoqueMovimentacao::query()
                ->where('ref_type', 'importacao_normalizada_linha')
                ->whereIn('ref_id', ImportacaoNormalizadaLinha::query()->where('importacao_id', $importacaoId)->pluck('id'))
                ->count()
        );
    }

    public function test_upload_bloqueia_staging_duplicado_por_arquivo_hash_e_retorna_409(): void
    {
        $this->autenticarComoDev();
        $arquivoPath = $this->criarPlanilhaFixture();

        $primeiroUpload = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => new UploadedFile(
                $arquivoPath,
                'importacao-duplicada.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);

        $primeiroUpload->assertCreated();
        $importacaoExistenteId = (int) $primeiroUpload->json('data.id');

        $segundoUpload = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => new UploadedFile(
                $arquivoPath,
                'importacao-duplicada.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);

        $segundoUpload->assertStatus(409);
        $segundoUpload->assertJsonPath('sucesso', false);
        $segundoUpload->assertJsonPath('data.importacao_existente.id', $importacaoExistenteId);
        $this->assertSame(1, ImportacaoNormalizada::query()->count());
    }

    public function test_upload_permanece_permitido_quando_importacao_igual_anterior_esta_cancelada(): void
    {
        $this->autenticarComoDev();
        $arquivoPath = $this->criarPlanilhaFixture();
        $arquivoHash = hash_file('sha256', $arquivoPath);

        ImportacaoNormalizada::create([
            'tipo' => 'planilha_sierra_carga_inicial',
            'arquivo_nome' => 'importacao-anterior.xlsx',
            'arquivo_hash' => $arquivoHash,
            'usuario_id' => null,
            'status' => 'cancelada',
        ]);

        $response = $this->post('/api/v1/importacoes/normalizadas', [
            'arquivo' => new UploadedFile(
                $arquivoPath,
                'importacao-nova.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);

        $response->assertCreated();
        $this->assertSame(2, ImportacaoNormalizada::query()->count());
    }

    public function test_confirmar_retorna_409_quando_ja_existe_importacao_mesmo_hash_confirmada(): void
    {
        $this->autenticarComoDev();

        $arquivoHash = str_repeat('a', 64);
        ImportacaoNormalizada::create([
            'tipo' => 'planilha_sierra_carga_inicial',
            'arquivo_nome' => 'existente.xlsx',
            'arquivo_hash' => $arquivoHash,
            'usuario_id' => null,
            'status' => 'confirmada',
            'confirmado_em' => now(),
        ]);

        $importacaoDuplicada = ImportacaoNormalizada::create([
            'tipo' => 'planilha_sierra_carga_inicial',
            'arquivo_nome' => 'duplicada.xlsx',
            'arquivo_hash' => $arquivoHash,
            'usuario_id' => null,
            'status' => 'pronta_para_efetivar',
            'preview_resumo' => ['totais' => ['linhas_validas_para_efetivacao' => 1]],
        ]);

        $response = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoDuplicada->id}/confirmar");

        $response->assertStatus(409);
        $response->assertJsonPath('sucesso', false);
        $response->assertJsonPath('data.importacao_existente.status', 'confirmada');
    }

    public function test_efetivar_retorna_409_quando_ja_existe_importacao_mesmo_hash_efetivada(): void
    {
        $this->autenticarComoDev();

        $arquivoHash = str_repeat('b', 64);
        ImportacaoNormalizada::create([
            'tipo' => 'planilha_sierra_carga_inicial',
            'arquivo_nome' => 'existente.xlsx',
            'arquivo_hash' => $arquivoHash,
            'usuario_id' => null,
            'status' => 'efetivada',
            'confirmado_em' => now()->subMinute(),
            'efetivado_em' => now()->subSecond(),
        ]);

        $importacaoDuplicada = ImportacaoNormalizada::create([
            'tipo' => 'planilha_sierra_carga_inicial',
            'arquivo_nome' => 'duplicada.xlsx',
            'arquivo_hash' => $arquivoHash,
            'usuario_id' => null,
            'status' => 'confirmada',
            'preview_resumo' => ['totais' => ['linhas_validas_para_efetivacao' => 1]],
            'confirmado_em' => now(),
        ]);

        $response = $this->postJson("/api/v1/importacoes/normalizadas/{$importacaoDuplicada->id}/efetivar");

        $response->assertStatus(409);
        $response->assertJsonPath('sucesso', false);
        $response->assertJsonPath('data.importacao_existente.status', 'efetivada');
    }
}
