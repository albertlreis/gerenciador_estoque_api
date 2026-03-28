<?php

namespace App\Services\Import;

use App\Enums\ImportacaoNormalizadaConflitoSeveridade;
use App\Enums\ImportacaoNormalizadaLinhaStatus;
use App\Enums\ImportacaoNormalizadaStatus;
use App\Enums\StatusRevisaoCadastro;
use App\Models\ImportacaoNormalizada;
use App\Models\ImportacaoNormalizadaConflito;
use App\Models\ImportacaoNormalizadaLinha;
use App\Models\ImportacaoNormalizadaRevisao;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

final class ImportacaoNormalizadaSierraService
{
    /**
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'quantidade' => 'quantidade',
        'data entrada' => 'data_entrada',
        'codigo' => 'codigo',
        'referencia' => 'codigo',
        'valor unit' => 'valor',
        'localizacao' => 'localizacao',
        'nome' => 'nome',
        'categoria' => 'categoria',
        'fornecedor' => 'fornecedor',
        'unidade' => 'unidade',
        'madeira' => 'madeira',
        'tec. 1' => 'tecido_1',
        'tec 1' => 'tecido_1',
        'tecido 1' => 'tecido_1',
        'tec. 2' => 'tecido_2',
        'tec 2' => 'tecido_2',
        'tecido 2' => 'tecido_2',
        'metal / vidro' => 'metal_vidro',
        'metal/vidro' => 'metal_vidro',
        'metal vidro' => 'metal_vidro',
        'valor' => 'valor',
        'outlet' => 'outlet',
        'status' => 'status',
        'dimensao 1 (cm)' => 'dimensao_1',
        'dimensao 2 (cm)' => 'dimensao_2',
        'dimensao 3 (cm)' => 'dimensao_3',
        'diametro cm' => 'diametro_cm',
        'largura cm' => 'largura_cm',
        'profundidade cm' => 'profundidade_cm',
        'altura cm' => 'altura_cm',
        'comprimento cm' => 'comprimento_cm',
        'espessura cm' => 'espessura_cm',
        'nome normalizado' => 'nome_normalizado',
        'categoria normalizada' => 'categoria_normalizada',
        'codigo origem' => 'codigo_origem',
        'codigo modelo' => 'codigo_modelo',
        'nome base normalizado' => 'nome_base_normalizado',
        'codigo produto' => 'codigo_produto',
        'chave produto' => 'chave_produto',
        'chave variacao' => 'chave_variacao',
        'sku interno' => 'sku_interno',
        'conflito codigo' => 'conflito_codigo',
        'categoria oficial' => 'categoria_oficial',
        'regra categoria' => 'regra_categoria',
        'cor extraida' => 'cor',
        'lado extraido' => 'lado',
        'material oficial' => 'material_oficial',
        'acabamento oficial' => 'acabamento_oficial',
        'fornecedor' => 'fornecedor',
        'custo' => 'custo',
    ];

    /**
     * @var string[]
     */
    private const REQUIRED_HEADERS_PADRAO = [
        'codigo',
        'nome',
        'codigo_produto',
        'chave_produto',
        'chave_variacao',
        'sku_interno',
        'categoria_oficial',
        'nome_base_normalizado',
        'regra_categoria',
        'quantidade',
        'status',
    ];

    /**
     * @var string[]
     */
    private const REQUIRED_HEADERS_CARGA_INICIAL = [
        'codigo',
        'nome',
        'status',
    ];

    /**
     * @var string[]
     */
    private const SUPPORTED_RULES = [
        'GERAL',
        'ESTOFADOS',
        'MESAS',
        'ADORNOS',
        'OMBRELONES',
    ];

