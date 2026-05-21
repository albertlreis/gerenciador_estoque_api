<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Support\StructuredLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ContaAzulConnectionService
{
    public function __construct(
        private readonly array $config,
        private readonly ContaAzulOAuthService $oauth,
        private readonly ContaAzulClient $client
    ) {
    }

    public function latestForLoja(?int $lojaId = null): ?ContaAzulConexao
    {
        $q = ContaAzulConexao::query()->orderByDesc('id');
        if ($lojaId === null) {
            $q->whereNull('loja_id');
        } else {
            $q->where('loja_id', $lojaId);
        }

        return $q->first();
    }

    public function findOrCreateConexao(?int $lojaId = null): ContaAzulConexao
    {
        $existing = $this->latestForLoja($lojaId);
        if ($existing) {
            return $existing;
        }

        return ContaAzulConexao::create([
            'loja_id' => $lojaId,
            'status' => 'inativa',
            'ambiente' => 'producao',
        ]);
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     */
    public function persistTokensFromOAuth(ContaAzulConexao $conexao, array $tokenResponse): ContaAzulToken
    {
        $access = (string) ($tokenResponse['access_token'] ?? '');
        if ($access === '') {
            throw new ContaAzulException('Resposta OAuth sem access_token.', 'token_sem_access_token');
        }

        $existingToken = $conexao->token;
        $refresh = isset($tokenResponse['refresh_token']) && $tokenResponse['refresh_token'] !== ''
            ? (string) $tokenResponse['refresh_token']
            : ($existingToken?->refresh_token ? (string) $existingToken->refresh_token : null);
        $expiresIn = isset($tokenResponse['expires_in']) ? (int) $tokenResponse['expires_in'] : 3600;
        $scope = isset($tokenResponse['scope']) ? (string) $tokenResponse['scope'] : null;

        $expiresAt = CarbonImmutable::now()->addSeconds(max(60, $expiresIn));

        return DB::transaction(function () use ($conexao, $access, $refresh, $expiresAt, $scope) {
            $conexao->update([
                'status' => 'ativa',
                'ultimo_erro' => null,
            ]);

            $token = ContaAzulToken::query()->firstOrNew(['conexao_id' => $conexao->id]);
            $token->fill([
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_at' => $expiresAt,
                'scope' => $scope,
                'ultimo_refresh_em' => now(),
            ]);
            $token->save();

            return $token;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistManualTokens(?int $lojaId, array $payload): ContaAzulConexao
    {
        $conexao = $this->findOrCreateConexao($lojaId);
        $conexao->fill([
            'ambiente' => (string) ($payload['ambiente'] ?? $conexao->ambiente ?? 'producao'),
            'nome_externo' => $payload['nome_externo'] ?? null,
            'observacoes' => $payload['observacoes'] ?? null,
        ]);
        $conexao->save();

        $this->persistTokensFromOAuth($conexao, [
            'access_token' => $payload['access_token'] ?? null,
            'refresh_token' => $payload['refresh_token'] ?? null,
            'expires_in' => $payload['expires_in'] ?? 3600,
            'scope' => $payload['scope'] ?? null,
        ]);
        $conexao->load('token');

        if (!$this->healthcheck($conexao)) {
            throw new ContaAzulException(
                'O token manual foi salvo, mas o teste de conexão com a Conta Azul falhou.',
                'healthcheck_failed'
            );
        }

        return $conexao->fresh(['token']);
    }

    public function getValidAccessToken(ContaAzulConexao $conexao, bool $forceRefresh = false): string
    {
        $token = $conexao->token;
        if (!$token) {
            throw new ContaAzulException('Conexão sem tokens.', 'conexao_sem_token');
        }

        if (!$forceRefresh && !$token->isAccessTokenExpired()) {
            return (string) $token->access_token;
        }

        $refresh = $token->refresh_token;
        if (!$refresh) {
            $manualDevMessage = 'Refresh token ausente; gere um novo access token manual da Conta Azul e salve novamente.';
            $defaultMessage = 'Refresh token ausente; reconecte a Conta Azul.';
            $message = $conexao->ambiente === 'homologacao' ? $manualDevMessage : $defaultMessage;

            $conexao->update(['status' => 'erro', 'ultimo_erro' => 'Refresh token ausente.']);
            throw new ContaAzulException($message, 'refresh_token_ausente');
        }

        $token->refresh();
        if (!$forceRefresh && !$token->isAccessTokenExpired()) {
            return (string) $token->access_token;
        }

        try {
            $json = $this->oauth->refreshAccessToken((string) $refresh);
            $this->persistTokensFromOAuth($conexao, $json);
        } catch (\Throwable $e) {
            $conexao->update([
                'status' => 'erro',
                'ultimo_erro' => 'Falha ao renovar token: ' . $e->getMessage(),
            ]);
            StructuredLog::integration('conta_azul.token.refresh_failed', [
                'conexao_id' => $conexao->id,
            ], 'error');
            throw new ContaAzulException('Falha ao renovar token: ' . $e->getMessage(), 'refresh_token_falhou', [], $e);
        }

        $conexao->load('token');
        $new = $conexao->token;
        if (!$new) {
            throw new ContaAzulException('Token não encontrado após refresh.', 'token_nao_encontrado');
        }

        return (string) $new->access_token;
    }

    /**
     * Chamada leve para validar credenciais (lista mínima de pessoas).
     */
    public function healthcheck(ContaAzulConexao $conexao): bool
    {
        $path = (string) ($this->config['paths']['pessoas'] ?? '/v1/pessoas');
        $pageParam = (string) ($this->config['pagination']['page_param'] ?? 'pagina');
        $sizeParam = (string) ($this->config['pagination']['page_size_param'] ?? 'tamanho_pagina');
        $pageSize = max(10, (int) ($this->config['healthcheck_page_size'] ?? 10));

        $token = $this->getValidAccessToken($conexao);
        $res = $this->client->get($path, $token, [
            $pageParam => 1,
            $sizeParam => $pageSize,
        ]);

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $conexao->update([
            'ultimo_healthcheck_em' => now(),
            'ultimo_erro' => $ok ? null : $this->formatHealthcheckError($res),
            'status' => $ok ? 'ativa' : 'erro',
        ]);

        return $ok;
    }

    /**
     * @param  array{status:int, body?:?string, json?:mixed}  $res
     */
    private function formatHealthcheckError(array $res): string
    {
        $parts = ['HTTP ' . (int) $res['status']];
        $json = $res['json'] ?? null;

        if (is_array($json)) {
            foreach (['status_conta', 'codigo_erro', 'error', 'descricao_erro', 'mensagem', 'message'] as $key) {
                $value = $json[$key] ?? null;
                if (is_scalar($value) && $value !== '') {
                    $parts[] = $key . '=' . (string) $value;
                }
            }
        }

        if (count($parts) === 1 && isset($res['body']) && is_string($res['body']) && $res['body'] !== '') {
            $body = trim(preg_replace('/\s+/', ' ', $res['body']) ?? '');
            if ($body !== '') {
                $parts[] = mb_substr($body, 0, 240);
            }
        }

        return implode(' - ', array_values(array_unique($parts)));
    }
}
