<?php

namespace App\Integrations\ContaAzul\Auth;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
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
        $authBase = rtrim((string) ($this->config['auth_url'] ?? ''), '/');
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

        return $authBase . '/oauth2/authorize?' . http_build_query($query);
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
        $authBase = rtrim((string) ($this->config['auth_url'] ?? ''), '/');
        $uri = $authBase . '/oauth2/token';

        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');
        $basic = base64_encode($clientId . ':' . $clientSecret);

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

        if (!is_array($json)) {
            throw new ContaAzulException('Resposta OAuth inválida da Conta Azul.');
        }

        return $json;
    }

    public function makeApiClient(): ContaAzulClient
    {
        return new ContaAzulClient($this->config);
    }
}
