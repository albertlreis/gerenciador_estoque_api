<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExtratorPedidoPythonService
{
    /**
     * Envia o arquivo para a API Python e retorna os dados do pedido.
     * Mantém compatibilidade: se tipo não vier, assume Sierra.
     *
     * @param UploadedFile $arquivo
     * @param string|null $tipoImportacao
     * @return array
     * @throws Exception
     */
    public function processar(UploadedFile $arquivo, ?string $tipoImportacao = null): array
    {
        $url = config('services.extrator_pedido.url');
        $tipo = strtoupper((string) ($tipoImportacao ?: 'PRODUTOS_PDF_SIERRA'));

        if (!$url) {
            throw new Exception('URL da API Python não configurada. Defina SERVICES_EXTRATOR_PEDIDO_URL no .env');
        }

        try {
            $inicio = microtime(true);

            $response = Http::retry(2, 500)
                ->timeout(120)
                ->attach('arquivo', file_get_contents($arquivo->getRealPath()), $arquivo->getClientOriginalName())
                ->post($url, [
                    'tipo_importacao' => $tipo,
                ]);

            if (!$response->successful()) {
                Log::error('Erro API Python', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'tipo_importacao' => $tipo,
                ]);

                throw new Exception('Erro ao processar arquivo na API Python');
            }

            $json = $response->json();

            if (!isset($json['sucesso']) || !$json['sucesso']) {
                throw new Exception('API Python retornou erro: ' . json_encode($json));
            }

            Log::info('Importação Python - extração concluída', [
                'tipo_importacao' => $tipo,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
                'arquivo_tamanho' => $arquivo->getSize(),
                'tempo_ms' => (int) ((microtime(true) - $inicio) * 1000),
            ]);

            return $json['dados'] ?? [];
        } catch (Exception $e) {
            Log::error('Falha ao extrair pedido do arquivo', [
                'erro' => $e->getMessage(),
                'file' => $arquivo->getClientOriginalName(),
                'tipo_importacao' => $tipo,
            ]);
            throw $e;
        }
    }
}
