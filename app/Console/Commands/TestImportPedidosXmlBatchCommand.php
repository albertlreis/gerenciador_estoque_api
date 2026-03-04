<?php

namespace App\Console\Commands;

use App\Http\Controllers\PedidoController;
use App\Models\PedidoImportacao;
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

class TestImportPedidosXmlBatchCommand extends Command
{
    protected $signature = 'sierra:test-import-pedidos-xml
        {--dir= : Diretório com XMLs de NFe}
        {--commit=0 : Persistir no banco (1) ou executar com rollback (0)}';

    protected $description = 'Executa teste batch de importação de pedidos via XML NFe (ADORNOS_XML_NFE), gerando relatório por arquivo.';

    public function handle(): int
    {
        $inicioBatch = microtime(true);
        $timestamp = now()->format('Ymd_His');
        $requestBatchId = (string) Str::uuid();
        $reportDir = storage_path("logs/import-xml-tests/{$timestamp}");
        File::ensureDirectoryExists($reportDir);

        $dir = (string) $this->option('dir');
        if ($dir === '') {
            $dir = storage_path('leitor_pdf_examples');
        }

        if (!$dir || !is_dir($dir)) {
            $this->error("Diretório inválido: {$dir}");
            return self::FAILURE;
        }

        $this->line("Batch ID: {$requestBatchId}");
        $this->line("Diretório: {$dir}");

        $shouldCommit = $this->toBool($this->option('commit'), false);
        $this->line('Modo commit: ' . ($shouldCommit ? 'SIM' : 'NAO (rollback)'));
        $this->line("Relatórios: {$reportDir}");

        $files = collect(glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.xml'))
            ->filter(fn(string $path) => !str_ends_with($path, '.Zone.Identifier'))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->warn('Nenhum XML encontrado no diretório informado.');
            return self::SUCCESS;
        }

        $usuario = $this->resolveUsuario();
        $controller = app(PedidoController::class);
        $rows = [];
        $results = [];

        foreach ($files as $filePath) {
            $arquivo = basename($filePath);
            $tipo = 'ADORNOS_XML_NFE';
            $requestId = (string) Str::uuid();
            $inicioArquivo = microtime(true);

            $resultado = [
                'batch_id' => $requestBatchId,
                'request_id' => $requestId,
                'arquivo' => $arquivo,
                'caminho' => $filePath,
                'tipo_importacao' => $tipo,
                'status' => 'FAIL',
                'tempo_ms' => 0,
                'resumo' => [
                    'numero_pedido' => null,
                    'data_pedido' => null,
                    'total_liquido' => null,
                    'numero_itens' => null,
                ],
                'erro' => null,
                'import_response' => null,
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

                $uploaded = $this->makeUploadedFile($filePath, 'application/xml');

                $importRequest = Request::create('/api/v1/pedidos/import', 'POST', [
                    'tipo_importacao' => $tipo,
                ], [], ['arquivo' => $uploaded]);
                $importRequest->headers->set('X-Request-Id', $requestId);
                $importRequest->setUserResolver(fn () => $usuario);

                $importResponse = $controller->importar($importRequest);
                $importPayload = json_decode((string) $importResponse->getContent(), true);
                $resultado['import_response'] = $importPayload;

                if (($importResponse->getStatusCode() ?? 500) >= 400 || !($importPayload['sucesso'] ?? false)) {
                    $mensagem = $importPayload['erro'] ?? $importPayload['mensagem'] ?? 'Falha na etapa de importação.';
                    throw new \RuntimeException($mensagem);
                }

                $dados = (array) ($importPayload['dados'] ?? []);
                $pedidoDados = (array) ($dados['pedido'] ?? []);
                $totaisDados = (array) ($dados['totais'] ?? []);
                $itensDados = (array) ($dados['itens'] ?? []);

                $resultado['resumo'] = [
                    'numero_pedido' => $pedidoDados['numero_pedido'] ?? null,
                    'data_pedido' => $pedidoDados['data_pedido'] ?? null,
                    'total_liquido' => $totaisDados['total_liquido'] ?? $totaisDados['total_bruto'] ?? null,
                    'numero_itens' => count($itensDados),
                ];

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

                Log::error('batch_importacao_xml_falha', [
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
                    (string) ($resultado['resumo']['numero_pedido'] ?? '-'),
                    (string) ($resultado['resumo']['data_pedido'] ?? '-'),
                    (string) ($resultado['resumo']['total_liquido'] ?? '-'),
                    (string) ($resultado['resumo']['numero_itens'] ?? '-'),
                    $resultado['erro']['mensagem'] ?? '-',
                ];
                $results[] = $resultado;
            }
        }

        $this->table(
            ['Arquivo', 'Status', 'Tempo(ms)', 'Numero Pedido', 'Data', 'Total Líquido', 'Itens', 'Erro'],
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

    private function makeUploadedFile(string $filePath, string $mimeType): UploadedFile
    {
        $symfonyFile = new SymfonyUploadedFile(
            $filePath,
            basename($filePath),
            $mimeType,
            null,
            true
        );

        return UploadedFile::createFromBase($symfonyFile, true);
    }

    private function resolveUsuario(): Usuario
    {
        $usuario = Usuario::query()->first();
        if ($usuario) {
            return $usuario;
        }

        return Usuario::query()->create([
            'nome' => 'Batch XML',
            'email' => 'batch.import.xml@example.com',
            'senha' => 'batch',
            'ativo' => 1,
        ]);
    }

    private function sanitizeReportName(string $arquivo): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $arquivo) ?: 'arquivo';
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
}

