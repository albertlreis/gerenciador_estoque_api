<?php

namespace App\Services\Import;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\ImportacaoNormalizadaAcaoLinha;
use App\Enums\ImportacaoNormalizadaLinhaStatus;
use App\Enums\ImportacaoNormalizadaStatus;
use App\Enums\StatusRevisaoCadastro;
use App\Models\AreaEstoque;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Fornecedor;
use App\Models\ImportacaoNormalizada;
use App\Models\ImportacaoNormalizadaConflito;
use App\Models\ImportacaoNormalizadaLinha;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\EstoqueMovimentacaoService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ImportacaoNormalizadaPipelineService
{
    private const REF_TYPE_LINHA_IMPORTACAO = 'importacao_normalizada_linha';

    public function __construct(
        private readonly ProdutoUpsertService $produtoUpsertService,
        private readonly EstoqueMovimentacaoService $estoqueMovimentacaoService,
        private readonly LocalizacaoParser $localizacaoParser,
    ) {}

    public function gerarPreview(ImportacaoNormalizada $importacao, bool $persist = true): array
    {
        /** @var EloquentCollection<int, ImportacaoNormalizadaLinha> $linhas */
        $linhas = $importacao->linhas()
            ->with(['conflitos', 'produtoVinculado', 'variacaoVinculada.produto'])
            ->orderBy('aba_origem')
            ->orderBy('linha_planilha')
            ->get();

        $contextoCatalogo = $this->carregarContextoCatalogo($linhas, $this->isCargaInicial($importacao));
        $classificacoes = [];

        foreach ($linhas as $linha) {
            $classificacao = $this->classificarLinha($linha, $contextoCatalogo);
            $classificacoes[$linha->id] = $classificacao;

            if ($persist) {
                $this->persistirClassificacaoLinha($linha, $classificacao);
            }
        }

        $preview = $this->montarResumoPreview($importacao, $linhas, $classificacoes);

        if ($persist) {
            $statusImportacao = $this->resolverStatusImportacaoPeloPreview($importacao, $preview);

            $importacao->forceFill([
                'status' => $statusImportacao,
                'preview_resumo' => $preview,
            ])->save();
        }

        Log::info('Importação normalizada: preview gerado.', [
            'importacao_id' => $importacao->id,
            'persistido' => $persist,
            'status' => $importacao->fresh()?->status?->value ?? $importacao->fresh()?->status,
            'totais' => $preview['totais'] ?? [],
        ]);

        return $preview;
    }

    public function validarParaConfirmacao(ImportacaoNormalizada $importacao, ?array $preview = null): array
    {
        $preview ??= $importacao->preview_resumo ?? $this->gerarPreview($importacao, true);

        $motivos = [];
        $totais = (array) ($preview['totais'] ?? []);

        if (($totais['linhas_bloqueadas'] ?? 0) > 0) {
            $motivos[] = 'Ainda existem linhas bloqueadas por conflito ou inconsistência operacional.';
        }

        if (($totais['linhas_pendentes_revisao'] ?? 0) > 0) {
            $motivos[] = 'Ainda existem linhas pendentes de revisão manual.';
        }

        if (($totais['linhas_validas_para_efetivacao'] ?? 0) <= 0) {
            $motivos[] = 'Nenhuma linha apta para efetivação foi encontrada.';
        }

        if (($totais['linhas_com_erro_estrutural'] ?? 0) > 0) {
            $motivos[] = 'Existem linhas com erro estrutural ainda não ignoradas manualmente.';
        }

        if ($importacao->status === ImportacaoNormalizadaStatus::EM_PROCESSAMENTO) {
            $motivos[] = 'A importação já está em processamento.';
        }

        return [
            'apta' => empty($motivos),
            'motivos' => $motivos,
            'totais' => [
                'linhas_bloqueadas' => (int) ($totais['linhas_bloqueadas'] ?? 0),
                'linhas_pendentes_revisao' => (int) ($totais['linhas_pendentes_revisao'] ?? 0),
                'linhas_validas_para_efetivacao' => (int) ($totais['linhas_validas_para_efetivacao'] ?? 0),
                'linhas_com_erro_estrutural' => (int) ($totais['linhas_com_erro_estrutural'] ?? 0),
            ],
        ];
    }

    public function confirmar(
        ImportacaoNormalizada $importacao,
        ?int $usuarioId = null,
        bool $modoCargaInicial = false
    ): array
    {
        return DB::transaction(function () use ($importacao, $usuarioId, $modoCargaInicial) {
            $this->assertFluxoImportacaoValido($importacao, $modoCargaInicial);
            /** @var ImportacaoNormalizada $locked */
            $locked = ImportacaoNormalizada::query()->lockForUpdate()->findOrFail($importacao->id);

            if ($locked->status === ImportacaoNormalizadaStatus::EFETIVADA) {
                return [
                    'sucesso' => true,
                    'idempotente' => true,
                    'mensagem' => 'A importação já foi efetivada anteriormente.',
                    'preview' => $locked->preview_resumo,
                    'validacao' => $this->validarParaConfirmacao($locked, $locked->preview_resumo),
                    'importacao' => $locked->fresh(),
                ];
            }

            $preview = $this->gerarPreview($locked, true);
            $validacao = $this->validarParaConfirmacao($locked, $preview);

            if (!$validacao['apta']) {
                Log::warning('Importação normalizada: confirmação bloqueada.', [
                    'importacao_id' => $locked->id,
                    'motivos' => $validacao['motivos'],
                ]);

                return [
                    'sucesso' => false,
                    'mensagem' => 'A importação ainda não está apta para confirmação.',
                    'preview' => $preview,
                    'validacao' => $validacao,
                    'importacao' => $locked->fresh(),
                ];
            }

            $locked->forceFill([
                'status' => ImportacaoNormalizadaStatus::CONFIRMADA,
                'confirmado_em' => $locked->confirmado_em ?? now(),
                'confirmado_por' => $locked->confirmado_por ?? $usuarioId,
                'preview_resumo' => $preview,
            ])->save();

            Log::info('Importação normalizada: confirmada.', [
                'importacao_id' => $locked->id,
                'usuario_id' => $usuarioId,
                'totais' => $preview['totais'] ?? [],
            ]);

            return [
                'sucesso' => true,
                'mensagem' => 'Importação confirmada e pronta para efetivação.',
                'preview' => $preview,
                'validacao' => $validacao,
                'importacao' => $locked->fresh(),
            ];
        });
    }

    public function efetivar(
        ImportacaoNormalizada $importacao,
        ?int $usuarioId = null,
        bool $modoCargaInicial = false
    ): array
    {
        try {
            return DB::transaction(function () use ($importacao, $usuarioId, $modoCargaInicial) {
                $this->assertFluxoImportacaoValido($importacao, $modoCargaInicial);
                /** @var ImportacaoNormalizada $locked */
                $locked = ImportacaoNormalizada::query()->lockForUpdate()->findOrFail($importacao->id);

                if ($locked->status === ImportacaoNormalizadaStatus::EFETIVADA) {
                    return [
                        'sucesso' => true,
                        'idempotente' => true,
                        'mensagem' => 'A importação já foi efetivada anteriormente.',
                        'relatorio' => $locked->relatorio_final,
                        'importacao' => $locked->fresh(),
                    ];
                }

                $preview = $this->gerarPreview($locked, true);
                $validacao = $this->validarParaConfirmacao($locked, $preview);

                if (!$validacao['apta']) {
                    Log::warning('Importação normalizada: efetivação bloqueada.', [
                        'importacao_id' => $locked->id,
                        'motivos' => $validacao['motivos'],
                    ]);

                    return [
                        'sucesso' => false,
                        'mensagem' => 'A importação ainda não está apta para efetivação.',
                        'preview' => $preview,
                        'validacao' => $validacao,
                        'importacao' => $locked->fresh(),
                    ];
                }

                if ($locked->confirmado_em === null) {
                    return [
                        'sucesso' => false,
                        'mensagem' => 'A importação precisa ser confirmada antes da efetivação.',
                        'preview' => $preview,
                        'validacao' => $validacao,
                        'importacao' => $locked->fresh(),
                    ];
                }

                $executionKey = (string) Str::uuid();
                $agora = now();

                $locked->forceFill([
                    'status' => ImportacaoNormalizadaStatus::EM_PROCESSAMENTO,
                    'chave_execucao' => $executionKey,
                    'observacoes' => null,
                ])->save();

                Log::info('Importação normalizada: efetivação iniciada.', [
                    'importacao_id' => $locked->id,
                    'usuario_id' => $usuarioId,
                    'chave_execucao' => $executionKey,
                ]);

                /** @var EloquentCollection<int, ImportacaoNormalizadaLinha> $linhas */
                $linhas = $locked->linhas()
                    ->with(['conflitos', 'produtoVinculado', 'variacaoVinculada.produto'])
                    ->orderBy('id')
                    ->get();

                $relatorio = $this->processarLinhasEfetivacao($locked, $linhas, $usuarioId, $executionKey, $agora);

                $locked->forceFill([
                    'status' => ImportacaoNormalizadaStatus::EFETIVADA,
                    'efetivado_em' => $agora,
                    'efetivado_por' => $usuarioId,
                    'preview_resumo' => $preview,
                    'relatorio_final' => $relatorio,
                    'observacoes' => null,
                ])->save();

                Log::info('Importação normalizada: efetivação concluída.', [
                    'importacao_id' => $locked->id,
                    'usuario_id' => $usuarioId,
                    'chave_execucao' => $executionKey,
                    'relatorio' => $relatorio,
                ]);

                return [
                    'sucesso' => true,
                    'mensagem' => 'Importação efetivada com sucesso.',
                    'preview' => $preview,
                    'relatorio' => $relatorio,
                    'importacao' => $locked->fresh(),
                ];
            });
        } catch (Throwable $e) {
            $importacao->forceFill([
                'status' => ImportacaoNormalizadaStatus::ERRO,
                'observacoes' => $e->getMessage(),
            ])->save();

            Log::error('Importação normalizada: falha na efetivação.', [
                'importacao_id' => $importacao->id,
                'usuario_id' => $usuarioId,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function processarLinhasEfetivacao(
        ImportacaoNormalizada $importacao,
        EloquentCollection $linhas,
        ?int $usuarioId,
        string $executionKey,
        Carbon $agora
    ): array {
        $produtosCriados = [];
        $produtosAtualizados = [];
        $variacoesCriadas = [];
        $variacoesAtualizadas = [];

        $linhasProcessadas = 0;
        $linhasEfetivadas = 0;
        $linhasIgnoradas = 0;
        $linhasBloqueadas = 0;
        $movimentacoesCriadas = 0;
        $linhasGeraramEstoque = 0;
        $linhasSemEstoquePorStatus = 0;
        $linhasSemMovimentacaoPorQuantidadeZero = 0;
        $codigosHistoricosPersistidos = 0;
        $errosOcorridos = 0;

        foreach ($linhas as $linha) {
            $linhasProcessadas++;

            if ($linha->status_processamento === ImportacaoNormalizadaLinhaStatus::IGNORADA) {
                $linhasIgnoradas++;
                $this->registrarResultadoIgnorado($linha, $agora);
                continue;
            }

            if (in_array($linha->status_processamento, [
                ImportacaoNormalizadaLinhaStatus::BLOQUEADA,
                ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO,
                ImportacaoNormalizadaLinhaStatus::ERRO,
            ], true)) {
                $linhasBloqueadas++;
                throw new RuntimeException(
                    sprintf('A linha %d da aba "%s" ainda está bloqueada para efetivação.', $linha->linha_planilha, $linha->aba_origem)
                );
            }

            try {
                $categoriaId = $this->resolverCategoriaOficialId((string) $linha->categoria_oficial);
                $fornecedorId = $this->resolverFornecedorId($linha->fornecedor);

                $resultadoUpsert = $this->produtoUpsertService->upsertProdutoVariacao(
                    $this->montarPayloadCatalogo($linha, $categoriaId, $fornecedorId, $this->isCargaInicial($importacao))
                );

                /** @var Produto $produto */
                $produto = $resultadoUpsert['produto'];
                /** @var ProdutoVariacao $variacao */
                $variacao = $resultadoUpsert['variacao'];

                if (!empty($resultadoUpsert['produto_criado'])) {
                    $produtosCriados[$produto->id] = true;
                } else {
                    $produtosAtualizados[$produto->id] = true;
                }

                if (!empty($resultadoUpsert['variacao_criada'])) {
                    $variacoesCriadas[$variacao->id] = true;
                } else {
                    $variacoesAtualizadas[$variacao->id] = true;
                }

                $codigosHistoricosPersistidos += (int) ($resultadoUpsert['codigos_historicos_criados'] ?? 0);

                $movimentacaoId = null;
                $depositoNome = null;
                $depositoId = null;
                $gerouMovimentacao = false;

                if ($linha->gera_estoque && (int) ($linha->quantidade ?? 0) > 0) {
                    $depositoNome = $this->resolverNomeDepositoDaLinha($linha);
                    if ($depositoNome === null) {
                        throw new RuntimeException(
                            sprintf('Não foi possível resolver o depósito da linha %d da aba "%s".', $linha->linha_planilha, $linha->aba_origem)
                        );
                    }

                    $deposito = Deposito::firstOrCreate(['nome' => $depositoNome]);
                    $depositoId = (int) $deposito->id;

                    $movimentacao = EstoqueMovimentacao::query()
                        ->where('ref_type', self::REF_TYPE_LINHA_IMPORTACAO)
                        ->where('ref_id', $linha->id)
                        ->first();

                    if (!$movimentacao) {
                        $movimentacao = $this->estoqueMovimentacaoService->registrarMovimentacaoManual([
                            'id_variacao' => $variacao->id,
                            'id_deposito_origem' => null,
                            'id_deposito_destino' => $depositoId,
                            'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                            'quantidade' => (int) $linha->quantidade,
                            'observacao' => $this->montarObservacaoMovimentacao($importacao, $linha),
                            'data_movimentacao' => $linha->data_entrada ?: $agora,
                            'lote_id' => $executionKey,
                            'ref_type' => self::REF_TYPE_LINHA_IMPORTACAO,
                            'ref_id' => $linha->id,
                        ], $usuarioId);
                        $movimentacoesCriadas++;
                    }

                    $movimentacaoId = (int) $movimentacao->id;
                    $gerouMovimentacao = true;
                    $linhasGeraramEstoque++;

                    $this->aplicarLocalizacaoAoEstoque(
                        $variacao->id,
                        $depositoId,
                        $linha->localizacao
                    );
                } else {
                    if ($linha->gera_estoque) {
                        $linhasSemMovimentacaoPorQuantidadeZero++;
                    } else {
                        $linhasSemEstoquePorStatus++;
                    }
                }

                $linha->forceFill([
                    'produto_id_vinculado' => $produto->id,
                    'variacao_id_vinculada' => $variacao->id,
                    'movimentacao_id' => $movimentacaoId,
                    'status_processamento' => ImportacaoNormalizadaLinhaStatus::EFETIVADA,
                    'erro_execucao' => null,
                    'efetivada_em' => $agora,
                    'resultado_execucao' => [
                        'produto_id' => $produto->id,
                        'variacao_id' => $variacao->id,
                        'produto_criado' => (bool) ($resultadoUpsert['produto_criado'] ?? false),
                        'variacao_criada' => (bool) ($resultadoUpsert['variacao_criada'] ?? false),
                        'codigos_historicos_criados' => (int) ($resultadoUpsert['codigos_historicos_criados'] ?? 0),
                        'deposito_nome' => $depositoNome,
                        'deposito_id' => $depositoId,
                        'movimentacao_id' => $movimentacaoId,
                        'gerou_movimentacao' => $gerouMovimentacao,
                    ],
                ])->save();

                $linhasEfetivadas++;
            } catch (Throwable $e) {
                $errosOcorridos++;
                $linha->forceFill([
                    'status_processamento' => ImportacaoNormalizadaLinhaStatus::FALHA_EFETIVACAO,
                    'erro_execucao' => $e->getMessage(),
                    'resultado_execucao' => [
                        'erro' => $e->getMessage(),
                    ],
                ])->save();

                throw $e;
            }
        }

        $decisoesManuaisAplicadas = $importacao->linhas()->whereNotNull('decisao_manual')->count()
            + $importacao->conflitos()->whereNotNull('decisao_manual')->count();

        return [
            'total_linhas_processadas' => $linhasProcessadas,
            'total_linhas_efetivadas' => $linhasEfetivadas,
            'total_linhas_ignoradas' => $linhasIgnoradas,
            'total_linhas_bloqueadas' => $linhasBloqueadas,
            'total_decisoes_manuais_aplicadas' => $decisoesManuaisAplicadas,
            'total_produtos_criados' => count($produtosCriados),
            'total_produtos_atualizados' => count(array_diff_key($produtosAtualizados, $produtosCriados)),
            'total_variacoes_criadas' => count($variacoesCriadas),
            'total_variacoes_atualizadas' => count(array_diff_key($variacoesAtualizadas, $variacoesCriadas)),
            'total_codigos_historicos_persistidos' => $codigosHistoricosPersistidos,
            'total_linhas_que_geraram_estoque' => $linhasGeraramEstoque,
            'total_linhas_sem_estoque_por_status' => $linhasSemEstoquePorStatus,
            'total_linhas_sem_movimentacao_por_quantidade_zero' => $linhasSemMovimentacaoPorQuantidadeZero,
            'total_movimentacoes_criadas' => $movimentacoesCriadas,
            'total_erros_ocorridos' => $errosOcorridos,
            'executado_em' => $agora->toIso8601String(),
        ];
    }

    private function registrarResultadoIgnorado(ImportacaoNormalizadaLinha $linha, Carbon $agora): void
    {
        $linha->forceFill([
            'efetivada_em' => $agora,
            'resultado_execucao' => [
                'ignorada' => true,
                'motivo' => $linha->motivo_bloqueio ?: $linha->motivo_decisao_manual ?: 'Linha ignorada por decisão manual.',
            ],
        ])->save();
    }

    /**
     * @return array{
     *   produtos_por_id:array<int,Produto>,
     *   produtos_por_codigo:array<string,Produto>,
     *   variacoes_por_id:array<int,ProdutoVariacao>,
     *   variacoes_por_sku:array<string,ProdutoVariacao>,
     *   variacoes_por_chave:array<string,ProdutoVariacao>
     * }
     */
    private function carregarContextoCatalogo(EloquentCollection $linhas, bool $modoCargaInicial = false): array
    {
        $produtoIds = $linhas->pluck('produto_id_vinculado')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $variacaoIds = $linhas->pluck('variacao_id_vinculada')->filter()->map(fn ($id) => (int) $id)->unique()->values();        
        $codigosProduto = $modoCargaInicial
            ? collect()
            : $linhas->pluck('codigo_produto')->filter()->map(fn ($value) => trim((string) $value))->unique()->values();
        $skus = $linhas->pluck('sku_interno')->filter()->map(fn ($value) => trim((string) $value))->unique()->values();
        $chavesVariacao = $linhas->pluck('chave_variacao')->filter()->map(fn ($value) => trim((string) $value))->unique()->values();

        $produtos = collect();
        if ($produtoIds->isNotEmpty() || $codigosProduto->isNotEmpty()) {
            $produtos = Produto::query()
                ->where(function ($query) use ($produtoIds, $codigosProduto) {
                    if ($produtoIds->isNotEmpty()) {
                        $query->whereIn('id', $produtoIds->all());
                    }
                    if ($codigosProduto->isNotEmpty()) {
                        $query->orWhereIn('codigo_produto', $codigosProduto->all());
                    }
                })
                ->get();
        }

        $variacoes = collect();
        if ($variacaoIds->isNotEmpty() || $skus->isNotEmpty() || $chavesVariacao->isNotEmpty()) {
            $variacoes = ProdutoVariacao::query()
                ->with('produto')
                ->where(function ($query) use ($variacaoIds, $skus, $chavesVariacao) {
                    if ($variacaoIds->isNotEmpty()) {
                        $query->whereIn('id', $variacaoIds->all());
                    }
                    if ($skus->isNotEmpty()) {
                        $query->orWhereIn('sku_interno', $skus->all());
                    }
                    if ($chavesVariacao->isNotEmpty()) {
                        $query->orWhereIn('chave_variacao', $chavesVariacao->all());
                    }
                })
                ->get();
        }

        return [
            'modo_carga_inicial' => $modoCargaInicial,
            'produtos_por_id' => $produtos->keyBy('id')->all(),
            'produtos_por_codigo' => $produtos->filter(fn (Produto $produto) => !empty($produto->codigo_produto))
                ->keyBy(fn (Produto $produto) => trim((string) $produto->codigo_produto))
                ->all(),
            'variacoes_por_id' => $variacoes->keyBy('id')->all(),
            'variacoes_por_sku' => $variacoes->filter(fn (ProdutoVariacao $variacao) => !empty($variacao->sku_interno))
                ->keyBy(fn (ProdutoVariacao $variacao) => trim((string) $variacao->sku_interno))
                ->all(),
            'variacoes_por_chave' => $variacoes->filter(fn (ProdutoVariacao $variacao) => !empty($variacao->chave_variacao))
                ->keyBy(fn (ProdutoVariacao $variacao) => trim((string) $variacao->chave_variacao))
                ->all(),
        ];
    }

    /**
     * @param array<string,mixed> $contextoCatalogo
     * @return array<string,mixed>
     */
    private function classificarLinha(ImportacaoNormalizadaLinha $linha, array $contextoCatalogo): array
    {
        $modoCargaInicial = (bool) ($contextoCatalogo['modo_carga_inicial'] ?? false);
        $motivosBloqueio = [];

        if ($linha->status_revisao === StatusRevisaoCadastro::REJEITADO) {
            return $this->resultadoClassificacao(
                $linha,
                ImportacaoNormalizadaAcaoLinha::IGNORADA_MANUALMENTE,
                ImportacaoNormalizadaLinhaStatus::IGNORADA,
                bloqueada: false,
                motivoBloqueio: $linha->motivo_decisao_manual ?: 'Linha ignorada por decisão manual.',
                produtoAcao: 'IGNORAR',
                variacaoAcao: 'IGNORAR',
                estoqueAcao: 'IGNORAR',
                geraMovimentacao: false,
                produtoId: $linha->produto_id_vinculado,
                variacaoId: $linha->variacao_id_vinculada,
            );
        }

        if ($this->linhaTemErroEstruturalBloqueante($linha)) {
            return $this->resultadoClassificacao(
                $linha,
                ImportacaoNormalizadaAcaoLinha::IGNORADA_POR_ERRO_ESTRUTURAL,
                ImportacaoNormalizadaLinhaStatus::ERRO,
                bloqueada: true,
                motivoBloqueio: implode(' | ', $linha->erros ?? []),
                produtoAcao: 'BLOQUEADA',
                variacaoAcao: 'BLOQUEADA',
                estoqueAcao: 'BLOQUEADA',
                geraMovimentacao: false,
                produtoId: $linha->produto_id_vinculado,
                variacaoId: $linha->variacao_id_vinculada,
            );
        }

        $produtoExistente = $this->resolverProdutoExistente($linha, $contextoCatalogo, $motivosBloqueio);
        $variacaoExistente = $this->resolverVariacaoExistente($linha, $contextoCatalogo, $motivosBloqueio);

        if ($variacaoExistente && $produtoExistente && (int) $variacaoExistente->produto_id !== (int) $produtoExistente->id) {
            $motivosBloqueio[] = 'A variação vinculada pertence a um produto diferente do produto pai associado.';
        }

        if ($variacaoExistente && !$produtoExistente) {
            $produtoExistente = $variacaoExistente->produto;
        }

        $depositoPrevisto = $linha->gera_estoque ? $this->resolverNomeDepositoDaLinha($linha) : null;
        if ($linha->gera_estoque && $depositoPrevisto === null) {
            $motivosBloqueio[] = 'Não foi possível determinar o depósito da linha para geração de estoque.';
        }

        $conflitosPendentes = $linha->conflitos
            ->filter(fn (ImportacaoNormalizadaConflito $conflito) => !in_array(
                $conflito->status_revisao?->value ?? $conflito->status_revisao,
                [StatusRevisaoCadastro::APROVADO->value, StatusRevisaoCadastro::REJEITADO->value],
                true
            ));

        if (!$modoCargaInicial && ($linha->status_revisao === StatusRevisaoCadastro::PENDENTE_REVISAO || $conflitosPendentes->isNotEmpty())) {
            $motivo = $conflitosPendentes->isNotEmpty()
                ? 'Existem conflitos pendentes de decisão manual.'
                : 'A linha ainda depende de revisão manual.';

            return $this->resultadoClassificacao(
                $linha,
                $conflitosPendentes->isNotEmpty()
                    ? ImportacaoNormalizadaAcaoLinha::BLOQUEADA_POR_CONFLITO
                    : ImportacaoNormalizadaAcaoLinha::PENDENTE_REVISAO_MANUAL,
                $conflitosPendentes->isNotEmpty()
                    ? ImportacaoNormalizadaLinhaStatus::BLOQUEADA
                    : ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO,
                bloqueada: true,
                motivoBloqueio: $motivo,
                produtoAcao: $produtoExistente ? 'ATUALIZAR' : 'CRIAR',
                variacaoAcao: $variacaoExistente ? 'ATUALIZAR' : 'CRIAR',
                estoqueAcao: $linha->gera_estoque ? 'PENDENTE' : 'SEM_ESTOQUE',
                geraMovimentacao: false,
                produtoId: $produtoExistente?->id,
                variacaoId: $variacaoExistente?->id,
                depositoPrevisto: $depositoPrevisto,
            );
        }

        if (!$modoCargaInicial && !empty($motivosBloqueio)) {
            return $this->resultadoClassificacao(
                $linha,
                ImportacaoNormalizadaAcaoLinha::BLOQUEADA_POR_CONFLITO,
                ImportacaoNormalizadaLinhaStatus::BLOQUEADA,
                bloqueada: true,
                motivoBloqueio: implode(' | ', array_unique($motivosBloqueio)),
                produtoAcao: $produtoExistente ? 'ATUALIZAR' : 'CRIAR',
                variacaoAcao: $variacaoExistente ? 'ATUALIZAR' : 'CRIAR',
                estoqueAcao: $linha->gera_estoque ? 'BLOQUEADA' : 'SEM_ESTOQUE',
                geraMovimentacao: false,
                produtoId: $produtoExistente?->id,
                variacaoId: $variacaoExistente?->id,
                depositoPrevisto: $depositoPrevisto,
            );
        }

        $produtoAcao = $produtoExistente ? 'ATUALIZAR' : 'CRIAR';
        $variacaoAcao = $variacaoExistente ? 'ATUALIZAR' : 'CRIAR';
        $geraMovimentacao = $linha->gera_estoque && (int) ($linha->quantidade ?? 0) > 0;
        $estoqueAcao = !$linha->gera_estoque
            ? 'SEM_ESTOQUE'
            : ($geraMovimentacao ? 'GERAR_MOVIMENTACAO_ENTRADA' : 'SEM_MOVIMENTACAO_QUANTIDADE_ZERO');

        $classificacao = match (true) {
            !$linha->gera_estoque => ImportacaoNormalizadaAcaoLinha::CADASTRO_APENAS_SEM_ESTOQUE,
            !$produtoExistente && !$variacaoExistente => ImportacaoNormalizadaAcaoLinha::CRIAR_PRODUTO_E_VARIACAO,
            $produtoExistente && !$variacaoExistente => ImportacaoNormalizadaAcaoLinha::CRIAR_VARIACAO_EM_PRODUTO_EXISTENTE,
            default => ImportacaoNormalizadaAcaoLinha::ATUALIZAR_VARIACAO_EXISTENTE,
        };

        return $this->resultadoClassificacao(
            $linha,
            $classificacao,
            $linha->efetivada_em ? ImportacaoNormalizadaLinhaStatus::EFETIVADA : ImportacaoNormalizadaLinhaStatus::AGUARDANDO_EFETIVACAO,
            bloqueada: false,
            motivoBloqueio: null,
            produtoAcao: $produtoAcao,
            variacaoAcao: $variacaoAcao,
            estoqueAcao: $estoqueAcao,
            geraMovimentacao: $geraMovimentacao,
            produtoId: $produtoExistente?->id,
            variacaoId: $variacaoExistente?->id,
            depositoPrevisto: $depositoPrevisto,
        );
    }

    private function resultadoClassificacao(
        ImportacaoNormalizadaLinha $linha,
        ImportacaoNormalizadaAcaoLinha $acao,
        ImportacaoNormalizadaLinhaStatus $statusProcessamento,
        bool $bloqueada,
        ?string $motivoBloqueio,
        string $produtoAcao,
        string $variacaoAcao,
        string $estoqueAcao,
        bool $geraMovimentacao,
        ?int $produtoId,
        ?int $variacaoId,
        ?string $depositoPrevisto = null,
    ): array {
        return [
            'classificacao_acao' => $acao,
            'status_processamento' => $statusProcessamento,
            'produto_acao' => $produtoAcao,
            'variacao_acao' => $variacaoAcao,
            'estoque_acao' => $estoqueAcao,
            'gera_movimentacao' => $geraMovimentacao,
            'bloqueada' => $bloqueada,
            'motivo_bloqueio' => $motivoBloqueio,
            'produto_id_vinculado' => $produtoId,
            'variacao_id_vinculada' => $variacaoId,
            'resultado_preview' => [
                'linha_id' => $linha->id,
                'classificacao_acao' => $acao->value,
                'status_processamento' => $statusProcessamento->value,
                'produto_acao' => $produtoAcao,
                'variacao_acao' => $variacaoAcao,
                'estoque_acao' => $estoqueAcao,
                'gera_estoque' => (bool) $linha->gera_estoque,
                'gera_movimentacao' => $geraMovimentacao,
                'bloqueada' => $bloqueada,
                'motivo_bloqueio' => $motivoBloqueio,
                'produto_id_vinculado' => $produtoId,
                'variacao_id_vinculada' => $variacaoId,
                'deposito_previsto' => $depositoPrevisto,
                'status' => $linha->status,
                'status_normalizado' => $linha->status_normalizado,
            ],
        ];
    }

    private function persistirClassificacaoLinha(ImportacaoNormalizadaLinha $linha, array $classificacao): void
    {
        $linha->forceFill([
            'classificacao_acao' => $classificacao['classificacao_acao'],
            'status_processamento' => $classificacao['status_processamento'],
            'produto_acao' => $classificacao['produto_acao'],
            'variacao_acao' => $classificacao['variacao_acao'],
            'estoque_acao' => $classificacao['estoque_acao'],
            'gera_movimentacao' => $classificacao['gera_movimentacao'],
            'motivo_bloqueio' => $classificacao['motivo_bloqueio'],
            'resultado_preview' => $classificacao['resultado_preview'],
            'produto_id_vinculado' => $classificacao['produto_id_vinculado'] ?? $linha->produto_id_vinculado,
            'variacao_id_vinculada' => $classificacao['variacao_id_vinculada'] ?? $linha->variacao_id_vinculada,
        ])->save();
    }

    /**
     * @param EloquentCollection<int, ImportacaoNormalizadaLinha> $linhas
     * @param array<int,array<string,mixed>> $classificacoes
     * @return array<string,mixed>
     */
    private function montarResumoPreview(ImportacaoNormalizada $importacao, EloquentCollection $linhas, array $classificacoes): array
    {
        $porAba = [];
        $porStatus = [];
        $porAcao = [];

        $produtosNovos = [];
        $produtosAtualizados = [];
        $variacoesNovas = [];
        $variacoesAtualizadas = [];

        $totais = [
            'linhas_total' => $linhas->count(),
            'linhas_que_gerariam_estoque' => 0,
            'linhas_que_nao_gerariam_estoque' => 0,
            'linhas_com_conflito' => 0,
            'linhas_pendentes_revisao' => 0,
            'linhas_validas_para_efetivacao' => 0,
            'linhas_bloqueadas' => 0,
            'linhas_com_erro_estrutural' => 0,
            'linhas_ignoradas' => 0,
        ];

        foreach ($linhas as $linha) {
            $classificacao = $classificacoes[$linha->id];
            $acao = $classificacao['classificacao_acao']->value;

            $porAba[$linha->aba_origem] = ($porAba[$linha->aba_origem] ?? 0) + 1;
            $statusKey = $linha->status_normalizado ?: ($linha->status ?: 'Sem status');
            $porStatus[$statusKey] = ($porStatus[$statusKey] ?? 0) + 1;
            $porAcao[$acao] = ($porAcao[$acao] ?? 0) + 1;

            if ($linha->gera_estoque) {
                $totais['linhas_que_gerariam_estoque']++;
            } else {
                $totais['linhas_que_nao_gerariam_estoque']++;
            }

            if ($linha->conflitos->isNotEmpty()) {
                $totais['linhas_com_conflito']++;
            }

            if ($classificacao['status_processamento'] === ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO) {
                $totais['linhas_pendentes_revisao']++;
            }

            if ($classificacao['status_processamento'] === ImportacaoNormalizadaLinhaStatus::ERRO) {
                $totais['linhas_com_erro_estrutural']++;
            }

            if (in_array($classificacao['status_processamento'], [
                ImportacaoNormalizadaLinhaStatus::BLOQUEADA,
                ImportacaoNormalizadaLinhaStatus::PENDENTE_REVISAO,
                ImportacaoNormalizadaLinhaStatus::ERRO,
            ], true)) {
                $totais['linhas_bloqueadas']++;
            }

            if ($classificacao['status_processamento'] === ImportacaoNormalizadaLinhaStatus::IGNORADA) {
                $totais['linhas_ignoradas']++;
                continue;
            }

            if ($classificacao['status_processamento'] === ImportacaoNormalizadaLinhaStatus::AGUARDANDO_EFETIVACAO
                || $classificacao['status_processamento'] === ImportacaoNormalizadaLinhaStatus::EFETIVADA
            ) {
                $totais['linhas_validas_para_efetivacao']++;
            }

            if ($classificacao['produto_acao'] === 'CRIAR') {
                $produtoIdentidade = $linha->codigo_produto ?: ($linha->chave_produto ?: 'linha:' . $linha->id);
                $produtosNovos[$produtoIdentidade] = true;
            } elseif ($classificacao['produto_acao'] === 'ATUALIZAR') {
                $produtoIdentidade = (string) ($classificacao['produto_id_vinculado'] ?: $linha->codigo_produto ?: $linha->chave_produto);
                $produtosAtualizados[$produtoIdentidade] = true;
            }

            if ($classificacao['variacao_acao'] === 'CRIAR') {
                $variacaoIdentidade = $linha->sku_interno ?: ($linha->chave_variacao ?: 'linha:' . $linha->id);
                $variacoesNovas[$variacaoIdentidade] = true;
            } elseif ($classificacao['variacao_acao'] === 'ATUALIZAR') {
                $variacaoIdentidade = (string) ($classificacao['variacao_id_vinculado'] ?: $linha->sku_interno ?: $linha->chave_variacao);
                $variacoesAtualizadas[$variacaoIdentidade] = true;
            }
        }

        $totais['produtos_pais_novos'] = count($produtosNovos);
        $totais['produtos_pais_atualizados'] = count(array_diff_key($produtosAtualizados, $produtosNovos));
        $totais['variacoes_novas'] = count($variacoesNovas);
        $totais['variacoes_atualizadas'] = count(array_diff_key($variacoesAtualizadas, $variacoesNovas));

        return [
            'importacao_id' => $importacao->id,
            'gerado_em' => now()->toIso8601String(),
            'totais' => $totais,
            'totais_por_aba' => $porAba,
            'totais_por_status' => $porStatus,
            'totais_por_acao' => $porAcao,
        ];
    }

    private function resolverStatusImportacaoPeloPreview(ImportacaoNormalizada $importacao, array $preview): ImportacaoNormalizadaStatus
    {
        if ($importacao->status === ImportacaoNormalizadaStatus::EFETIVADA) {
            return ImportacaoNormalizadaStatus::EFETIVADA;
        }

        if ($importacao->status === ImportacaoNormalizadaStatus::EM_PROCESSAMENTO) {
            return ImportacaoNormalizadaStatus::EM_PROCESSAMENTO;
        }

        $totais = $preview['totais'] ?? [];

        if (($totais['linhas_bloqueadas'] ?? 0) > 0 || ($totais['linhas_pendentes_revisao'] ?? 0) > 0) {
            return ImportacaoNormalizadaStatus::EM_REVISAO;
        }

        if ($importacao->confirmado_em !== null) {
            return ImportacaoNormalizadaStatus::CONFIRMADA;
        }

        if (($totais['linhas_validas_para_efetivacao'] ?? 0) > 0) {
            return ImportacaoNormalizadaStatus::PRONTA_PARA_EFETIVAR;
        }

        return ImportacaoNormalizadaStatus::STAGED;
    }

    private function linhaTemErroEstruturalBloqueante(ImportacaoNormalizadaLinha $linha): bool
    {
        return !empty($linha->erros);
    }

    /**
     * @param array<string,mixed> $contextoCatalogo
     * @param array<int,string> $motivosBloqueio
     */
    private function resolverProdutoExistente(
        ImportacaoNormalizadaLinha $linha,
        array $contextoCatalogo,
        array &$motivosBloqueio
    ): ?Produto {
        $modoCargaInicial = (bool) ($contextoCatalogo['modo_carga_inicial'] ?? false);
        $manual = null;
        if (!empty($linha->produto_id_vinculado)) {
            $manual = $contextoCatalogo['produtos_por_id'][(int) $linha->produto_id_vinculado] ?? Produto::find($linha->produto_id_vinculado);
            if (!$manual) {
                $motivosBloqueio[] = 'Produto pai vinculado manualmente não foi encontrado.';
                return null;
            }
        }

        $automatico = null;
        if (!$modoCargaInicial && !empty($linha->codigo_produto)) {
            $automatico = $contextoCatalogo['produtos_por_codigo'][trim((string) $linha->codigo_produto)] ?? null;
        }

        if ($manual && $automatico && (int) $manual->id !== (int) $automatico->id) {
            $motivosBloqueio[] = 'O vínculo manual do produto diverge do produto encontrado pelas chaves oficiais.';
            return $manual;
        }

        $produto = $manual ?: $automatico;
        if (!$produto) {
            return null;
        }

        if (!empty($linha->codigo_produto) && !empty($produto->codigo_produto) && trim((string) $produto->codigo_produto) !== trim((string) $linha->codigo_produto)) {
            $motivosBloqueio[] = 'O produto pai vinculado possui código de produto diferente do informado na planilha.';
        }

        return $produto;
    }

    /**
     * @param array<string,mixed> $contextoCatalogo
     * @param array<int,string> $motivosBloqueio
     */
    private function resolverVariacaoExistente(
        ImportacaoNormalizadaLinha $linha,
        array $contextoCatalogo,
        array &$motivosBloqueio
    ): ?ProdutoVariacao {
        $manual = null;
        if (!empty($linha->variacao_id_vinculada)) {
            $manual = $contextoCatalogo['variacoes_por_id'][(int) $linha->variacao_id_vinculada]
                ?? ProdutoVariacao::with('produto')->find($linha->variacao_id_vinculada);
            if (!$manual) {
                $motivosBloqueio[] = 'Variação vinculada manualmente não foi encontrada.';
                return null;
            }
        }

        $automatica = null;
        if (!empty($linha->sku_interno)) {
            $automatica = $contextoCatalogo['variacoes_por_sku'][trim((string) $linha->sku_interno)] ?? null;
        }
        if (!$automatica && !empty($linha->chave_variacao)) {
            $automatica = $contextoCatalogo['variacoes_por_chave'][trim((string) $linha->chave_variacao)] ?? null;
        }

        if ($manual && $automatica && (int) $manual->id !== (int) $automatica->id) {
            $motivosBloqueio[] = 'O vínculo manual da variação diverge da variação encontrada pelas chaves oficiais.';
            return $manual;
        }

        $variacao = $manual ?: $automatica;
        if (!$variacao) {
            return null;
        }

        if (!empty($linha->sku_interno) && !empty($variacao->sku_interno) && trim((string) $variacao->sku_interno) !== trim((string) $linha->sku_interno)) {
            $motivosBloqueio[] = 'A variação vinculada possui SKU interno diferente do informado na planilha.';
        }

        if (!empty($linha->chave_variacao) && !empty($variacao->chave_variacao) && trim((string) $variacao->chave_variacao) !== trim((string) $linha->chave_variacao)) {
            $motivosBloqueio[] = 'A variação vinculada possui chave de variação diferente da planilha.';
        }

        return $variacao;
    }

    private function resolverCategoriaOficialId(string $categoriaOficial): int
    {
        $nome = trim($categoriaOficial);
        if ($nome === '') {
            throw new RuntimeException('Categoria oficial ausente para efetivação.');
        }

        $categoria = Categoria::query()
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])
            ->first();

        if (!$categoria) {
            $categoria = Categoria::create([
                'nome' => $nome,
                'descricao' => 'Categoria criada automaticamente pela importação normalizada.',
            ]);
        }

        return (int) $categoria->id;
    }

    private function resolverFornecedorId(?string $fornecedorNome): ?int
    {
        $nome = trim((string) $fornecedorNome);
        if ($nome === '') {
            return null;
        }

        $fornecedor = Fornecedor::query()
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])
            ->first();

        if (!$fornecedor) {
            $fornecedor = Fornecedor::create([
                'nome' => $nome,
                'status' => 1,
            ]);
        }

        return (int) $fornecedor->id;
    }

    /**
     * @return array<string,mixed>
     */
    private function montarPayloadCatalogo(
        ImportacaoNormalizadaLinha $linha,
        int $categoriaId,
        ?int $fornecedorId,
        bool $modoCargaInicial
    ): array
    {
        return [
            'produto_id_forcado' => $linha->produto_id_vinculado,
            'variacao_id_forcada' => $linha->variacao_id_vinculada,
            'nome_limpo' => $linha->nome_base_normalizado ?: $linha->nome_normalizado ?: $linha->nome,
            'nome_completo' => $linha->nome,
            'categoria_id' => $categoriaId,
            'fornecedor_id' => $fornecedorId,
            'codigo_produto' => $linha->codigo_produto,
            'sku_interno' => $linha->sku_interno,
            'chave_variacao' => $linha->chave_variacao ?: $linha->chave_variacao_calculada,
            'dimensao_1' => $linha->dimensao_1,
            'dimensao_2' => $linha->dimensao_2,
            'dimensao_3' => $linha->dimensao_3,
            'cor' => $linha->cor,
            'lado' => $linha->lado,
            'material_oficial' => $linha->material_oficial,
            'acabamento_oficial' => $linha->acabamento_oficial,
            'conflito_codigo' => (bool) $linha->conflito_codigo,
            'status_revisao' => $linha->status_revisao?->value ?? $linha->status_revisao,
            'valor' => $linha->valor,
            'custo' => $linha->custo,
            'referencia' => $linha->codigo,
            'codigo' => $linha->codigo,
            'codigo_origem' => $linha->codigo_origem,
            'codigo_modelo' => $linha->codigo_modelo,
            'fonte' => $modoCargaInicial ? 'carga_inicial_sierra' : 'importacao_normalizada',
            'aba_origem' => $linha->aba_origem,
            'modo_carga_inicial' => $modoCargaInicial,
            'forcar_nova_variacao' => $modoCargaInicial,
            'atributos' => $this->montarAtributosLegadosDaLinha($linha),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function montarAtributosLegadosDaLinha(ImportacaoNormalizadaLinha $linha): array
    {
        $atributos = [];

        foreach ([
            'cor' => $linha->cor,
            'lado' => $linha->lado,
            'material_oficial' => $linha->material_oficial,
            'acabamento_oficial' => $linha->acabamento_oficial,
            'dimensao_1' => $linha->dimensao_1,
            'dimensao_2' => $linha->dimensao_2,
            'dimensao_3' => $linha->dimensao_3,
        ] as $campo => $valor) {
            if ($valor === null || trim((string) $valor) === '') {
                continue;
            }

            $atributos[$campo] = (string) $valor;
        }

        foreach ($this->extrairAtributosCargaInicialDaLinha($linha) as $campo => $valor) {
            $atributos[$campo] = $valor;
        }

        return $atributos;
    }

    /**
     * @return array<string,string>
     */
    private function extrairAtributosCargaInicialDaLinha(ImportacaoNormalizadaLinha $linha): array
    {
        $dadosBrutos = is_array($linha->dados_brutos) ? $linha->dados_brutos : [];
        if ($dadosBrutos === []) {
            return [];
        }

        $aliases = [
            'madeira' => 'madeira',
            'tecidos' => 'tecido_1',
            'tecido' => 'tecido_1',
            'tec. 1' => 'tecido_1',
            'tec 1' => 'tecido_1',
            'tecido 1' => 'tecido_1',
            'tec. 2' => 'tecido_2',
            'tec 2' => 'tecido_2',
            'tecido 2' => 'tecido_2',
            'metal / vidro' => 'metal_vidro',
            'metal/vidro' => 'metal_vidro',
            'metal vidro' => 'metal_vidro',
            'diametro cm' => 'diametro_cm',
            'largura cm' => 'largura_cm',
            'profundidade cm' => 'profundidade_cm',
            'altura cm' => 'altura_cm',
            'comprimento cm' => 'comprimento_cm',
            'espessura cm' => 'espessura_cm',
        ];

        $atributos = [];

        foreach ($dadosBrutos as $chave => $valor) {
            if (!is_scalar($valor) || trim((string) $valor) === '') {
                continue;
            }

            $normalizada = (string) Str::of((string) $chave)
                ->squish()
                ->lower()
                ->ascii()
                ->replace(['_', ':'], ' ');
            $normalizada = preg_replace('/\s+/', ' ', $normalizada);
            $normalizada = trim((string) $normalizada);

            $atributo = $aliases[$normalizada] ?? null;
            if ($atributo === null) {
                continue;
            }

            $atributos[$atributo] = trim((string) $valor);
        }

        return $atributos;
    }

    private function resolverNomeDepositoDaLinha(ImportacaoNormalizadaLinha $linha): ?string
    {
        if (!$linha->gera_estoque) {
            return null;
        }

        $aba = Str::of((string) $linha->aba_origem)->squish()->lower()->ascii()->toString();
        $status = Str::of((string) ($linha->status_normalizado ?: $linha->status))->squish()->lower()->ascii()->toString();

        if (Str::contains($status, 'loja')) {
            return 'Loja';
        }

        if (Str::contains($status, 'deposito')) {
            if (Str::contains($aba, 'deposito jb')) {
                return 'Depósito JB';
            }

            return 'Depósito';
        }

        if (Str::contains($aba, 'loja')) {
            return 'Loja';
        }

        if (Str::contains($aba, 'deposito jb')) {
            return 'Depósito JB';
        }

        if (Str::contains($aba, 'deposito')) {
            return 'Depósito';
        }

        if (Str::contains($aba, 'adornos')) {
            return 'Loja';
        }

        return null;
    }

    private function aplicarLocalizacaoAoEstoque(int $variacaoId, int $depositoId, ?string $localizacao): void
    {
        $localizacao = trim((string) $localizacao);
        if ($localizacao === '') {
            return;
        }

        /** @var Estoque|null $estoque */
        $estoque = Estoque::query()
            ->where('id_variacao', $variacaoId)
            ->where('id_deposito', $depositoId)
            ->first();

        if (!$estoque) {
            return;
        }

        $parsed = $this->localizacaoParser->parse($localizacao);
        if (($parsed['tipo'] ?? null) === 'vazio') {
            return;
        }

        $areaId = null;
        if (!empty($parsed['area'])) {
            $area = AreaEstoque::firstOrCreate([
                'nome' => mb_convert_case((string) $parsed['area'], MB_CASE_TITLE, 'UTF-8'),
            ]);
            $areaId = (int) $area->id;
        }

        $estoque->localizacao()->updateOrCreate(
            ['estoque_id' => $estoque->id],
            [
                'setor' => $parsed['setor'] ?? null,
                'coluna' => $parsed['coluna'] ?? null,
                'nivel' => $parsed['nivel'] ?? null,
                'area_id' => $areaId,
                'codigo_composto' => $parsed['codigo'] ?? null,
                'observacoes' => 'Aplicado automaticamente pela importação normalizada.',
            ]
        );
    }

    private function montarObservacaoMovimentacao(ImportacaoNormalizada $importacao, ImportacaoNormalizadaLinha $linha): string
    {
        $partes = [
            sprintf('Importação normalizada #%d', $importacao->id),
            'Aba: ' . $linha->aba_origem,
            'Linha: ' . $linha->linha_planilha,
            'SKU: ' . $linha->sku_interno,
            'Status: ' . ($linha->status_normalizado ?: $linha->status ?: 'sem status'),
        ];

        if (!empty($linha->localizacao)) {
            $partes[] = 'Localização: ' . $linha->localizacao;
        }

        return implode(' | ', $partes);
    }

    private function isCargaInicial(ImportacaoNormalizada $importacao): bool
    {
        return (string) $importacao->tipo === 'planilha_sierra_carga_inicial';
    }

    private function assertFluxoImportacaoValido(ImportacaoNormalizada $importacao, bool $modoCargaInicial): void
    {
        $tipo = (string) $importacao->tipo;

        if (str_contains($tipo, 'conta_azul')) {
            throw new RuntimeException('Fluxo inválido: importação Conta Azul não pode usar pipeline Sierra.');
        }

        if ($modoCargaInicial && !$this->isCargaInicial($importacao)) {
            throw new RuntimeException('A importação informada não está marcada como carga inicial Sierra.');
        }
    }
}
