<?php

namespace App\Integrations\GoogleCalendar\Clients;

use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarHttpException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class GoogleCalendarClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
        ?Client $http = null
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim((string) ($this->config['base_url'] ?? 'https://www.googleapis.com/calendar/v3'), '/') . '/',
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
     * @param array<string, scalar|null> $query
     * @return array{status:int, body:?string, json:mixed, headers:array<string, array<string>>}
     */
    public function get(string $uri, string $bearerToken, array $query = []): array
    {
        return $this->request('GET', $uri, $bearerToken, ['query' => $query]);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public function post(string $uri, string $bearerToken, ?array $json = null, array $query = []): array
    {
        return $this->request('POST', $uri, $bearerToken, array_filter([
            'json' => $json,
            'query' => $query,
        ], fn ($value) => $value !== null && $value !== []));
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public function patch(string $uri, string $bearerToken, ?array $json = null, array $query = []): array
    {
        return $this->request('PATCH', $uri, $bearerToken, array_filter([
            'json' => $json,
            'query' => $query,
        ], fn ($value) => $value !== null && $value !== []));
    }

    public function delete(string $uri, string $bearerToken, array $query = []): array
    {
        return $this->request('DELETE', $uri, $bearerToken, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $options
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

                return $this->normalizeResponse($response);
            } catch (RequestException $e) {
                if ($i < $attempts - 1 && $this->shouldRetry($e)) {
                    usleep($sleepMs * 1000 * ($i + 1));
                    continue;
                }

                throw new GoogleCalendarHttpException(
                    'Falha de transporte na API Google Calendar: ' . $e->getMessage(),
                    $e->getResponse()?->getStatusCode() ?? 0,
                    null,
                    $e
                );
            } catch (GuzzleException $e) {
                throw new GoogleCalendarHttpException(
                    'Erro HTTP Google Calendar: ' . $e->getMessage(),
                    0,
                    null,
                    $e
                );
            }
        }

        throw new \LogicException('GoogleCalendarClient::request encerrou sem retorno.');
    }

    private function shouldRetry(RequestException $e): bool
    {
        $response = $e->getResponse();
        if (!$response) {
            return true;
        }

        $code = $response->getStatusCode();
        return $code === 429 || $code >= 500;
    }

    /**
     * @return array{status:int, body:?string, json:mixed, headers:array<string, array<string>>}
     */
    private function normalizeResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $json = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            $json = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => $body !== '' ? $body : null,
            'json' => $json,
            'headers' => $response->getHeaders(),
        ];
    }
}
