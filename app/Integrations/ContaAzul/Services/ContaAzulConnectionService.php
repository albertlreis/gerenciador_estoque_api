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
        $query = ContaAzulConexao::query();
        if ($lojaId === null) {
            $query->whereNull('loja_id');
        } else {
            $query->where('loja_id', $lojaId);
        }

        $existing = $query->first();
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
            throw new ContaAzulException('Resposta OAuth sem access_token.');
        }

        $refresh = isset($tokenResponse['refresh_token']) ? (string) $tokenResponse['refresh_token'] : null;
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

    public function getValidAccessToken(ContaAzulConexao $conexao): string
    {
        $token = $conexao->token;
        if (!$token) {
            throw new ContaAzulException('Conexão sem tokens.');
        }

        if (!$token->isAccessTokenExpired()) {
            return (string) $token->access_token;
        }

        $refresh = $token->refresh_token;
        if (!$refresh) {
            $conexao->update(['status' => 'erro', 'ultimo_erro' => 'Refresh token ausente.']);
            throw new ContaAzulException('Refresh token ausente; reconecte a Conta Azul.');
        }

        $token->refresh();
        if (!$token->isAccessTokenExpired()) {
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
            throw new ContaAzulException('Falha ao renovar token: ' . $e->getMessage(), 0, $e);
        }

        $conexao->load('token');
        $new = $conexao->token;
        if (!$new) {
            throw new ContaAzulException('Token não encontrado após refresh.');
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
        $sizeParam = (string) ($this->config['pagination']['page_size_param'] ?? 'tamanhoPagina');

        $token = $this->getValidAccessToken($conexao);
        $res = $this->client->get($path, $token, [
            $pageParam => 1,
            $sizeParam => 1,
        ]);

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $conexao->update([
            'ultimo_healthcheck_em' => now(),
            'ultimo_erro' => $ok ? null : ('HTTP ' . $res['status']),
            'status' => $ok ? 'ativa' : 'erro',
        ]);

        return $ok;
    }
}
