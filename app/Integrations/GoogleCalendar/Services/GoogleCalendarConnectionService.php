<?php

namespace App\Integrations\GoogleCalendar\Services;

use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarCalendar;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarConexao;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GoogleCalendarConnectionService
{
    public function __construct(
        private readonly array $config,
        private readonly GoogleCalendarOAuthService $oauth,
        private readonly GoogleCalendarClient $client
    ) {
    }

    public function latest(): ?GoogleCalendarConexao
    {
        return GoogleCalendarConexao::query()->with('token')->orderByDesc('id')->first();
    }

    public function findOrCreate(): GoogleCalendarConexao
    {
        return $this->latest() ?? GoogleCalendarConexao::create(['status' => 'inativa']);
    }

    /**
     * @param array<string, mixed> $tokenResponse
     */
    public function persistTokensFromOAuth(GoogleCalendarConexao $conexao, array $tokenResponse): GoogleCalendarToken
    {
        $access = (string) ($tokenResponse['access_token'] ?? '');
        if ($access === '') {
            throw new GoogleCalendarException('Resposta OAuth sem access_token.', 'token_sem_access_token');
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

            $token = GoogleCalendarToken::query()->firstOrNew(['conexao_id' => $conexao->id]);
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

    public function getValidAccessToken(GoogleCalendarConexao $conexao): string
    {
        $conexao->loadMissing('token');
        $token = $conexao->token;
        if (!$token) {
            throw new GoogleCalendarException('Conexao Google Agenda sem tokens.', 'conexao_sem_token');
        }

        if (!$token->isAccessTokenExpired()) {
            return (string) $token->access_token;
        }

        if (!$token->refresh_token) {
            $conexao->update(['status' => 'erro', 'ultimo_erro' => 'Refresh token ausente.']);
            throw new GoogleCalendarException('Refresh token ausente; reconecte a Google Agenda.', 'refresh_token_ausente');
        }

        try {
            $json = $this->oauth->refreshAccessToken((string) $token->refresh_token);
            $this->persistTokensFromOAuth($conexao, $json);
        } catch (\Throwable $e) {
            $conexao->update(['status' => 'erro', 'ultimo_erro' => 'Falha ao renovar token: ' . $e->getMessage()]);
            throw new GoogleCalendarException('Falha ao renovar token: ' . $e->getMessage(), 'refresh_token_falhou', [], $e);
        }

        $conexao->load('token');
        if (!$conexao->token) {
            throw new GoogleCalendarException('Token nao encontrado apos refresh.', 'token_nao_encontrado');
        }

        return (string) $conexao->token->access_token;
    }

    public function healthcheck(GoogleCalendarConexao $conexao): bool
    {
        $token = $this->getValidAccessToken($conexao);
        $res = $this->client->get((string) ($this->config['paths']['calendar_list'] ?? '/users/me/calendarList'), $token, [
            'maxResults' => 1,
        ]);

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $conexao->update([
            'ultimo_healthcheck_em' => now(),
            'ultimo_erro' => $ok ? null : $this->formatError($res),
            'status' => $ok ? 'ativa' : 'erro',
        ]);

        return $ok;
    }

    /**
     * @return array<int, GoogleCalendarCalendar>
     */
    public function syncCalendars(GoogleCalendarConexao $conexao): array
    {
        $token = $this->getValidAccessToken($conexao);
        $path = (string) ($this->config['paths']['calendar_list'] ?? '/users/me/calendarList');
        $items = [];
        $pageToken = null;

        do {
            $query = ['maxResults' => 250, 'minAccessRole' => 'reader'];
            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $res = $this->client->get($path, $token, $query);
            if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
                throw new GoogleCalendarException('Nao foi possivel listar agendas Google.', 'calendar_list_failed', ['response' => $res]);
            }

            foreach (($res['json']['items'] ?? []) as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }
                $items[] = $this->upsertCalendar($conexao, $item);
            }

            $pageToken = isset($res['json']['nextPageToken']) ? (string) $res['json']['nextPageToken'] : null;
        } while ($pageToken);

        return $items;
    }

    public function setCalendarEnabled(string $calendarId, bool $enabled): GoogleCalendarCalendar
    {
        $conexao = $this->findOrCreate();
        $calendar = GoogleCalendarCalendar::query()
            ->where('conexao_id', $conexao->id)
            ->where('calendar_id', $calendarId)
            ->first();

        if (!$calendar) {
            $this->syncCalendars($conexao);
            $calendar = GoogleCalendarCalendar::query()
                ->where('conexao_id', $conexao->id)
                ->where('calendar_id', $calendarId)
                ->first();
        }

        if (!$calendar) {
            throw new GoogleCalendarException('Agenda nao encontrada para a conexao Google atual.', 'calendar_not_found');
        }

        $calendar->update(['enabled' => $enabled]);

        return $calendar->fresh();
    }

    /**
     * @param array<string, mixed> $item
     */
    private function upsertCalendar(GoogleCalendarConexao $conexao, array $item): GoogleCalendarCalendar
    {
        $calendar = GoogleCalendarCalendar::query()->firstOrNew([
            'conexao_id' => $conexao->id,
            'calendar_id' => (string) $item['id'],
        ]);

        $calendar->fill([
            'summary' => (string) ($item['summary'] ?? $item['id']),
            'description' => $item['description'] ?? null,
            'timezone' => $item['timeZone'] ?? null,
            'access_role' => $item['accessRole'] ?? null,
            'primary' => (bool) ($item['primary'] ?? false),
            'background_color' => $item['backgroundColor'] ?? null,
            'foreground_color' => $item['foregroundColor'] ?? null,
            'synced_at' => now(),
            'metadata_json' => $item,
        ]);

        if (!$calendar->exists && (bool) ($item['primary'] ?? false)) {
            $calendar->enabled = true;
        }

        $calendar->save();

        return $calendar;
    }

    /**
     * @param array{status:int, body?:?string, json?:mixed} $res
     */
    private function formatError(array $res): string
    {
        $parts = ['HTTP ' . (int) $res['status']];
        $json = $res['json'] ?? null;

        if (is_array($json)) {
            $message = $json['error']['message'] ?? $json['error_description'] ?? $json['error'] ?? null;
            if (is_scalar($message) && $message !== '') {
                $parts[] = (string) $message;
            }
        }

        if (count($parts) === 1 && isset($res['body']) && is_string($res['body']) && $res['body'] !== '') {
            $parts[] = mb_substr(trim(preg_replace('/\s+/', ' ', $res['body']) ?? ''), 0, 240);
        }

        return implode(' - ', array_values(array_unique($parts)));
    }
}
