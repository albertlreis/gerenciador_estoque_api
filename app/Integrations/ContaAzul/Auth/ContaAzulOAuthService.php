<?php

namespace App\Integrations\ContaAzul\Auth;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Support\StructuredLog;
use GuzzleHttp\Client;

/**
 * OAuth2 Conta Azul (Authorization Code + refresh). Token endpoint usa Basic Auth (client_id:client_secret).
 */
class ContaAzulOAuthService
{
    private Client $http;

    public function __construct(
        private readonly array $config,
        ?Client $http = null
    ) {
        $this->http = $http ?? new Client([
            'timeout' => $this->config['timeout'] ?? 30,
            'connect_timeout' => $this->config['connect_timeout'] ?? 10,
            'http_errors' => false,
        ]);
    }

    /**
     * @param  array<string, string|int|bool>  $extraQuery
     */
    public function buildAuthorizationUrl(string $state, array $extraQuery = []): string
    {
        $this->validateRequiredConfig();

        $authBase = rtrim((string) ($this->config['auth_url'] ?? ''), '/');
        $authorizePath = '/' . ltrim((string) ($this->config['authorize_path'] ?? '/login'), '/');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $redirect = (string) ($this->config['redirect_uri'] ?? '');
        $scope = (string) ($this->config['scope'] ?? '');

        $query = array_merge([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => $scope,
        ], $extraQuery);

        return $authBase . $authorizePath . '?' . http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) ($this->config['redirect_uri'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->postToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function postToken(array $form): array
    {
        $this->validateRequiredConfig();

        $authBase = rtrim((string) ($this->config['auth_url'] ?? ''), '/');
        $tokenPath = '/' . ltrim((string) ($this->config['token_path'] ?? '/oauth2/token'), '/');
        $uri = $authBase . $tokenPath;

        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');
        $basic = base64_encode($clientId . ':' . $clientSecret);

        $form['client_id'] = $clientId;
        $form['client_secret'] = $clientSecret;

        $response = $this->http->post($uri, [
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'form_params' => $form,
        ]);

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $status = $response->getStatusCode();

        if (!is_array($json)) {
            StructuredLog::integration('conta_azul.oauth.invalid_response', [
                'status' => $status,
                'body' => $body !== '' ? $body : null,
            ], 'error');

            throw new ContaAzulException(
                'Resposta OAuth inválida da Conta Azul.',
                'oauth_resposta_invalida',
                ['status' => $status]
            );
        }

        if ($status < 200 || $status >= 300) {
            $reason = $this->mapOAuthErrorReason($json['error'] ?? null);
            StructuredLog::integration('conta_azul.oauth.token_failed', [
                'status' => $status,
                'error' => $json['error'] ?? null,
                'error_description' => $json['error_description'] ?? null,
            ], 'warning');

            throw new ContaAzulException(
                (string) ($json['error_description'] ?? $json['message'] ?? 'Falha na autenticação OAuth da Conta Azul.'),
                $reason,
                [
                    'status' => $status,
                    'error' => $json['error'] ?? null,
                    'error_description' => $json['error_description'] ?? null,
                ]
            );
        }

        if (!isset($json['access_token']) || !is_string($json['access_token']) || $json['access_token'] === '') {
            StructuredLog::integration('conta_azul.oauth.missing_access_token', [
                'status' => $status,
                'keys' => array_keys($json),
            ], 'error');

            throw new ContaAzulException(
                'Resposta OAuth sem access_token.',
                'token_sem_access_token',
                ['status' => $status]
            );
        }

        return $json;
    }

    private function validateRequiredConfig(): void
    {
        $missing = [];

        foreach ([
            'client_id' => 'CONTA_AZUL_CLIENT_ID',
            'client_secret' => 'CONTA_AZUL_CLIENT_SECRET',
            'redirect_uri' => 'CONTA_AZUL_REDIRECT_URI',
        ] as $key => $envName) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                $missing[] = $envName;
            }
        }

        if ($missing === []) {
            return;
        }

        StructuredLog::integration('conta_azul.oauth.invalid_config', [
            'missing' => $missing,
        ], 'error');

        throw new ContaAzulException(
            'Configuração OAuth da Conta Azul incompleta.',
            'config_invalida',
            ['missing' => $missing]
        );
    }

    private function mapOAuthErrorReason(mixed $error): string
    {
        return match ((string) $error) {
            'invalid_grant' => 'invalid_grant',
            'invalid_client' => 'config_invalida',
            default => 'oauth_token_error',
        };
    }

    public function makeApiClient(): ContaAzulClient
    {
        return new ContaAzulClient($this->config);
    }
}
