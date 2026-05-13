<?php

namespace App\Integrations\GoogleCalendar\Auth;

use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use GuzzleHttp\Client;

class GoogleCalendarOAuthService
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

    public function buildAuthorizationUrl(string $state): string
    {
        $clientId = (string) ($this->config['client_id'] ?? '');
        $redirectUri = (string) ($this->config['redirect_uri'] ?? '');

        if ($clientId === '' || $redirectUri === '') {
            throw new GoogleCalendarException(
                'A integracao Google Agenda nao esta configurada corretamente.',
                'config_invalida'
            );
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => (string) ($this->config['scope'] ?? ''),
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim((string) ($this->config['auth_url'] ?? 'https://accounts.google.com'), '/')
            . '/o/oauth2/v2/auth?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->tokenRequest([
            'code' => $code,
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'client_secret' => (string) ($this->config['client_secret'] ?? ''),
            'redirect_uri' => (string) ($this->config['redirect_uri'] ?? ''),
            'grant_type' => 'authorization_code',
        ], 'oauth_token_error');
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->tokenRequest([
            'client_id' => (string) ($this->config['client_id'] ?? ''),
            'client_secret' => (string) ($this->config['client_secret'] ?? ''),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], 'refresh_token_falhou');
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function tokenRequest(array $form, string $reason): array
    {
        try {
            $response = $this->http->post((string) ($this->config['token_url'] ?? 'https://oauth2.googleapis.com/token'), [
                'form_params' => $form,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            throw new GoogleCalendarException(
                'Falha ao conversar com o OAuth do Google: ' . $e->getMessage(),
                $reason,
                [],
                $e
            );
        }

        $body = (string) $response->getBody();
        $json = $body !== '' ? json_decode($body, true) : null;
        if (!is_array($json)) {
            throw new GoogleCalendarException('Resposta OAuth do Google em formato invalido.', 'oauth_resposta_invalida');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $message = (string) ($json['error_description'] ?? $json['error'] ?? 'OAuth Google recusou a requisicao.');
            throw new GoogleCalendarException($message, $reason, ['status' => $response->getStatusCode(), 'response' => $json]);
        }

        return $json;
    }
}
