<?php

namespace App\Support\ResetImagens;

use App\Models\ProdutoImagem;
use App\Models\ProdutoVariacaoImagem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class ProdutoImagemResetService
{
    private const EXPORT_ROOT = 'operations/reset-imagens';

    /**
     * @var list<string>
     */
    private const PENDING_HEADERS = [
        'operacao',
        'motivo',
        'tipo',
        'imagem_id_antiga',
        'produto_id_antigo',
        'variacao_id_antiga',
        'codigo_produto',
        'produto_nome',
        'variacao_nome',
        'sku_interno',
        'chave_variacao',
        'referencia',
        'url_armazenada',
        'caminho_relativo',
        'detalhe',
        'candidatos',
    ];

    /**
     * @return array{
     *   success: bool,
     *   manifest_path: string,
     *   manifest_absolute_path: string,
     *   summary_path: string,
     *   summary_absolute_path: string,
     *   pending_path: string,
     *   pending_absolute_path: string,
     *   output_dir: string,
     *   output_absolute_dir: string,
     *   total_items: int,
     *   missing_files: int
     * }
     */
    public function export(bool $allowMissingFiles = false): array
    {
        $outputDir = $this->makeOutputDirectory(self::EXPORT_ROOT);

        $items = collect(array_merge(
            $this->buildProdutoExportItems(),
            $this->buildVariacaoExportItems()
        ))->values();

        $pendencias = $items
            ->filter(fn (array $item) => !$item['arquivo_existe'])
            ->map(fn (array $item) => $this->makePendingRow(
                'export',
                'arquivo_ausente',
                $item,
                'Arquivo cadastrado no banco nao foi encontrado no storage publico.'
            ))
            ->values();

        $manifestPath = "{$outputDir}/manifest.json";
        $summaryPath = "{$outputDir}/summary.json";
        $pendingPath = "{$outputDir}/pendencias.csv";

        $missingFiles = $pendencias->count();
        $success = $allowMissingFiles || $missingFiles === 0;

        $manifest = [
            'manifest_version' => 1,
            'operacao' => 'export',
            'gerado_em' => now()->toIso8601String(),
            'allow_missing_files' => $allowMissingFiles,
            'items_total' => $items->count(),
            'items' => $items->all(),
        ];

        $summary = [
            'manifest_version' => 1,
            'operacao' => 'export',
            'status' => $success ? 'ok' : 'failed_missing_files',
            'gerado_em' => now()->toIso8601String(),
            'output_dir' => $outputDir,
            'manifest_path' => $manifestPath,
            'pendencias_path' => $pendingPath,
            'items_total' => $items->count(),
            'por_tipo' => $items->countBy('tipo')->all(),
            'arquivos_ausentes' => $missingFiles,
            'pendencias_total' => $pendencias->count(),
            'pendencias_por_motivo' => $pendencias->countBy('motivo')->all(),
            'allow_missing_files' => $allowMissingFiles,
        ];

        $this->writeJson($manifestPath, $manifest);
        $this->writeJson($summaryPath, $summary);
        $this->writePendingCsv($pendingPath, $pendencias->all());

        return [
            'success' => $success,
            'manifest_path' => $manifestPath,
            'manifest_absolute_path' => $this->localDisk()->path($manifestPath),
            'summary_path' => $summaryPath,
            'summary_absolute_path' => $this->localDisk()->path($summaryPath),
            'pending_path' => $pendingPath,
            'pending_absolute_path' => $this->localDisk()->path($pendingPath),
            'output_dir' => $outputDir,
            'output_absolute_dir' => $this->localDisk()->path($outputDir),
            'total_items' => $items->count(),
            'missing_files' => $missingFiles,
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   summary_path: string,
     *   summary_absolute_path: string,
     *   pending_path: string,
     *   pending_absolute_path: string,
     *   output_dir: string,
     *   output_absolute_dir: string,
     *   manifest_path: string,
     *   manifest_absolute_path: string,
     *   total_items: int,
     *   relinked_produtos: int,
     *   relinked_variacoes: int,
     *   pending_total: int
     * }
     */
    public function relink(string $manifestPath): array
    {
        $manifest = $this->readManifest($manifestPath);
        $items = collect($manifest['items'] ?? [])->values();
        $resolvedManifest = $this->resolveManifestPath($manifestPath);

        $outputDir = $this->makeRelinkOutputDirectory($resolvedManifest['storage_relative']);
        $summaryPath = "{$outputDir}/summary.json";
        $pendingPath = "{$outputDir}/pendencias.csv";

        $lookups = $this->buildRelinkLookups();
        $pendencias = collect();
        $estrategias = [];
        $relinkedProdutos = 0;
        $relinkedVariacoes = 0;

        /**
         * @var array<int, array<int, array{id: int, principal: bool}>>
         */
        $produtoImagensRelinkadas = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $tipo = (string) ($item['tipo'] ?? '');
            $item['caminho_relativo'] = $this->resolveManifestRelativePath($item);

            if (!$this->arquivoExisteNoStorage($item['caminho_relativo'] ?? null)) {
                $pendencias->push($this->makePendingRow(
                    'relink',
                    'arquivo_ausente',
                    $item,
                    'Arquivo nao esta mais disponivel no volume restaurado; reenvio manual pode ser necessario.'
                ));
                continue;
            }

            if ($tipo === 'produto') {
                $match = $this->matchProduto($item, $lookups);
                if (!$match['matched']) {
                    $pendencias->push($this->makePendingRow(
                        'relink',
                        $match['reason'],
                        $item,
                        $match['detail'],
                        $match['candidate_ids']
                    ));
                    continue;
                }

                $filename = basename((string) $item['caminho_relativo']);
                if ($filename === '' || $filename === '.' || $filename === '/') {
                    $pendencias->push($this->makePendingRow(
                        'relink',
                        'arquivo_ausente',
                        $item,
                        'Nao foi possivel derivar o nome do arquivo da imagem do produto.'
                    ));
                    continue;
                }

                $imagem = ProdutoImagem::query()->updateOrCreate(
                    [
                        'id_produto' => $match['entity_id'],
                        'url' => $filename,
                    ],
                    [
                        'principal' => false,
                    ]
                );

                $produtoImagensRelinkadas[$match['entity_id']][] = [
                    'id' => (int) $imagem->id,
                    'principal' => (bool) ($item['principal'] ?? false),
                ];

                $estrategias[$match['strategy']] = ($estrategias[$match['strategy']] ?? 0) + 1;
                $relinkedProdutos++;
                continue;
            }

            if ($tipo === 'variacao') {
                $match = $this->matchVariacao($item, $lookups);
                if (!$match['matched']) {
                    $pendencias->push($this->makePendingRow(
                        'relink',
                        $match['reason'],
                        $item,
                        $match['detail'],
                        $match['candidate_ids']
                    ));
                    continue;
                }

                ProdutoVariacaoImagem::query()->updateOrCreate(
                    ['id_variacao' => $match['entity_id']],
                    ['url' => $this->publicDisk()->url((string) $item['caminho_relativo'])]
                );

                $estrategias[$match['strategy']] = ($estrategias[$match['strategy']] ?? 0) + 1;
                $relinkedVariacoes++;
                continue;
            }

            $pendencias->push($this->makePendingRow(
                'relink',
                'erro',
                $item,
                'Tipo de item nao suportado no manifesto.'
            ));
        }

        $this->reaplicarPrincipais($produtoImagensRelinkadas);

        $summary = [
            'manifest_version' => 1,
            'operacao' => 'relink',
            'status' => 'ok',
            'gerado_em' => now()->toIso8601String(),
            'manifest_path' => $resolvedManifest['storage_relative'] ?? $resolvedManifest['absolute_path'],
            'output_dir' => $outputDir,
            'items_total' => $items->count(),
            'relinkados' => [
                'produto' => $relinkedProdutos,
                'variacao' => $relinkedVariacoes,
            ],
            'pendencias_total' => $pendencias->count(),
            'pendencias_por_motivo' => $pendencias->countBy('motivo')->all(),
            'estrategias' => $estrategias,
        ];

        $this->writeJson($summaryPath, $summary);
        $this->writePendingCsv($pendingPath, $pendencias->all());

        return [
            'success' => true,
            'summary_path' => $summaryPath,
            'summary_absolute_path' => $this->localDisk()->path($summaryPath),
            'pending_path' => $pendingPath,
            'pending_absolute_path' => $this->localDisk()->path($pendingPath),
            'output_dir' => $outputDir,
            'output_absolute_dir' => $this->localDisk()->path($outputDir),
            'manifest_path' => $resolvedManifest['storage_relative'] ?? $resolvedManifest['absolute_path'],
            'manifest_absolute_path' => $resolvedManifest['absolute_path'],
            'total_items' => $items->count(),
            'relinked_produtos' => $relinkedProdutos,
            'relinked_variacoes' => $relinkedVariacoes,
            'pending_total' => $pendencias->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildProdutoExportItems(): array
    {
        return DB::table('produto_imagens as pi')
            ->join('produtos as p', 'p.id', '=', 'pi.id_produto')
            ->select([
                'pi.id as imagem_id_antiga',
                'pi.id_produto as produto_id_antigo',
                'pi.url as url_armazenada',
                'pi.principal',
                'p.codigo_produto',
                'p.nome as produto_nome',
            ])
            ->orderBy('pi.id')
            ->get()
            ->map(function (object $row): array {
                $relativePath = $this->resolveProdutoRelativePath((string) $row->url_armazenada);
                $arquivoExiste = $this->arquivoExisteNoStorage($relativePath);

                return [
                    'tipo' => 'produto',
                    'imagem_id_antiga' => (int) $row->imagem_id_antiga,
                    'produto_id_antigo' => (int) $row->produto_id_antigo,
                    'variacao_id_antiga' => null,
                    'codigo_produto' => $this->nullableString($row->codigo_produto),
                    'produto_nome' => $this->nullableString($row->produto_nome),
                    'produto_nome_normalizado' => $this->normalizeName($row->produto_nome),
                    'variacao_nome' => null,
                    'sku_interno' => null,
                    'chave_variacao' => null,
                    'referencia' => null,
                    'url_armazenada' => $this->nullableString($row->url_armazenada),
                    'caminho_relativo' => $relativePath,
                    'principal' => (bool) $row->principal,
                    'arquivo_existe' => $arquivoExiste,
                    'sha256' => $arquivoExiste ? $this->sha256ArquivoPublico($relativePath) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariacaoExportItems(): array
    {
        return DB::table('produto_variacao_imagens as pvi')
            ->join('produto_variacoes as pv', 'pv.id', '=', 'pvi.id_variacao')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->select([
                'pvi.id as imagem_id_antiga',
                'pvi.id_variacao as variacao_id_antiga',
                'pv.produto_id as produto_id_antigo',
                'pvi.url as url_armazenada',
                'pv.sku_interno',
                'pv.chave_variacao',
                'pv.referencia',
                'pv.nome as variacao_nome',
                'p.codigo_produto',
                'p.nome as produto_nome',
            ])
            ->orderBy('pvi.id')
            ->get()
            ->map(function (object $row): array {
                $relativePath = $this->resolveVariacaoRelativePath((string) $row->url_armazenada);
                $arquivoExiste = $this->arquivoExisteNoStorage($relativePath);

                return [
                    'tipo' => 'variacao',
                    'imagem_id_antiga' => (int) $row->imagem_id_antiga,
                    'produto_id_antigo' => (int) $row->produto_id_antigo,
                    'variacao_id_antiga' => (int) $row->variacao_id_antiga,
                    'codigo_produto' => $this->nullableString($row->codigo_produto),
                    'produto_nome' => $this->nullableString($row->produto_nome),
                    'produto_nome_normalizado' => $this->normalizeName($row->produto_nome),
                    'variacao_nome' => $this->nullableString($row->variacao_nome),
                    'sku_interno' => $this->nullableString($row->sku_interno),
                    'chave_variacao' => $this->nullableString($row->chave_variacao),
                    'referencia' => $this->nullableString($row->referencia),
                    'url_armazenada' => $this->nullableString($row->url_armazenada),
                    'caminho_relativo' => $relativePath,
                    'principal' => null,
                    'arquivo_existe' => $arquivoExiste,
                    'sha256' => $arquivoExiste ? $this->sha256ArquivoPublico($relativePath) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $lookups
     * @return array{
     *   matched: bool,
     *   entity_id: int|null,
     *   strategy: string|null,
     *   reason: string,
     *   detail: string,
     *   candidate_ids: list<int>
     * }
     */
    private function matchProduto(array $item, array $lookups): array
    {
        $codigoToken = $this->normalizeLookupToken($item['codigo_produto'] ?? null);
        $nomeNormalizado = $this->normalizeName($item['produto_nome_normalizado'] ?? $item['produto_nome'] ?? null);
        $candidateIds = [];
        $hadMultiple = false;

        if ($codigoToken !== null) {
            /** @var Collection<int, array{id: int, nome_normalizado: string|null}> $byCode */
            $byCode = $lookups['produtos_por_codigo']->get($codigoToken, collect());
            if ($byCode->count() === 1) {
                return $this->matchedResult((int) $byCode->first()['id'], 'produto.codigo_unico');
            }

            if ($byCode->count() > 1) {
                $hadMultiple = true;
                $candidateIds = array_values(array_unique(array_merge(
                    $candidateIds,
                    $byCode->pluck('id')->map(fn ($id) => (int) $id)->all()
                )));

                if ($nomeNormalizado !== null) {
                    $byCodeAndName = $byCode
                        ->filter(fn (array $produto) => $produto['nome_normalizado'] === $nomeNormalizado)
                        ->values();

                    if ($byCodeAndName->count() === 1) {
                        return $this->matchedResult((int) $byCodeAndName->first()['id'], 'produto.codigo_nome_normalizado');
                    }

                    if ($byCodeAndName->count() > 1) {
                        $candidateIds = array_values(array_unique(array_merge(
                            $candidateIds,
                            $byCodeAndName->pluck('id')->map(fn ($id) => (int) $id)->all()
                        )));
                    }
                }
            }
        }

        if ($nomeNormalizado !== null) {
            /** @var Collection<int, array{id: int}> $byName */
            $byName = $lookups['produtos_por_nome']->get($nomeNormalizado, collect());
            if ($byName->count() === 1) {
                return $this->matchedResult((int) $byName->first()['id'], 'produto.nome_normalizado_unico');
            }

            if ($byName->count() > 1) {
                $hadMultiple = true;
                $candidateIds = array_values(array_unique(array_merge(
                    $candidateIds,
                    $byName->pluck('id')->map(fn ($id) => (int) $id)->all()
                )));
            }
        }

        return [
            'matched' => false,
            'entity_id' => null,
            'strategy' => null,
            'reason' => $hadMultiple ? 'multiplos_matches' : 'nenhum_match',
            'detail' => $hadMultiple
                ? 'Mais de um produto plausivel foi encontrado; o item ficou para revisao manual.'
                : 'Nenhum produto correspondente foi localizado para a imagem exportada.',
            'candidate_ids' => $candidateIds,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $lookups
     * @return array{
     *   matched: bool,
     *   entity_id: int|null,
     *   strategy: string|null,
     *   reason: string,
     *   detail: string,
     *   candidate_ids: list<int>
     * }
     */
    private function matchVariacao(array $item, array $lookups): array
    {
        $candidateIds = [];
        $hadMultiple = false;

        $skuToken = $this->normalizeLookupToken($item['sku_interno'] ?? null);
        if ($skuToken !== null) {
            /** @var Collection<int, array{id: int}> $bySku */
            $bySku = $lookups['variacoes_por_sku']->get($skuToken, collect());
            if ($bySku->count() === 1) {
                return $this->matchedResult((int) $bySku->first()['id'], 'variacao.sku_interno');
            }

            if ($bySku->count() > 1) {
                $hadMultiple = true;
                $candidateIds = array_values(array_unique(array_merge(
                    $candidateIds,
                    $bySku->pluck('id')->map(fn ($id) => (int) $id)->all()
                )));
            }
        }

        $chaveToken = $this->normalizeLookupToken($item['chave_variacao'] ?? null);
        if ($chaveToken !== null) {
            /** @var Collection<int, array{id: int}> $byChave */
            $byChave = $lookups['variacoes_por_chave']->get($chaveToken, collect());
            if ($byChave->count() === 1) {
                return $this->matchedResult((int) $byChave->first()['id'], 'variacao.chave_variacao');
            }

            if ($byChave->count() > 1) {
                $hadMultiple = true;
                $candidateIds = array_values(array_unique(array_merge(
                    $candidateIds,
                    $byChave->pluck('id')->map(fn ($id) => (int) $id)->all()
                )));
            }
        }

        $codigoRefToken = $this->makeCodigoReferenciaToken(
            $item['codigo_produto'] ?? null,
            $item['referencia'] ?? null
        );
        if ($codigoRefToken !== null) {
            /** @var Collection<int, array{id: int}> $byCodigoReferencia */
            $byCodigoReferencia = $lookups['variacoes_por_codigo_referencia']->get($codigoRefToken, collect());
            if ($byCodigoReferencia->count() === 1) {
                return $this->matchedResult((int) $byCodigoReferencia->first()['id'], 'variacao.codigo_produto_referencia');
            }

            if ($byCodigoReferencia->count() > 1) {
                $hadMultiple = true;
                $candidateIds = array_values(array_unique(array_merge(
                    $candidateIds,
                    $byCodigoReferencia->pluck('id')->map(fn ($id) => (int) $id)->all()
                )));
            }
        }

        return [
            'matched' => false,
            'entity_id' => null,
            'strategy' => null,
            'reason' => $hadMultiple ? 'multiplos_matches' : 'nenhum_match',
            'detail' => $hadMultiple
                ? 'Mais de uma variacao plausivel foi encontrada; o item ficou para revisao manual.'
                : 'Nenhuma variacao correspondente foi localizada para a imagem exportada.',
            'candidate_ids' => $candidateIds,
        ];
    }

    /**
     * @param array<int, array<int, array{id: int, principal: bool}>> $produtoImagensRelinkadas
     */
    private function reaplicarPrincipais(array $produtoImagensRelinkadas): void
    {
        foreach ($produtoImagensRelinkadas as $produtoId => $imagens) {
            $ids = collect($imagens)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                continue;
            }

            $principal = collect($imagens)->firstWhere('principal', true);
            $principalId = is_array($principal) ? (int) ($principal['id'] ?? 0) : null;

            if ($principalId === null || $principalId <= 0) {
                continue;
            }

            ProdutoImagem::query()
                ->where('id_produto', $produtoId)
                ->whereIn('id', $ids)
                ->update(['principal' => false]);

            ProdutoImagem::query()
                ->where('id_produto', $produtoId)
                ->where('id', (int) $principalId)
                ->update(['principal' => true]);
        }
    }

    /**
     * @return array<string, Collection>
     */
    private function buildRelinkLookups(): array
    {
        $produtos = DB::table('produtos')
            ->select(['id', 'codigo_produto', 'nome'])
            ->orderBy('id')
            ->get()
            ->map(function (object $row): array {
                return [
                    'id' => (int) $row->id,
                    'codigo_token' => $this->normalizeLookupToken($row->codigo_produto),
                    'nome_normalizado' => $this->normalizeName($row->nome),
                ];
            })
            ->values();

        $variacoes = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->select([
                'pv.id',
                'pv.produto_id',
                'pv.referencia',
                'pv.sku_interno',
                'pv.chave_variacao',
                'p.codigo_produto',
            ])
            ->orderBy('pv.id')
            ->get()
            ->map(function (object $row): array {
                return [
                    'id' => (int) $row->id,
                    'produto_id' => (int) $row->produto_id,
                    'sku_token' => $this->normalizeLookupToken($row->sku_interno),
                    'chave_token' => $this->normalizeLookupToken($row->chave_variacao),
                    'codigo_referencia_token' => $this->makeCodigoReferenciaToken(
                        $row->codigo_produto,
                        $row->referencia
                    ),
                ];
            })
            ->values();

        return [
            'produtos_por_codigo' => $produtos
                ->filter(fn (array $produto) => $produto['codigo_token'] !== null)
                ->groupBy('codigo_token'),
            'produtos_por_nome' => $produtos
                ->filter(fn (array $produto) => $produto['nome_normalizado'] !== null)
                ->groupBy('nome_normalizado'),
            'variacoes_por_sku' => $variacoes
                ->filter(fn (array $variacao) => $variacao['sku_token'] !== null)
                ->groupBy('sku_token'),
            'variacoes_por_chave' => $variacoes
                ->filter(fn (array $variacao) => $variacao['chave_token'] !== null)
                ->groupBy('chave_token'),
            'variacoes_por_codigo_referencia' => $variacoes
                ->filter(fn (array $variacao) => $variacao['codigo_referencia_token'] !== null)
                ->groupBy('codigo_referencia_token'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $manifestPath): array
    {
        $resolved = $this->resolveManifestPath($manifestPath);
        $content = @file_get_contents($resolved['absolute_path']);
        if ($content === false) {
            throw new RuntimeException('Nao foi possivel ler o manifest informado.');
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Manifest JSON invalido: ' . $exception->getMessage(), previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
            throw new RuntimeException('Manifest sem a chave items em formato valido.');
        }

        return $decoded;
    }

    /**
     * @return array{absolute_path: string, storage_relative: string|null}
     */
    private function resolveManifestPath(string $manifestPath): array
    {
        $input = trim($manifestPath);
        if ($input === '') {
            throw new RuntimeException('Informe o caminho do manifest.json.');
        }

        $candidates = array_values(array_unique(array_filter([
            $input,
            base_path($input),
            storage_path('app/' . ltrim($input, '/')),
        ], fn ($value) => is_string($value) && $value !== '')));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return [
                    'absolute_path' => $candidate,
                    'storage_relative' => $this->storageRelativeFromAbsolutePath($candidate),
                ];
            }
        }

        if ($this->localDisk()->exists($input)) {
            $absolutePath = $this->localDisk()->path($input);

            return [
                'absolute_path' => $absolutePath,
                'storage_relative' => $input,
            ];
        }

        throw new RuntimeException("Manifest nao encontrado: {$manifestPath}");
    }

    private function storageRelativeFromAbsolutePath(string $absolutePath): ?string
    {
        $root = $this->normalizeFilesystemPath($this->localDisk()->path(''));
        $target = $this->normalizeFilesystemPath($absolutePath);

        if (!Str::startsWith($target, $root)) {
            return null;
        }

        return ltrim(substr($target, strlen($root)), '/');
    }

    private function makeOutputDirectory(string $root): string
    {
        $directory = trim($root, '/') . '/' . now()->format('Ymd-His');
        $this->localDisk()->makeDirectory($directory);

        return $directory;
    }

    private function makeRelinkOutputDirectory(?string $manifestStorageRelative): string
    {
        if ($manifestStorageRelative !== null) {
            $baseDirectory = trim(dirname($manifestStorageRelative), './');
            if ($baseDirectory === '') {
                $baseDirectory = self::EXPORT_ROOT;
            }

            $directory = $baseDirectory . '/relink-' . now()->format('Ymd-His');
            $this->localDisk()->makeDirectory($directory);

            return $directory;
        }

        return $this->makeOutputDirectory(self::EXPORT_ROOT . '/relink');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Falha ao gerar JSON operacional: ' . $exception->getMessage(), previous: $exception);
        }

        $this->localDisk()->put($path, $json . PHP_EOL);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writePendingCsv(string $path, array $rows): void
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Falha ao abrir buffer temporario para o CSV de pendencias.');
        }

        fputcsv($stream, self::PENDING_HEADERS);
        foreach ($rows as $row) {
            $line = [];
            foreach (self::PENDING_HEADERS as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($stream, $line);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $this->localDisk()->put($path, $contents ?: '');
    }

    /**
     * @param array<string, mixed> $item
     * @param list<int> $candidateIds
     * @return array<string, mixed>
     */
    private function makePendingRow(
        string $operacao,
        string $motivo,
        array $item,
        string $detalhe,
        array $candidateIds = []
    ): array {
        return [
            'operacao' => $operacao,
            'motivo' => $motivo,
            'tipo' => (string) ($item['tipo'] ?? ''),
            'imagem_id_antiga' => $item['imagem_id_antiga'] ?? '',
            'produto_id_antigo' => $item['produto_id_antigo'] ?? '',
            'variacao_id_antiga' => $item['variacao_id_antiga'] ?? '',
            'codigo_produto' => $item['codigo_produto'] ?? '',
            'produto_nome' => $item['produto_nome'] ?? '',
            'variacao_nome' => $item['variacao_nome'] ?? '',
            'sku_interno' => $item['sku_interno'] ?? '',
            'chave_variacao' => $item['chave_variacao'] ?? '',
            'referencia' => $item['referencia'] ?? '',
            'url_armazenada' => $item['url_armazenada'] ?? '',
            'caminho_relativo' => $item['caminho_relativo'] ?? '',
            'detalhe' => $detalhe,
            'candidatos' => implode(',', $candidateIds),
        ];
    }

    /**
     * @return array{
     *   matched: true,
     *   entity_id: int,
     *   strategy: string,
     *   reason: string,
     *   detail: string,
     *   candidate_ids: list<int>
     * }
     */
    private function matchedResult(int $entityId, string $strategy): array
    {
        return [
            'matched' => true,
            'entity_id' => $entityId,
            'strategy' => $strategy,
            'reason' => '',
            'detail' => '',
            'candidate_ids' => [],
        ];
    }

    private function resolveManifestRelativePath(array $item): ?string
    {
        $relativePath = $this->nullableString($item['caminho_relativo'] ?? null);
        if ($relativePath !== null) {
            return $this->normalizeRelativePath($relativePath);
        }

        $tipo = (string) ($item['tipo'] ?? '');
        if ($tipo === 'produto') {
            return $this->resolveProdutoRelativePath($item['url_armazenada'] ?? null);
        }

        if ($tipo === 'variacao') {
            return $this->resolveVariacaoRelativePath($item['url_armazenada'] ?? null);
        }

        return null;
    }

    private function resolveProdutoRelativePath(mixed $storedUrl): ?string
    {
        return $this->resolvePublicRelativePath($storedUrl, ProdutoImagem::FOLDER);
    }

    private function resolveVariacaoRelativePath(mixed $storedUrl): ?string
    {
        return $this->resolvePublicRelativePath($storedUrl, ProdutoImagem::FOLDER . '/variacoes');
    }

    private function resolvePublicRelativePath(mixed $storedUrl, string $expectedPrefix): ?string
    {
        $value = $this->nullableString($storedUrl);
        if ($value === null) {
            return null;
        }

        $parsedPath = parse_url($value, PHP_URL_PATH);
        if (is_string($parsedPath) && trim($parsedPath) !== '') {
            $value = $parsedPath;
        }

        $value = '/' . ltrim(str_replace('\\', '/', $value), '/');

        if (Str::startsWith($value, '/uploads/')) {
            $value = '/storage/' . ltrim(Str::after($value, '/uploads/'), '/');
        }

        if (Str::startsWith($value, '/storage/')) {
            $relative = ltrim(Str::after($value, '/storage/'), '/');
        } else {
            $relative = ltrim($value, '/');
        }

        $relative = $this->normalizeRelativePath($relative);
        $expectedPrefix = trim($expectedPrefix, '/');

        $normalizedPrefixPattern = '#^(?:' . preg_quote($expectedPrefix, '#') . '/)+#';
        $relative = preg_replace($normalizedPrefixPattern, $expectedPrefix . '/', $relative) ?? $relative;

        if ($relative === $expectedPrefix) {
            return null;
        }

        if (!Str::startsWith($relative, $expectedPrefix . '/')) {
            $filename = basename($relative);
            if ($filename === '' || $filename === '.' || $filename === '/') {
                return null;
            }

            $relative = $expectedPrefix . '/' . $filename;
        }

        return $this->normalizeRelativePath($relative);
    }

    private function arquivoExisteNoStorage(?string $relativePath): bool
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return false;
        }

        return $this->publicDisk()->exists($relativePath);
    }

    private function sha256ArquivoPublico(?string $relativePath): ?string
    {
        if (!$this->arquivoExisteNoStorage($relativePath)) {
            return null;
        }

        try {
            $contents = $this->publicDisk()->get((string) $relativePath);

            return hash('sha256', $contents);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeName(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $normalized = (string) Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeLookupToken(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $normalized = (string) Str::of($value)
            ->lower()
            ->squish();

        return $normalized !== '' ? $normalized : null;
    }

    private function makeCodigoReferenciaToken(mixed $codigoProduto, mixed $referencia): ?string
    {
        $codigoToken = $this->normalizeLookupToken($codigoProduto);
        $referenciaToken = $this->normalizeLookupToken($referencia);

        if ($codigoToken === null || $referenciaToken === null) {
            return null;
        }

        return $codigoToken . '|' . $referenciaToken;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return ltrim($path, '/');
    }

    private function normalizeFilesystemPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function localDisk()
    {
        return Storage::disk('local');
    }

    private function publicDisk()
    {
        return Storage::disk('public');
    }
}
