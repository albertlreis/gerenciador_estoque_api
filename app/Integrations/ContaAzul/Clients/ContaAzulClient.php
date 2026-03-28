<?php

namespace App\Integrations\ContaAzul\Clients;

use App\Integrations\ContaAzul\Exceptions\ContaAzulHttpException;
use App\Integrations\ContaAzul\Support\StructuredLog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class ContaAzulClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
        ?Client $http = null
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => $this->config['base_url'] . '/',
            'timeout' => $this->config['timeout'] ?? 30,
            'connect_timeout' => $this->config['connect_timeout'] ?? 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $query
     * @return array{status:int, body:?string, json:mixed, headers:array<string, array<string>>}
     */
    public function get(string $uri, string $bearerToken, array $query = []): array
    {
        return $this->request('GET', $uri, $bearerToken, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    public function post(string $uri, string $bearerToken, ?array $json = null): array
    {
        $options = [];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->request('POST', $uri, $bearerToken, $options);
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    public function put(string $uri, string $bearerToken, ?array $json = null): array
    {
        $options = [];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->request('PUT', $uri, $bearerToken, $options);
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    public function patch(string $uri, string $bearerToken, ?array $json = null): array
    {
        $options = [];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->request('PATCH', $uri, $bearerToken, $options);
    }

    public function delete(string $uri, string $bearerToken, array $query = []): array
    {
        return $this->request('DELETE', $uri, $bearerToken, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status:int, body:?string, json:mixed, headers:array<string, array<string>>}
     */
    public function request(string $method, string $uri, string $bearerToken, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);
        $options['headers'] = array_merge(
            ['Authorization' => 'Bearer ' . $bearerToken],
            is_array($headers) ? $headers : []
        );

        $attempts = max(1, (int) ($this->config['retry']['times'] ?? 1));
        $sleepMs = max(0, (int) ($this->config['retry']['sleep_ms'] ?? 0));

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $response = $this->http->request($method, ltrim($uri, '/'), $options);

                return $this->normalizeResponse($method, $uri, $response);
            } catch (RequestException $e) {
                if ($i < $attempts - 1 && $this->shouldRetry($e)) {
                    usleep($sleepMs * 1000 * ($i + 1));
                    continue;
                }
                StructuredLog::integration('conta_azul.http.request_failed', [
                    'method' => $method,
                    'uri' => $uri,
                    'message' => $e->getMessage(),
                ], 'error');
                throw new ContaAzulHttpException(
                    'Falha de transporte na API Conta Azul: ' . $e->getMessage(),
                    0,
                    null,
                    $e
                );
            } catch (GuzzleException $e) {
                StructuredLog::integration('conta_azul.http.guzzle_failed', [
                    'method' => $method,
                    'uri' => $uri,
                    'message' => $e->getMessage(),
                ], 'error');
                throw new ContaAzulHttpException(
                    'Erro HTTP Conta Azul: ' . $e->getMessage(),
                    0,
                    null,
                    $e
                );
            }
        }

        throw new \LogicException('ContaAzulClient::request encerrou sem retorno.');
    }

    private function shouldRetry(RequestException $e): bool
    {
        $response = $e->getResponse();
        if ($response === null) {
            return true;
        }
        $code = $response->getStatusCode();

        return $code === 429 || $code >= 500;
    }

    /**
     * @return array{status:int, body:?string, json:mixed, headers:array<string, array<string>>}
     */
    private function normalizeResponse(string $method, string $uri, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $json = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            $json = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        $headers = $response->getHeaders();
        if ($status === 401 || $status === 403) {
            StructuredLog::integration('conta_azul.http.client_error', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
            ], 'warning');
        } elseif ($status === 404) {
            StructuredLog::integration('conta_azul.http.not_found', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
            ], 'notice');
        } elseif ($status === 409) {
            StructuredLog::integration('conta_azul.http.conflict', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
            ], 'warning');
        } elseif ($status === 422) {
            StructuredLog::integration('conta_azul.http.unprocessable', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
            ], 'warning');
        } elseif ($status === 429) {
            StructuredLog::integration('conta_azul.http.rate_limited', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
                'retry_after' => $response->getHeaderLine('Retry-After'),
            ], 'warning');
        } elseif ($status >= 500) {
            StructuredLog::integration('conta_azul.http.server_error', [
                'method' => $method,
                'uri' => $uri,
                'status' => $status,
            ], 'error');
        }

        return [
            'status' => $status,
            'body' => $body !== '' ? $body : null,
            'json' => $json,
            'headers' => $headers,
        ];
    }
}
