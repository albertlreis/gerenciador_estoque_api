<?php

namespace App\Services\Comunicacao;

use App\Support\Logging\SierraLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ComunicacaoApiClient
{
    /**
     * Envia um pedido de comunicação genérico para a api-comunicacao.
     *
     * @param array{
     *   canal: 'email'|'sms'|'whatsapp',
     *   para: string,
     *   template_code: string,
     *   variaveis?: array<string,mixed>,
     *   correlation_id?: string,
     *   store_only?: bool,
     *   external_id?: string,
     *   client_reference?: string
     * } $payload
     */
    public function enviar(array $payload): array
    {
        $base = rtrim((string) config('services.comms.base_url'), '/');
        $apiKey = (string) config('services.comms.api_key');
        $apiSecret = (string) config('services.comms.api_secret');

        if ($base === '' || $apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Config services.comms incompleta (COMMS_BASE_URL/COMMS_API_KEY/COMMS_API_SECRET).');
        }

        $channel = strtolower((string) ($payload['canal'] ?? 'email'));
        $destination = trim((string) ($payload['para'] ?? ''));
        $templateCode = trim((string) ($payload['template_code'] ?? ''));
        $correlationId = trim((string) ($payload['correlation_id'] ?? Str::uuid()->toString()));

        if ($destination === '' || $templateCode === '') {
            throw new RuntimeException('Destino/template_code obrigatórios para comunicação.');
        }

        $variables = (array) ($payload['variaveis'] ?? []);
        $storeOnly = array_key_exists('store_only', $payload)
            ? (bool) $payload['store_only']
            : true;

        $message = [
            'channel' => $channel,
            'template_code' => $templateCode,
            'variables' => $variables,
            'client_reference' => trim((string) ($payload['client_reference'] ?? '')) ?: null,
        ];

        if ($channel === 'email') {
            $message['to_email'] = strtolower($destination);
        } else {
            $message['to_phone'] = preg_replace('/\D+/', '', $destination) ?? $destination;
        }

        $body = [
            'source' => 'sierra',
            'external_id' => trim((string) ($payload['external_id'] ?? '')) ?: null,
            'store_only' => $storeOnly,
            'correlation_id' => $correlationId,
            'meta' => [
                'origin' => 'gerenciador_estoque_api',
                'channel' => $channel,
                'correlation_id' => $correlationId,
            ],
            'payload' => [
                'messages' => [$message],
            ],
        ];

        $url = $this->requestsUrl($base);

        $resp = Http::timeout(10)
            ->acceptJson()
            ->withHeaders([
                'X-API-KEY' => $apiKey,
                'X-API-SECRET' => $apiSecret,
                'X-Correlation-Id' => $correlationId,
            ])
            ->post($url, $body);

        if (!$resp->successful()) {
            SierraLog::warning('communication.request.create_failed', [
                'status' => $resp->status(),
                'url' => $url,
                'channel' => $channel,
                'correlation_id' => $correlationId,
            ]);

            throw new RuntimeException('Serviço de comunicação recusou a solicitação com HTTP '.$resp->status().'.');
        }

        return (array) ($resp->json() ?? []);
    }

    private function requestsUrl(string $base): string
    {
        if (str_ends_with($base, '/api')) {
            return $base . '/requests';
        }

        return $base . '/api/requests';
    }
}
