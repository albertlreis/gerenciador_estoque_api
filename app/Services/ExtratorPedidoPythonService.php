<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExtratorPedidoPythonService
{
    /**
     * Envia o PDF para a API Python e retorna os dados do pedido.
     *
     * @param UploadedFile $pdf
     * @return array
     * @throws Exception
     */
    public function processar(UploadedFile $pdf): array
    {
        $url = 'http://167.99.51.172:8010/extrair-pedido';
//        $url = 'http://localhost:8003/extrair-pedido';

        if (!$url) {
            throw new Exception("URL da API Python não configurada. Defina SERVICES_EXTRATOR_PEDIDO_URL no .env");
        }

        try {
            $response = Http::timeout(120)
                ->attach("pdf", file_get_contents($pdf->getRealPath()), $pdf->getClientOriginalName())
                ->post($url);

            if (!$response->successful()) {
                Log::error("Erro API Python", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                throw new Exception("Erro ao processar PDF na API Python");
            }

            $json = $response->json();

            if (!isset($json['sucesso']) || !$json['sucesso']) {
                throw new Exception("API Python retornou erro: " . json_encode($json));
            }

            return $json['dados'] ?? [];

        } catch (Exception $e) {
            Log::error("Falha ao extrair pedido do PDF", [
                "erro" => $e->getMessage(),
                "file" => $pdf->getClientOriginalName()
            ]);
            throw $e;
        }
    }
}
