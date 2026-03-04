<?php

namespace App\Services\Comunicacao;

use App\Models\ContaReceber;
use App\Models\Pedido;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     *   store_only?: bool
     * } $payload
     */
    public function enviar(array $payload): void
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
        ];

        if ($channel === 'email') {
            $message['to_email'] = strtolower($destination);
        } else {
            $message['to_phone'] = preg_replace('/\D+/', '', $destination) ?? $destination;
        }

        $body = [
            'source' => 'sierra',
            'external_id' => null,
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
            Log::warning('[ComunicacaoApiClient] Falha ao criar request', [
                'status' => $resp->status(),
                'url' => $url,
                'channel' => $channel,
                'destination' => $destination,
                'correlation_id' => $correlationId,
                'response' => $resp->json(),
            ]);
        }
    }

    public function enviarStatusPedido(Pedido $pedido, string $status): void
    {
        $cliente = $pedido->cliente;
        if (!$cliente || !$cliente->email) {
            return;
        }

        $template = (string) config('comunicacao.templates.pedido_status_email');
        if ($template === '') {
            return;
        }

        $this->enviar([
            'canal' => 'email',
            'para' => (string) $cliente->email,
            'template_code' => $template,
            'variaveis' => [
                'pedido' => [
                    'id' => $pedido->id,
                    'numero' => $pedido->numero_externo ?? $pedido->id,
                    'status' => $status,
                ],
                'cliente' => [
                    'nome' => $cliente->nome ?? '',
                ],
            ],
            'correlation_id' => "pedido:{$pedido->id}",
        ]);
    }

    public function enviarCobranca(ContaReceber $conta): void
    {
        $pedido = $conta->pedido;
        $cliente = $pedido?->cliente;
        if (!$cliente) {
            return;
        }

        $phone = (string) ($cliente->whatsapp ?? $cliente->telefone ?? '');
        if ($phone === '') {
            return;
        }

        $templateSms = (string) config('comunicacao.templates.cobranca_sms');
        $templateWpp = (string) config('comunicacao.templates.cobranca_whatsapp');

        $variaveis = [
            'cliente' => [
                'nome' => $cliente->nome ?? '',
            ],
            'conta' => [
                'id' => $conta->id,
                'descricao' => $conta->descricao,
                'numero_documento' => $conta->numero_documento,
                'data_vencimento' => $conta->data_vencimento,
                'valor' => $conta->valor_liquido,
            ],
            'pedido' => $pedido ? [
                'id' => $pedido->id,
                'numero' => $pedido->numero_externo ?? $pedido->id,
            ] : null,
        ];

        if ($templateSms !== '') {
            $this->enviar([
                'canal' => 'sms',
                'para' => $phone,
                'template_code' => $templateSms,
                'variaveis' => $variaveis,
                'correlation_id' => "cobranca:{$conta->id}",
            ]);
        }

        if ($templateWpp !== '') {
            $this->enviar([
                'canal' => 'whatsapp',
                'para' => $phone,
                'template_code' => $templateWpp,
                'variaveis' => $variaveis,
                'correlation_id' => "cobranca:{$conta->id}",
            ]);
        }
    }

    private function requestsUrl(string $base): string
    {
        if (str_ends_with($base, '/api')) {
            return $base . '/requests';
        }

        return $base . '/api/requests';
    }
}

