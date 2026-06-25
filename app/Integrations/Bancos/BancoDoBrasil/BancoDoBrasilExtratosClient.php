<?php

namespace App\Integrations\Bancos\BancoDoBrasil;

use App\Integrations\Bancos\Contracts\BankStatementProvider;
use App\Integrations\Bancos\Exceptions\BancoDoBrasilIntegrationException;
use App\Models\ContaFinanceira;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BancoDoBrasilExtratosClient implements BankStatementProvider
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly array $config
    ) {}

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function isConfigured(): bool
    {
        foreach (['client_id', 'client_secret', 'app_key', 'oauth_url', 'base_url'] as $key) {
            if (!is_string($this->config[$key] ?? null) || trim((string) $this->config[$key]) === '') {
                return false;
            }
        }

        return $this->isEnabled();
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchStatement(ContaFinanceira $conta, CarbonInterface $start, CarbonInterface $end): array
    {
        $this->assertConfigured();

        $token = $this->accessToken();
        $path = $this->statementPath($conta);
        $appKeyParam = (string) ($this->config['app_key_param'] ?? 'gw-dev-app-key');

        $query = [
            $appKeyParam => (string) $this->config['app_key'],
            'dataInicio' => $start->toDateString(),
            'dataFim' => $end->toDateString(),
        ];

        try {
            $response = Http::baseUrl((string) $this->config['base_url'])
                ->acceptJson()
                ->withToken($token)
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                ->retry(
                    max(0, (int) ($this->config['retry_times'] ?? 1) - 1),
                    max(0, (int) ($this->config['retry_sleep_ms'] ?? 0)),
                    null,
                    false
                )
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new BancoDoBrasilIntegrationException(
                'Falha de conexao com a API de Extratos do Banco do Brasil.',
                'bb_extratos_transport_error',
                [],
                $e
            );
        }

        if ($response->failed()) {
            throw new BancoDoBrasilIntegrationException(
                $this->formatHttpError('Extratos', $response->status(), $response->json(), $response->body()),
                'bb_extratos_http_error',
                ['status' => $response->status()]
            );
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new BancoDoBrasilIntegrationException(
                'Resposta invalida da API de Extratos do Banco do Brasil.',
                'bb_extratos_invalid_response'
            );
        }

        return $json;
    }

    public function testConnection(ContaFinanceira $conta): bool
    {
        $today = now();
        $this->fetchStatement($conta, $today->copy()->subDay(), $today);

        return true;
    }

    private function assertConfigured(): void
    {
        if (!$this->isEnabled()) {
            throw new BancoDoBrasilIntegrationException(
                'Integracao BB Extratos desabilitada.',
                'bb_extratos_disabled'
            );
        }

        if (!$this->isConfigured()) {
            throw new BancoDoBrasilIntegrationException(
                'Credenciais BB Extratos incompletas.',
                'bb_extratos_config_incomplete'
            );
        }
    }

    private function accessToken(): string
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth((string) $this->config['client_id'], (string) $this->config['client_secret'])
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                ->post((string) $this->config['oauth_url'], array_filter([
                    'grant_type' => 'client_credentials',
                    'scope' => $this->config['scope'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''));
        } catch (ConnectionException $e) {
            throw new BancoDoBrasilIntegrationException(
                'Falha de conexao ao autenticar no Banco do Brasil.',
                'bb_extratos_oauth_transport_error',
                [],
                $e
            );
        }

        if ($response->failed()) {
            throw new BancoDoBrasilIntegrationException(
                $this->formatHttpError('OAuth', $response->status(), $response->json(), $response->body()),
                'bb_extratos_oauth_error',
                ['status' => $response->status()]
            );
        }

        $token = $response->json('access_token');
        if (!is_string($token) || trim($token) === '') {
            throw new BancoDoBrasilIntegrationException(
                'OAuth BB nao retornou access_token.',
                'bb_extratos_oauth_without_token'
            );
        }

        return $token;
    }

    private function statementPath(ContaFinanceira $conta): string
    {
        $path = (string) ($this->config['statement_path'] ?? '');
        if ($path === '') {
            throw new BancoDoBrasilIntegrationException(
                'Caminho da API de Extratos BB nao configurado.',
                'bb_extratos_path_missing'
            );
        }

        $replacements = [
            '{agencia}' => rawurlencode((string) $conta->agencia),
            '{conta}' => rawurlencode((string) $conta->conta),
            '{conta_dv}' => rawurlencode((string) $conta->conta_dv),
        ];

        return '/' . ltrim(strtr($path, $replacements), '/');
    }

    private function formatHttpError(string $operation, int $status, mixed $json, ?string $body): string
    {
        $parts = ["BB {$operation} HTTP {$status}"];

        if (is_array($json)) {
            foreach (['error', 'error_description', 'message', 'mensagem', 'codigo', 'descricao'] as $key) {
                $value = $json[$key] ?? null;
                if (is_scalar($value) && $value !== '') {
                    $parts[] = "{$key}=" . (string) $value;
                }
            }
        }

        if (count($parts) === 1 && is_string($body) && trim($body) !== '') {
            $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
            $parts[] = mb_substr($body, 0, 220);
        }

        $message = implode(' - ', array_values(array_unique($parts)));
        $message = preg_replace('/(client_secret|client_id|app_key|access_token|authorization|bearer)\s*[=:]\s*[^,\s]+/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('/[A-Za-z0-9_\-]{28,}/', '[redacted]', $message) ?? $message;

        return $message;
    }
}
