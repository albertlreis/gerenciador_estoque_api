<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\ProdutoVariacao;
use App\Http\Controllers\PedidoController;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Throwable;

class TestImportPedidosPdfBatchCommand extends Command
{
    private ?int $categoriaPadraoId = null;
    private array $cacheCategoriaPorRef = [];

    protected $signature = 'sierra:test-import-pedidos-pdf
        {--dir= : Diretório com PDFs}
        {--commit=0 : Persistir no banco (1) ou executar com rollback (0)}
        {--confirm=1 : Executar etapa de confirmação (1) ou apenas importar (0)}
        {--timeout=60 : Timeout (segundos) para chamada ao leitor PDF}';

    protected $description = 'Executa teste batch de importação de pedidos via PDF (importar + confirmar), gerando relatório por arquivo.';

    public function handle(): int
    {
        $inicioBatch = microtime(true);
        $timestamp = now()->format('Ymd_His');
        $requestBatchId = (string) Str::uuid();
        $reportDir = storage_path("logs/import-pdf-tests/{$timestamp}");
        File::ensureDirectoryExists($reportDir);

        $dir = $this->resolveDir((string) $this->option('dir'));
        $shouldCommit = $this->toBool($this->option('commit'), false);
        $shouldConfirm = $this->toBool($this->option('confirm'), true);
        $timeout = max(1, (int) $this->option('timeout'));

        if (!$dir || !is_dir($dir)) {
            $this->error("Diretório inválido: {$dir}");
            return self::FAILURE;
        }

        config([
            'services.extrator_pedido.url' => 'http://localhost:8010/extrair-pedido',
            'services.extrator_pedido.timeout' => $timeout,
        ]);

        $this->line("Batch ID: {$requestBatchId}");
        $this->line("Diretório: {$dir}");
        $this->line('Modo commit: ' . ($shouldCommit ? 'SIM' : 'NAO (rollback)'));
        $this->line('Confirmação: ' . ($shouldConfirm ? 'SIM' : 'NAO'));
        $this->line("Relatórios: {$reportDir}");

        $files = collect(glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf'))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->warn('Nenhum PDF encontrado no diretório informado.');
            return self::SUCCESS;
        }

        $usuario = $this->resolveUsuario();
        $controller = app(PedidoController::class);
        $rows = [];
        $results = [];

        foreach ($files as $filePath) {
            $arquivo = basename($filePath);
            $tipo = $this->detectarTipoImportacao($arquivo);
            $requestId = (string) Str::uuid();
            $inicioArquivo = microtime(true);

            $resumo = [
                'fornecedor_cliente' => null,
                'data_pedido' => null,
                'total' => null,
                'numero_itens' => null,
            ];

            $resultado = [
                'batch_id' => $requestBatchId,
                'request_id' => $requestId,
                'arquivo' => $arquivo,
                'caminho' => $filePath,
                'tipo_importacao' => $tipo,
                'status' => 'FAIL',
                'tempo_ms' => 0,
                'resumo' => $resumo,
                'erro' => null,
                'import_response' => null,
                'confirm_response' => null,
                'commit' => $shouldCommit,
                'confirm' => $shouldConfirm,
                'timestamp' => now()->toIso8601String(),
            ];

            try {
                if (!is_file($filePath) || filesize($filePath) <= 0) {
                    throw new \RuntimeException('Arquivo inválido ou vazio.');
                }

                if (!$shouldCommit) {
                    DB::beginTransaction();
                }

                Auth::setUser($usuario);

                $uploaded = $this->makeUploadedFile($filePath);

                $importRequest = Request::create('/api/v1/pedidos/import', 'POST', [
                    'tipo_importacao' => $tipo,
                ], [], ['arquivo' => $uploaded]);
                $importRequest->headers->set('X-Request-Id', $requestId);
                $importRequest->setUserResolver(fn () => $usuario);

                $importResponse = $controller->importar($importRequest);
                $importPayload = json_decode((string) $importResponse->getContent(), true);
                $resultado['import_response'] = $importPayload;

                if (($importResponse->getStatusCode() ?? 500) >= 400 || !($importPayload['sucesso'] ?? false)) {
                    throw new \RuntimeException($importPayload['erro'] ?? $importPayload['mensagem'] ?? 'Falha na etapa de importação.');
                }

                $dados = (array) ($importPayload['dados'] ?? []);
                $pedidoDados = (array) ($dados['pedido'] ?? []);
                $totaisDados = (array) ($dados['totais'] ?? []);
                $itensDados = (array) ($dados['itens'] ?? []);

                $resumo = [
                    'fornecedor_cliente' => $pedidoDados['cliente'] ?? $pedidoDados['fornecedor'] ?? null,
                    'data_pedido' => $pedidoDados['data_pedido'] ?? null,
                    'total' => $pedidoDados['total'] ?? $totaisDados['total_liquido'] ?? $totaisDados['total_bruto'] ?? null,
                    'numero_itens' => count($itensDados),
                ];
                $resultado['resumo'] = $resumo;

                if ($shouldConfirm) {
                    $confirmPayload = $dados;
                    $confirmPayload['request_id'] = $requestId;
                    $confirmPayload['importacao_id'] = $importPayload['importacao_id'] ?? null;
                    $confirmPayload['tipo_importacao'] = $tipo;
                    $confirmPayload['pedido'] = array_merge(
                        ['tipo' => 'reposicao'],
                        (array) ($dados['pedido'] ?? [])
                    );
                    $confirmPayload['cliente'] = (array) ($dados['cliente'] ?? []);
                    $confirmPayload['pedido']['numero_externo'] = $this->numeroExternoBatch(
                        (string) ($confirmPayload['pedido']['numero_externo'] ?? '')
                    );
                    $confirmPayload['itens'] = $this->normalizarItensParaConfirmacao((array) ($dados['itens'] ?? []));

                    $confirmRequest = Request::create('/api/v1/pedidos/import/pdf/confirm', 'POST', $confirmPayload);
                    $confirmRequest->headers->set('X-Request-Id', $requestId);
                    $confirmRequest->setUserResolver(fn () => $usuario);

                    $confirmResponse = $controller->confirmarImportacaoPDF($confirmRequest);
                    $confirmResponsePayload = json_decode((string) $confirmResponse->getContent(), true);
                    $resultado['confirm_response'] = $confirmResponsePayload;

                    if (($confirmResponse->getStatusCode() ?? 500) >= 400) {
                        throw new \RuntimeException(
                            $confirmResponsePayload['message']
                                ?? $confirmResponsePayload['mensagem']
                                ?? json_encode($confirmResponsePayload)
                        );
                    }
                }

                if ($shouldCommit) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }

                $resultado['status'] = 'OK';
            } catch (Throwable $e) {
                if (!$shouldCommit && DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                $resultado['status'] = 'FAIL';
                $resultado['erro'] = [
                    'mensagem' => $e->getMessage(),
                    'classe' => $e::class,
                    'arquivo' => $e->getFile(),
                    'linha' => $e->getLine(),
                ];

                Log::error('batch_importacao_pdf_falha', [
                    'batch_id' => $requestBatchId,
                    'request_id' => $requestId,
                    'arquivo' => $arquivo,
                    'etapa' => 'batch',
                    'erro' => $e->getMessage(),
                ]);
            } finally {
                $resultado['tempo_ms'] = (int) ((microtime(true) - $inicioArquivo) * 1000);

                $reportPath = $reportDir . DIRECTORY_SEPARATOR . $this->sanitizeReportName($arquivo) . '.json';
                File::put($reportPath, json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $rows[] = [
                    $arquivo,
                    $resultado['status'],
                    $resultado['tempo_ms'],
                    (string) ($resultado['resumo']['fornecedor_cliente'] ?? '-'),
                    (string) ($resultado['resumo']['data_pedido'] ?? '-'),
                    (string) ($resultado['resumo']['total'] ?? '-'),
                    (string) ($resultado['resumo']['numero_itens'] ?? '-'),
                    $resultado['erro']['mensagem'] ?? '-',
                ];
                $results[] = $resultado;
            }
        }

        $this->table(
            ['Arquivo', 'Status', 'Tempo(ms)', 'Fornecedor/Cliente', 'Data', 'Total', 'Itens', 'Erro'],
            $rows
        );

        $ok = collect($results)->where('status', 'OK')->count();
        $fail = collect($results)->where('status', 'FAIL')->count();

        $consolidado = [
            'batch_id' => $requestBatchId,
            'executado_em' => now()->toIso8601String(),
            'duracao_ms' => (int) ((microtime(true) - $inicioBatch) * 1000),
            'total_arquivos' => count($results),
            'ok' => $ok,
            'fail' => $fail,
            'resultados' => $results,
        ];

        File::put(
            $reportDir . DIRECTORY_SEPARATOR . 'consolidado.json',
            json_encode($consolidado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->line("Resumo: OK={$ok}, FAIL={$fail}");
        $this->line("Consolidado: {$reportDir}" . DIRECTORY_SEPARATOR . 'consolidado.json');

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveDir(string $optionDir): ?string
    {
        if ($optionDir !== '') {
            return realpath($optionDir) ?: $optionDir;
        }

        $candidates = [
            base_path('../leitor_pdf_sierra'),
            base_path('..\\leitor_pdf_sierra'),
            getcwd() . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'leitor_pdf_sierra',
        ];

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved && is_dir($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function makeUploadedFile(string $filePath): UploadedFile
    {
        $symfonyFile = new SymfonyUploadedFile(
            $filePath,
            basename($filePath),
            'application/pdf',
            null,
            true
        );

        return UploadedFile::createFromBase($symfonyFile, true);
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function detectarTipoImportacao(string $arquivo): string
    {
        $nome = Str::upper($arquivo);

        if (str_contains($nome, 'AVANTI')) {
            return 'PRODUTOS_PDF_AVANTI';
        }

        if (str_contains($nome, 'QUAKER')) {
            return 'PRODUTOS_PDF_QUAKER';
        }

        return 'PRODUTOS_PDF_SIERRA';
    }

    private function resolveUsuario(): Usuario
    {
        $usuario = Usuario::query()->first();
        if ($usuario) {
            return $usuario;
        }

        return Usuario::query()->create([
            'nome' => 'Batch PDF',
            'email' => 'batch.import.pdf@example.com',
            'senha' => 'batch',
            'ativo' => 1,
        ]);
    }

    private function sanitizeReportName(string $arquivo): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $arquivo) ?: 'arquivo';
    }

    private function numeroExternoBatch(string $numeroExterno): string
    {
        $numeroExterno = trim($numeroExterno);
        if ($numeroExterno === '') {
            $numeroExterno = 'IMP-PDF';
        }

        return $numeroExterno . '-BATCH-' . now()->format('YmdHis');
    }

    private function normalizarItensParaConfirmacao(array $itens): array
    {
        return collect($itens)->map(function (array $item, int $index) {
            $quantidade = $this->toDecimal($item['quantidade'] ?? 0);
            if ($quantidade <= 0) {
                $quantidade = 1;
            }

            $precoUnitario = $this->toDecimal($item['preco_unitario'] ?? ($item['preco'] ?? null));
            $valorTotalLinha = $this->toDecimal($item['valor_total_linha'] ?? ($item['valor_total'] ?? null));

            $valor = $this->toDecimal($item['valor'] ?? null);
            if ($valor <= 0 && $precoUnitario > 0) {
                $valor = $precoUnitario;
            } elseif ($valor <= 0 && $valorTotalLinha > 0 && $quantidade > 0) {
                $valor = $valorTotalLinha / $quantidade;
            }

            $ref = trim((string) ($item['ref'] ?? ($item['codigo'] ?? '')));
            $nome = trim((string) ($item['nome'] ?? ($item['descricao'] ?? '')));
            if ($nome === '') {
                $nome = $ref !== '' ? $ref : 'Item importado #' . ($index + 1);
            }

            $idCategoria = $item['id_categoria'] ?? null;
            if (empty($idCategoria) && $ref !== '') {
                $idCategoria = $this->categoriaPorReferencia($ref);
            }
            if (!$this->categoriaExiste($idCategoria)) {
                $idCategoria = $this->categoriaPadraoImportacao();
            }

            $item['ref'] = $ref !== '' ? $ref : null;
            $item['nome'] = $nome;
            $item['quantidade'] = $quantidade;
            $item['preco_unitario'] = $precoUnitario > 0 ? $precoUnitario : $valor;
            $item['valor'] = $valor;
            $item['custo_unitario'] = $this->toDecimal($item['custo_unitario'] ?? null);
            if (($item['custo_unitario'] ?? 0) <= 0) {
                $item['custo_unitario'] = $item['preco_unitario'];
            }
            $item['id_categoria'] = (int) $idCategoria;

            return $item;
        })->toArray();
    }

    private function categoriaPorReferencia(string $referencia): ?int
    {
        if (array_key_exists($referencia, $this->cacheCategoriaPorRef)) {
            return $this->cacheCategoriaPorRef[$referencia];
        }

        $variacao = ProdutoVariacao::query()
            ->with('produto:id,id_categoria')
            ->where('referencia', $referencia)
            ->first();

        $idCategoria = $variacao?->produto?->id_categoria ? (int) $variacao->produto->id_categoria : null;
        $this->cacheCategoriaPorRef[$referencia] = $idCategoria;

        return $idCategoria;
    }

    private function categoriaPadraoImportacao(): int
    {
        if ($this->categoriaPadraoId && $this->categoriaExiste($this->categoriaPadraoId)) {
            return $this->categoriaPadraoId;
        }

        $categoria = Categoria::query()->firstOrCreate(
            ['nome' => 'Importacao PDF - Sem categoria']
        );

        $this->categoriaPadraoId = (int) $categoria->id;

        return $this->categoriaPadraoId;
    }

    private function categoriaExiste(mixed $idCategoria): bool
    {
        if (empty($idCategoria)) {
            return false;
        }

        return Categoria::query()->whereKey((int) $idCategoria)->exists();
    }

    private function toDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = preg_replace('/[^\\d,\\.\\-]/', '', trim((string) $value));
        if ($s === null || $s === '' || $s === '-' || $s === '.' || $s === ',') {
            return 0.0;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