    public function criarStaging(
        UploadedFile $arquivo,
        ?int $usuarioId = null,
        bool $modoCargaInicial = false
    ): ImportacaoNormalizada
    {
        $hash = hash_file('sha256', $arquivo->getRealPath());

        $importacao = ImportacaoNormalizada::create([
            'tipo' => $modoCargaInicial ? 'planilha_sierra_carga_inicial' : 'planilha_sierra_normalizada',
            'arquivo_nome' => $arquivo->getClientOriginalName(),
            'arquivo_hash' => $hash,
            'usuario_id' => $usuarioId,
            'status' => ImportacaoNormalizadaStatus::RECEBIDA,
        ]);

        Log::info('Importação normalizada: início do staging.', [
            'importacao_id' => $importacao->id,
            'arquivo_nome' => $importacao->arquivo_nome,
            'arquivo_hash' => $importacao->arquivo_hash,
            'usuario_id' => $usuarioId,
        ]);

        try {
            DB::transaction(function () use ($arquivo, $importacao, $modoCargaInicial): void {
                $spreadsheet = IOFactory::load($arquivo->getRealPath());
                $abas = [];
                $modoCargaInicialDetectado = $modoCargaInicial;
                $metricas = [
                    'regras_categoria' => [],
                    'status' => [],
                ];

                foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                    $sheetName = trim((string) $worksheet->getTitle());
                    if ($this->shouldSkipSheet($sheetName)) {
                        continue;
                    }

                    $rows = $worksheet->toArray(null, true, true, true);
                    if (!$rows || count($rows) < 2) {
                        continue;
                    }

                    $headerRow = array_shift($rows);
                    $headerMap = $this->mapHeaderCanonical($headerRow);
                    $modoCargaInicialDaAba = $modoCargaInicial || $this->deveUsarModoCargaInicial($headerMap);
                    $modoCargaInicialDetectado = $modoCargaInicialDetectado || $modoCargaInicialDaAba;

                    $this->assertRequiredHeaders($headerMap, $sheetName, $modoCargaInicialDaAba);
                    $abas[] = $sheetName;

                    $linhaPlanilha = 2;
                    foreach ($rows as $row) {
                        $raw = $this->rowToRaw($row, $headerRow);
                        $canonical = $this->rowToCanonical($row, $headerMap);

                        if ($this->isRowEmpty($canonical, $raw)) {
                            $linhaPlanilha++;
                            continue;
                        }

                        $payload = $this->buildRowPayload($sheetName, $linhaPlanilha, $raw, $canonical, $modoCargaInicialDaAba);
                        /** @var ImportacaoNormalizadaLinha $linha */
                        $linha = $importacao->linhas()->create($payload);

                        foreach ($this->buildConflictsForRow($linha) as $conflict) {
                            $importacao->conflitos()->create([
                                'linha_id' => $linha->id,
                                ...$conflict,
                            ]);
                        }

                        $regra = (string) ($payload['regra_categoria'] ?? '');
                        if ($regra !== '') {
                            $metricas['regras_categoria'][$regra] = ($metricas['regras_categoria'][$regra] ?? 0) + 1;
                        }

                        $status = (string) ($payload['status_normalizado'] ?? ($payload['status'] ?? ''));
                        if ($status !== '') {
                            $metricas['status'][$status] = ($metricas['status'][$status] ?? 0) + 1;
                        }

                        $linhaPlanilha++;
                    }
                }

                $this->registrarConflitosDeConsistencia($importacao);

                if ($modoCargaInicialDetectado && $importacao->tipo !== 'planilha_sierra_carga_inicial') {
                    $importacao->tipo = 'planilha_sierra_carga_inicial';
                }

                $this->atualizarResumoImportacao($importacao, [
                    'abas_processadas' => array_values(array_unique($abas)),
                    'metricas' => $metricas,
                    'observacoes' => $modoCargaInicialDetectado
                        ? 'origem_importacao=carga_inicial_sierra'
                        : 'origem_importacao=importacao_normalizada',
                ]);
            });
        } catch (\Throwable $e) {
            $importacao->forceFill([
                'status' => ImportacaoNormalizadaStatus::ERRO,
                'observacoes' => $e->getMessage(),
            ])->save();

            Log::error('Importação normalizada: falha no staging.', [
                'importacao_id' => $importacao->id,
                'arquivo_nome' => $importacao->arquivo_nome,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('Importação normalizada: staging concluído.', [
            'importacao_id' => $importacao->id,
            'status' => $importacao->fresh()?->status?->value ?? $importacao->fresh()?->status,
            'linhas_total' => $importacao->fresh()?->linhas_total,
            'linhas_com_conflito' => $importacao->fresh()?->linhas_com_conflito,
            'linhas_pendentes_revisao' => $importacao->fresh()?->linhas_pendentes_revisao,
            'linhas_com_erro' => $importacao->fresh()?->linhas_com_erro,
        ]);

        return $importacao->fresh();
    }

    public function revisarLinha(ImportacaoNormalizadaLinha $linha, array $payload, ?int $usuarioId = null): ImportacaoNormalizadaLinha
    {
        return DB::transaction(function () use ($linha, $payload, $usuarioId) {
            $statusAnterior = $linha->status_revisao?->value ?? $linha->status_revisao;

            $linha->status_revisao = $payload['status_revisao'];
            $linha->decisao_manual = $payload['decisao'];
            $linha->motivo_decisao_manual = $payload['motivo'] ?? null;
            if (array_key_exists('produto_id_vinculado', $payload)) {
                $linha->produto_id_vinculado = $payload['produto_id_vinculado'];
            }
            if (array_key_exists('variacao_id_vinculada', $payload)) {
                $linha->variacao_id_vinculada = $payload['variacao_id_vinculada'];
            }
            $linha->status_processamento = $this->resolverStatusProcessamentoDaLinha($linha, $payload['status_revisao']);
            $linha->save();

            if (in_array($payload['status_revisao'], [
                StatusRevisaoCadastro::APROVADO->value,
                StatusRevisaoCadastro::REJEITADO->value,
            ], true)) {
                $linha->conflitos()
                    ->where('status_revisao', StatusRevisaoCadastro::PENDENTE_REVISAO->value)
                    ->update([
                        'status_revisao' => $payload['status_revisao'],
                        'decisao_manual' => 'resolvido_pela_revisao_da_linha',
                        'motivo_decisao_manual' => $payload['motivo'] ?? null,
                        'resolvido_por' => $usuarioId,
                        'resolvido_em' => now(),
                    ]);
            }

            ImportacaoNormalizadaRevisao::create([
                'importacao_id' => $linha->importacao_id,
                'linha_id' => $linha->id,
                'produto_id' => $linha->produto_id_vinculado,
                'variacao_id' => $linha->variacao_id_vinculada,
                'status_anterior' => $statusAnterior,
                'status_novo' => $payload['status_revisao'],
                'decisao' => $payload['decisao'],
                'motivo' => $payload['motivo'] ?? null,
                'detalhes' => array_filter([
                    'detalhes' => $payload['detalhes'] ?? null,
                    'produto_id_vinculado' => $payload['produto_id_vinculado'] ?? null,
                    'variacao_id_vinculada' => $payload['variacao_id_vinculada'] ?? null,
                ], fn ($value) => $value !== null),
                'usuario_id' => $usuarioId,
            ]);

            $this->atualizarResumoImportacao($linha->importacao);

            return $linha->fresh(['conflitos']);
        });
    }

    public function revisarConflito(ImportacaoNormalizadaConflito $conflito, array $payload, ?int $usuarioId = null): ImportacaoNormalizadaConflito
    {
        return DB::transaction(function () use ($conflito, $payload, $usuarioId) {
            $statusAnterior = $conflito->status_revisao?->value ?? $conflito->status_revisao;

            $conflito->status_revisao = $payload['status_revisao'];
            $conflito->decisao_manual = $payload['decisao'];
            $conflito->motivo_decisao_manual = $payload['motivo'] ?? null;
            $conflito->resolvido_por = $usuarioId;
            $conflito->resolvido_em = now();
            $conflito->save();

            ImportacaoNormalizadaRevisao::create([
                'importacao_id' => $conflito->importacao_id,
                'linha_id' => $conflito->linha_id,
                'conflito_id' => $conflito->id,
                'produto_id' => $conflito->linha?->produto_id_vinculado,
                'variacao_id' => $conflito->linha?->variacao_id_vinculada,
                'status_anterior' => $statusAnterior,
                'status_novo' => $payload['status_revisao'],
                'decisao' => $payload['decisao'],
                'motivo' => $payload['motivo'] ?? null,
                'detalhes' => $payload['detalhes'] ?? null,
                'usuario_id' => $usuarioId,
            ]);

            if ($conflito->linha) {
                $linha = $conflito->linha->fresh('conflitos');
                $linha->status_processamento = $this->resolverStatusProcessamentoDaLinha(
                    $linha,
                    $linha->status_revisao?->value ?? $linha->status_revisao
                );
                $linha->save();
            }

            $this->atualizarResumoImportacao($conflito->importacao);

            return $conflito->fresh();
        });
    }

    private function atualizarResumoImportacao(ImportacaoNormalizada $importacao, array $extra = []): void
    {
        $linhas = $importacao->linhas();
        $linhasTotal = (clone $linhas)->count();
        $linhasComErro = (clone $linhas)
            ->where('status_processamento', ImportacaoNormalizadaLinhaStatus::ERRO->value)
            ->count();
        $linhasPendentes = (clone $linhas)
            ->where('status_revisao', StatusRevisaoCadastro::PENDENTE_REVISAO->value)
            ->count();
        $linhasComConflito = $importacao->conflitos()
            ->distinct('linha_id')
            ->whereNotNull('linha_id')
            ->count('linha_id');
        $linhasStaged = (clone $linhas)
            ->where('status_processamento', '!=', ImportacaoNormalizadaLinhaStatus::ERRO->value)
            ->count();

        $status = ImportacaoNormalizadaStatus::STAGED;
        if ($linhasComErro > 0) {
            $status = ImportacaoNormalizadaStatus::ERRO;
        } elseif ($linhasPendentes > 0 || $linhasComConflito > 0) {
            $status = ImportacaoNormalizadaStatus::EM_REVISAO;
        } elseif ($linhasTotal > 0) {
            $status = ImportacaoNormalizadaStatus::PRONTA_PARA_EFETIVAR;
        }

        $importacao->fill(array_merge([
            'status' => $status,
            'linhas_total' => $linhasTotal,
            'linhas_staged' => $linhasStaged,
            'linhas_com_conflito' => $linhasComConflito,
            'linhas_pendentes_revisao' => $linhasPendentes,
            'linhas_com_erro' => $linhasComErro,
        ], $extra))->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRowPayload(
        string $sheetName,
        int $linhaPlanilha,
        array $raw,
        array $canonical,
        bool $modoCargaInicial
    ): array
    {
        $codigoBase = $this->toText($canonical['codigo'] ?? null);
        $codigoProduto = $this->toText($canonical['codigo_produto'] ?? null);
        $skuInterno = $this->toText($canonical['sku_interno'] ?? null);
        $categoriaBase = $this->toText($canonical['categoria'] ?? null);
        $categoriaOficial = $this->toText($canonical['categoria_oficial'] ?? null);
        $nomeBase = $this->toText($canonical['nome_base_normalizado'] ?? null);
        $nomeOriginal = $this->toText($canonical['nome'] ?? null);
        $regraCategoria = Str::upper($this->toText($canonical['regra_categoria'] ?? null));

        if ($modoCargaInicial) {
            if ($codigoProduto === '' && $codigoBase !== '') {
                $codigoProduto = $codigoBase;
            }
            if ($categoriaOficial === '') {
                $categoriaOficial = $categoriaBase !== '' ? $categoriaBase : $sheetName;
            }
            if ($nomeBase === '' && $nomeOriginal !== '') {
                $nomeBase = $nomeOriginal;
            }
            if ($regraCategoria === '') {
                $regraCategoria = Str::contains($this->normalizarTextoComparacao($sheetName), 'adornos')
                    ? 'ADORNOS'
                    : 'GERAL';
            }
        }
        $status = $this->toText($canonical['status'] ?? null);
        $statusNormalizado = $this->normalizarStatus($status);
        $geraEstoque = in_array($statusNormalizado, ['Loja', 'Depósito'], true);
        $motivoSemEstoque = !$geraEstoque
            ? ($statusNormalizado !== null
                ? 'Status não elegível para estoque: ' . $statusNormalizado
                : 'Status ausente para definição de estoque.')
            : null;

        $dim1 = $this->toDecimal(
            $canonical['dimensao_1']
                ?? $canonical['largura_cm']
                ?? $canonical['diametro_cm']
                ?? $canonical['comprimento_cm']
                ?? null
        );
        $dim2 = $this->toDecimal(
            $canonical['dimensao_2']
                ?? $canonical['profundidade_cm']
                ?? $canonical['espessura_cm']
                ?? null
        );
        $dim3 = $this->toDecimal(
            $canonical['dimensao_3']
                ?? $canonical['altura_cm']
                ?? null
        );
        $chaveProdutoCalculada = $this->montarChaveProduto($categoriaOficial, $nomeBase);
        $chaveVariacaoCalculada = $this->montarChaveVariacao(
            $chaveProdutoCalculada,
            $this->toText($canonical['cor'] ?? null),
            $dim1,
            $dim2,
            $dim3,
            $this->toText($canonical['lado'] ?? null),
            $this->toText($canonical['material_oficial'] ?? null),
            $this->toText($canonical['acabamento_oficial'] ?? null),
        );

        $avisos = [];
        $erros = [];
        $divergencias = [];

        if (!$modoCargaInicial && $codigoProduto === '') {
            $erros[] = 'Código produto ausente.';
        }
        if (!$modoCargaInicial && $skuInterno === '') {
            $erros[] = 'SKU interno ausente.';
        }
        if (!$modoCargaInicial && $categoriaOficial === '') {
            $erros[] = 'Categoria oficial ausente.';
        }
        if (!$modoCargaInicial && $nomeBase === '') {
            $erros[] = 'Nome base normalizado ausente.';
        }
        if ($this->toText($canonical['chave_produto'] ?? null) !== ''
            && $chaveProdutoCalculada !== null
            && $this->normalizarTextoComparacao((string) $canonical['chave_produto']) !== $this->normalizarTextoComparacao($chaveProdutoCalculada)
        ) {
            $divergencias[] = 'Chave produto divergente da composição oficial.';
        }
        if ($this->toText($canonical['chave_variacao'] ?? null) !== ''
            && $chaveVariacaoCalculada !== null
            && $this->normalizarTextoComparacao((string) $canonical['chave_variacao']) !== $this->normalizarTextoComparacao($chaveVariacaoCalculada)
        ) {
            $divergencias[] = 'Chave variação divergente da composição oficial.';
        }

        if ($regraCategoria !== '' && !in_array($regraCategoria, self::SUPPORTED_RULES, true)) {
            $avisos[] = 'Regra de categoria fora do conjunto suportado: ' . $regraCategoria;
        }
        if (!$geraEstoque && $this->toInt($canonical['quantidade'] ?? null) > 0) {
            $avisos[] = 'Linha com quantidade positiva, mas status não elegível para estoque.';
        }

        $pendenteRevisao = !empty($erros)
            || !empty($divergencias)
            || $this->toBool($canonical['conflito_codigo'] ?? null);

        $statusRevisao = $pendenteRevisao
            ? StatusRevisaoCadastro::PENDENTE_REVISAO
            : StatusRevisaoCadastro::NAO_REVISADO;

        $statusProcessamento = !empty($erros)
            ? ImportacaoNormalizadaLinhaStatus::ERRO
            : ($pendenteRevisao
                ? ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO
                : ImportacaoNormalizadaLinhaStatus::AGUARDANDO_EFETIVACAO);

        return [
            'aba_origem' => $sheetName,
            'linha_planilha' => $linhaPlanilha,
            'hash_linha' => sha1($sheetName . '|' . $linhaPlanilha . '|' . json_encode($raw, JSON_UNESCAPED_UNICODE)),
            'dados_brutos' => $raw,
            'dados_normalizados' => [
                'chave_produto_calculada' => $chaveProdutoCalculada,
                'chave_variacao_calculada' => $chaveVariacaoCalculada,
                'status_normalizado' => $statusNormalizado,
                'gera_estoque' => $geraEstoque,
            ],
            'codigo' => $codigoBase !== '' ? $codigoBase : null,
            'codigo_origem' => $this->toNullableText($canonical['codigo_origem'] ?? ($canonical['codigo'] ?? null)),
            'codigo_modelo' => $this->toNullableText($canonical['codigo_modelo'] ?? ($canonical['codigo'] ?? null)),
            'nome' => $this->toNullableText($canonical['nome'] ?? null),
            'nome_normalizado' => $this->toNullableText($canonical['nome_normalizado'] ?? null),
            'nome_base_normalizado' => $nomeBase !== '' ? $nomeBase : null,
            'categoria' => $this->toNullableText($canonical['categoria'] ?? null),
            'categoria_normalizada' => $this->toNullableText($canonical['categoria_normalizada'] ?? null),
            'categoria_oficial' => $categoriaOficial !== '' ? $categoriaOficial : null,
            'codigo_produto' => $codigoProduto !== '' ? $codigoProduto : null,
            'chave_produto' => $this->toNullableText($canonical['chave_produto'] ?? null),
            'chave_produto_calculada' => $chaveProdutoCalculada,
            'chave_variacao' => $this->toNullableText($canonical['chave_variacao'] ?? null),
            'chave_variacao_calculada' => $chaveVariacaoCalculada,
            'sku_interno' => $skuInterno !== '' ? $skuInterno : null,
            'conflito_codigo' => $this->toBool($canonical['conflito_codigo'] ?? null),
            'regra_categoria' => $regraCategoria !== '' ? $regraCategoria : null,
            'dimensao_1' => $dim1,
            'dimensao_2' => $dim2,
            'dimensao_3' => $dim3,
            'cor' => $this->toNullableText($canonical['cor'] ?? null),
            'lado' => $this->toNullableText($canonical['lado'] ?? null),
            'material_oficial' => $this->toNullableText($canonical['material_oficial'] ?? null),
            'acabamento_oficial' => $this->toNullableText($canonical['acabamento_oficial'] ?? null),
            'quantidade' => $this->toInt($canonical['quantidade'] ?? null) ?? ($modoCargaInicial ? 1 : null),
            'status' => $status !== '' ? $status : null,
            'status_normalizado' => $statusNormalizado,
            'gera_estoque' => $geraEstoque,
            'motivo_sem_estoque' => $motivoSemEstoque,
            'localizacao' => $this->toNullableText($canonical['localizacao'] ?? null),
            'data_entrada' => $this->toDateFlexible($canonical['data_entrada'] ?? null),
            'valor' => $this->toDecimal($canonical['valor'] ?? null),
            'custo' => $this->toDecimal($canonical['custo'] ?? null),
            'outlet' => $this->toBool($canonical['outlet'] ?? null),
            'fornecedor' => $this->toNullableText($canonical['fornecedor'] ?? null),
            'avisos' => $avisos,
            'erros' => $erros,
            'divergencias' => $divergencias,
            'status_revisao' => $statusRevisao,
            'status_processamento' => $statusProcessamento,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildConflictsForRow(ImportacaoNormalizadaLinha $linha): array
    {
        $conflitos = [];

        if ($linha->conflito_codigo) {
            $conflitos[] = [
                'tipo' => 'conflito_codigo_planilha',
                'campo' => 'conflito_codigo',
                'severidade' => ImportacaoNormalizadaConflitoSeveridade::CONFLITO,
                'descricao' => 'A planilha marcou a linha com conflito de código.',
                'valor_informado' => 'SIM',
                'valor_calculado' => null,
                'detalhes' => ['sku_interno' => $linha->sku_interno, 'codigo_origem' => $linha->codigo_origem],
                'status_revisao' => StatusRevisaoCadastro::PENDENTE_REVISAO,
            ];
        }

        foreach ($linha->erros ?? [] as $erro) {
            $conflitos[] = [
                'tipo' => 'erro_estrutural',
                'campo' => null,
                'severidade' => ImportacaoNormalizadaConflitoSeveridade::BLOQUEANTE,
                'descricao' => $erro,
                'valor_informado' => null,
                'valor_calculado' => null,
                'detalhes' => ['linha_planilha' => $linha->linha_planilha],
                'status_revisao' => StatusRevisaoCadastro::PENDENTE_REVISAO,
            ];
        }

        foreach ($linha->divergencias ?? [] as $divergencia) {
            $campo = str_contains((string) $divergencia, 'produto') ? 'chave_produto' : 'chave_variacao';

            $conflitos[] = [
                'tipo' => 'divergencia_normalizacao',
                'campo' => $campo,
                'severidade' => ImportacaoNormalizadaConflitoSeveridade::AVISO,
                'descricao' => $divergencia,
                'valor_informado' => $campo === 'chave_produto'
                    ? $linha->chave_produto
                    : $linha->chave_variacao,
                'valor_calculado' => $campo === 'chave_produto'
                    ? $linha->chave_produto_calculada
                    : $linha->chave_variacao_calculada,
                'detalhes' => [
                    'chave_produto' => $linha->chave_produto,
                    'chave_produto_calculada' => $linha->chave_produto_calculada,
                    'chave_variacao' => $linha->chave_variacao,
                    'chave_variacao_calculada' => $linha->chave_variacao_calculada,
                ],
                'status_revisao' => StatusRevisaoCadastro::PENDENTE_REVISAO,
            ];
        }

        return $conflitos;
    }

    private function registrarConflitosDeConsistencia(ImportacaoNormalizada $importacao): void
    {
        if ((string) $importacao->tipo === 'planilha_sierra_carga_inicial') {
            return;
        }

        $linhas = $importacao->linhas()->get();

        $this->registrarConflitosPorSkuInterno($importacao, $linhas);
        $this->registrarConflitosPorCodigoProduto($importacao, $linhas);
    }

    private function registrarConflitosPorSkuInterno(ImportacaoNormalizada $importacao, Collection $linhas): void
    {
        $linhas->filter(fn (ImportacaoNormalizadaLinha $linha) => !empty($linha->sku_interno))
            ->groupBy('sku_interno')
            ->each(function (Collection $grupo) use ($importacao) {
                $chavesVariacao = $grupo
                    ->map(fn (ImportacaoNormalizadaLinha $linha) => $linha->chave_variacao ?: $linha->chave_variacao_calculada)
                    ->filter()
                    ->unique()
                    ->values();
                $codigosProduto = $grupo->pluck('codigo_produto')->filter()->unique()->values();

                if ($chavesVariacao->count() <= 1 && $codigosProduto->count() <= 1) {
                    return;
                }

                foreach ($grupo as $linha) {
                    $this->registrarConflitoSintetico(
                        $importacao,
                        $linha,
                        'sku_interno_multiplas_identidades',
                        ImportacaoNormalizadaConflitoSeveridade::BLOQUEANTE,
                        'O mesmo SKU interno apareceu associado a mais de uma identidade de produto/variação no staging.',
                        [
                            'sku_interno' => $linha->sku_interno,
                            'chaves_variacao' => $chavesVariacao->all(),
                            'codigos_produto' => $codigosProduto->all(),
                        ]
                    );
                }
            });
    }

    private function registrarConflitosPorCodigoProduto(ImportacaoNormalizada $importacao, Collection $linhas): void
    {
        if ((string) $importacao->tipo === 'planilha_sierra_carga_inicial') {
            return;
        }

        $linhas->filter(fn (ImportacaoNormalizadaLinha $linha) => !empty($linha->codigo_produto))
            ->groupBy('codigo_produto')
            ->each(function (Collection $grupo) use ($importacao) {
                $chavesProduto = $grupo
                    ->map(fn (ImportacaoNormalizadaLinha $linha) => $linha->chave_produto ?: $linha->chave_produto_calculada)
                    ->filter()
                    ->unique()
                    ->values();
                if ($chavesProduto->count() <= 1) {
                    return;
                }

                foreach ($grupo as $linha) {
                    $this->registrarConflitoSintetico(
                        $importacao,
                        $linha,
                        'codigo_produto_multiplas_chaves',
                        ImportacaoNormalizadaConflitoSeveridade::BLOQUEANTE,
                        'O mesmo código de produto apareceu associado a mais de uma chave de produto.',
                        [
                            'codigo_produto' => $linha->codigo_produto,
                            'chaves_produto' => $chavesProduto->all(),
                        ]
                    );
                }
            });
    }

    private function registrarConflitoSintetico(
        ImportacaoNormalizada $importacao,
        ImportacaoNormalizadaLinha $linha,
        string $tipo,
        ImportacaoNormalizadaConflitoSeveridade $severidade,
        string $descricao,
        array $detalhes
    ): void {
        $jaExiste = $linha->conflitos()
            ->where('tipo', $tipo)
            ->exists();

        if ($jaExiste) {
            return;
        }

        $importacao->conflitos()->create([
            'linha_id' => $linha->id,
            'tipo' => $tipo,
            'campo' => null,
            'severidade' => $severidade,
            'descricao' => $descricao,
            'detalhes' => $detalhes,
            'status_revisao' => StatusRevisaoCadastro::PENDENTE_REVISAO,
        ]);

        $linha->status_revisao = StatusRevisaoCadastro::PENDENTE_REVISAO;
        $linha->status_processamento = ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO;
        $linha->save();
    }

    private function resolverStatusProcessamentoDaLinha(
        ImportacaoNormalizadaLinha $linha,
        string $statusRevisao
    ): ImportacaoNormalizadaLinhaStatus {
        if ($statusRevisao === StatusRevisaoCadastro::REJEITADO->value) {
            return ImportacaoNormalizadaLinhaStatus::IGNORADA;
        }

        if ($statusRevisao !== StatusRevisaoCadastro::APROVADO->value) {
            return ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO;
        }

        $temConflitoPendente = $linha->conflitos()
            ->whereNotIn('status_revisao', [
                StatusRevisaoCadastro::APROVADO->value,
                StatusRevisaoCadastro::REJEITADO->value,
            ])
            ->exists();

        return $temConflitoPendente
            ? ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO
            : ImportacaoNormalizadaLinhaStatus::AGUARDANDO_EFETIVACAO;
    }

    private function mapHeaderCanonical(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $col => $header) {
            $normalized = $this->normalizarTextoComparacao((string) $header);
            if ($normalized === '') {
                continue;
            }

            $normalized = str_replace(['_', ':'], ' ', $normalized);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim((string) $normalized);

            if (isset(self::HEADER_ALIASES[$normalized])) {
                $map[self::HEADER_ALIASES[$normalized]] = $col;
            }
        }

        return $map;
    }

    private function assertRequiredHeaders(array $headerMap, string $sheetName, bool $modoCargaInicial): void
    {
        $requiredHeaders = $modoCargaInicial
            ? self::REQUIRED_HEADERS_CARGA_INICIAL
            : self::REQUIRED_HEADERS_PADRAO;

        $missing = array_values(array_diff($requiredHeaders, array_keys($headerMap)));

        if (!empty($missing)) {
            throw new \RuntimeException(
                sprintf(
                    'A aba "%s" não contém todas as colunas obrigatórias da importação normalizada: %s',
                    $sheetName,
                    implode(', ', $missing)
                )
            );
        }
    }

    private function deveUsarModoCargaInicial(array $headerMap): bool
    {
        $keys = array_keys($headerMap);
        $temCabecalhoCargaInicial = empty(array_diff(self::REQUIRED_HEADERS_CARGA_INICIAL, $keys));
        $faltamCamposNormalizados = !empty(array_diff(self::REQUIRED_HEADERS_PADRAO, $keys));

        return $temCabecalhoCargaInicial && $faltamCamposNormalizados;
    }

    private function rowToRaw(array $row, array $headerRow): array
    {
        $raw = [];
        foreach ($headerRow as $col => $header) {
            $key = trim((string) $header);
            if ($key === '') {
                continue;
            }
            $raw[$key] = $row[$col] ?? null;
        }

        return $raw;
    }

    private function rowToCanonical(array $row, array $headerMap): array
    {
        $canonical = [];
        foreach ($headerMap as $key => $col) {
            $canonical[$key] = $row[$col] ?? null;
        }

        return $canonical;
    }

    private function isRowEmpty(array $canonical, array $raw): bool
    {
        foreach (array_merge($canonical, $raw) as $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            return false;
        }

        return true;
    }

    private function shouldSkipSheet(string $name): bool
    {
        $normalized = $this->normalizarTextoComparacao($name);

        return Str::contains($normalized, ['dicionario', 'resumo', 'auditoria']);
    }

    private function montarChaveProduto(?string $categoriaOficial, ?string $nomeBase): ?string
    {
        $categoria = $this->normalizarSegmentoChave($categoriaOficial);
        $nome = $this->normalizarSegmentoChave($nomeBase);

        if ($categoria === null || $nome === null) {
            return null;
        }

        return $categoria . '|' . $nome;
    }

    private function montarChaveVariacao(
        ?string $chaveProduto,
        ?string $cor,
        ?float $dim1,
        ?float $dim2,
        ?float $dim3,
        ?string $lado,
        ?string $material,
        ?string $acabamento
    ): ?string {
        if ($chaveProduto === null) {
            return null;
        }

        $partes = [$chaveProduto];

        if (($corNorm = $this->normalizarSegmentoChave($cor)) !== null) {
            $partes[] = 'COR:' . $corNorm;
        }
        if ($dim1 !== null) {
            $partes[] = 'D1:' . $this->formatarNumeroChave($dim1);
        }
        if ($dim2 !== null) {
            $partes[] = 'D2:' . $this->formatarNumeroChave($dim2);
        }
        if ($dim3 !== null) {
            $partes[] = 'D3:' . $this->formatarNumeroChave($dim3);
        }
        if (($ladoNorm = $this->normalizarSegmentoChave($lado)) !== null) {
            $partes[] = 'LADO:' . $ladoNorm;
        }
        if (($materialNorm = $this->normalizarSegmentoChave($material)) !== null) {
            $partes[] = 'MAT:' . $materialNorm;
        }
        if (($acabamentoNorm = $this->normalizarSegmentoChave($acabamento)) !== null) {
            $partes[] = 'ACAB:' . $acabamentoNorm;
        }

        return implode('|', $partes);
    }

    private function normalizarSegmentoChave(?string $valor): ?string
    {
        $texto = $this->toText($valor);
        if ($texto === '') {
            return null;
        }

        return (string) Str::of($texto)->squish()->upper()->ascii();
    }

    private function formatarNumeroChave(float $numero): string
    {
        return rtrim(rtrim(number_format($numero, 2, '.', ''), '0'), '.');
    }

    private function normalizarStatus(?string $status): ?string
    {
        $texto = $this->normalizarTextoComparacao((string) $status);
        if ($texto === '') {
            return null;
        }

        if (Str::contains($texto, 'deposito')) {
            return 'Depósito';
        }

        if (Str::contains($texto, 'loja')) {
            return 'Loja';
        }

        return $this->toText($status) ?: null;
    }

    private function normalizarTextoComparacao(string $texto): string
    {
        return (string) Str::of($texto)->squish()->lower()->ascii();
    }

    private function toText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function toNullableText(mixed $value): ?string
    {
        $text = $this->toText($value);
        return $text !== '' ? $text : null;
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (str_contains($text, '.') && str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
        }
        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        $text = trim((string) $value);
        return is_numeric($text) ? (int) round((float) $text) : null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = $this->normalizarTextoComparacao((string) $value);
        return in_array($text, ['1', 'sim', 's', 'true', 'x'], true);
    }

    private function toDateFlexible(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
