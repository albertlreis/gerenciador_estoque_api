<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExtratorPedidoPythonService
{
    /**
     * Envia o arquivo para a API Python e retorna os dados do pedido.
     * Mantem compatibilidade: se tipo nao vier, assume Sierra.
     *
     * @param UploadedFile $arquivo
     * @param string|null $tipoImportacao
     * @param string|null $requestId
     * @return array
     * @throws Exception
     */
    public function processar(UploadedFile $arquivo, ?string $tipoImportacao = null, ?string $requestId = null): array
    {
        $url = config('services.extrator_pedido.url');
        $url = $this->resolverUrlLocal($url);
        $tipo = strtoupper((string) ($tipoImportacao ?: 'PRODUTOS_PDF_SIERRA'));
        $requestId = $requestId ?: (string) Str::uuid();
        $fileField = (string) config('services.extrator_pedido.file_field', 'pdf');
        $timeout = (int) config('services.extrator_pedido.timeout', 120);
        $retryTimes = (int) config('services.extrator_pedido.retry_times', 2);
        $retrySleepMs = (int) config('services.extrator_pedido.retry_sleep_ms', 500);

        if (!$url) {
            throw new Exception('URL da API Python nao configurada. Defina SERVICES_EXTRATOR_PEDIDO_URL no .env');
        }

        try {
            $inicio = microtime(true);

            Log::info('importacao_pdf_python_request', [
                'request_id' => $requestId,
                'etapa' => 'parse',
                'url' => $url,
                'tipo_importacao' => $tipo,
                'file_field' => $fileField,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
                'arquivo_tamanho' => $arquivo->getSize(),
                'timeout_s' => $timeout,
            ]);

            $response = $this->enviarRequisicao(
                $url,
                $arquivo,
                $tipo,
                $fileField,
                $timeout,
                $retryTimes,
                $retrySleepMs
            );

            if (!$response->successful()) {
                $fallbackField = $this->campoFallback($fileField, (string) $response->body());

                if ($fallbackField) {
                    Log::warning('importacao_pdf_python_retry_file_field', [
                        'request_id' => $requestId,
                        'etapa' => 'parse',
                        'url' => $url,
                        'status' => $response->status(),
                        'file_field_atual' => $fileField,
                        'file_field_fallback' => $fallbackField,
                    ]);

                    $response = $this->enviarRequisicao(
                        $url,
                        $arquivo,
                        $tipo,
                        $fallbackField,
                        $timeout,
                        $retryTimes,
                        $retrySleepMs
                    );
                }
            }

            if (!$response->successful()) {
                Log::error('importacao_pdf_python_http_error', [
                    'request_id' => $requestId,
                    'etapa' => 'parse',
                    'url' => $url,
                    'status' => $response->status(),
                    'body_preview' => mb_substr((string) $response->body(), 0, 1200),
                    'tipo_importacao' => $tipo,
                    'file_field' => $fileField,
                ]);

                throw new Exception('Erro ao processar arquivo na API Python');
            }

            $json = $response->json();

            if (!isset($json['sucesso']) || !$json['sucesso']) {
                throw new Exception('API Python retornou erro: ' . json_encode($json));
            }

            Log::info('importacao_pdf_python_success', [
                'request_id' => $requestId,
                'etapa' => 'parse',
                'url' => $url,
                'tipo_importacao' => $tipo,
                'file_field' => $fileField,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
                'arquivo_tamanho' => $arquivo->getSize(),
                'tempo_ms' => (int) ((microtime(true) - $inicio) * 1000),
            ]);

            return $json['dados'] ?? [];
        } catch (Exception $e) {
            Log::error('importacao_pdf_python_exception', [
                'request_id' => $requestId,
                'etapa' => 'parse',
                'url' => $url,
                'erro' => $e->getMessage(),
                'file' => $arquivo->getClientOriginalName(),
                'tipo_importacao' => $tipo,
                'file_field' => $fileField,
            ]);
            throw $e;
        }
    }

    private function enviarRequisicao(
        string $url,
        UploadedFile $arquivo,
        string $tipo,
        string $fileField,
        int $timeout,
        int $retryTimes,
        int $retrySleepMs
    ) {
        return Http::retry($retryTimes, $retrySleepMs)
            ->timeout($timeout)
            ->attach($fileField, file_get_contents($arquivo->getRealPath()), $arquivo->getClientOriginalName())
            ->post($url, [
                'tipo_importacao' => $tipo,
            ]);
    }

    private function campoFallback(string $fileFieldAtual, string $responseBody): ?string
    {
        if ($fileFieldAtual === 'pdf' && str_contains(mb_strtolower($responseBody), '"arquivo"')) {
            return 'arquivo';
        }

        if ($fileFieldAtual === 'arquivo' && str_contains(mb_strtolower($responseBody), '"pdf"')) {
            return 'pdf';
        }

        return null;
    }

    private function resolverUrlLocal(?string $url): ?string
    {
        if (!$url) {
            return $url;
        }

        $forcarLocal = app()->environment(['local', 'testing'])
            && (bool) config('services.extrator_pedido.force_local_url', true);

        if (!$forcarLocal) {
            return $url;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $url;
        }

        $localUrl = 'http://127.0.0.1:8010/extrair-pedido';
        Log::warning('importacao_pdf_python_force_local_url', [
            'url_configurada' => $url,
            'url_utilizada' => $localUrl,
        ]);

        return $localUrl;
    }
}
