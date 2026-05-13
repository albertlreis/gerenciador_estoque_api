<?php

namespace App\Integrations\GoogleCalendar\Services;

use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarCalendar;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarConexao;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GoogleCalendarEventService
{
    public function __construct(
        private readonly array $config,
        private readonly GoogleCalendarConnectionService $connections,
        private readonly GoogleCalendarClient $client
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(array $filters, ?int $usuarioId = null): array
    {
        $conexao = $this->requireConnection();
        $calendars = $this->selectedCalendars($conexao, $filters['calendar_ids'] ?? []);
        if ($calendars->isEmpty()) {
            return [];
        }

        $version = (int) Cache::get('google_calendar_events_version', 1);
        $cacheKey = 'google_calendar_events:' . md5(json_encode([
            'version' => $version,
            'usuario_id' => $usuarioId,
            'start' => $filters['start'] ?? null,
            'end' => $filters['end'] ?? null,
            'calendar_ids' => $calendars->pluck('calendar_id')->values()->all(),
            'q' => $filters['q'] ?? null,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds($this->cacheTtl()), function () use ($conexao, $calendars, $filters) {
            $token = $this->connections->getValidAccessToken($conexao);
            $events = [];

            foreach ($calendars as $calendar) {
                $events = array_merge($events, $this->listCalendarEvents($token, $calendar, $filters));
            }

            usort($events, fn ($a, $b) => strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? '')));

            return array_values($events);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createEvent(array $payload, ?int $usuarioId = null): array
    {
        $conexao = $this->requireConnection();
        $calendar = $this->writableCalendar($conexao, (string) ($payload['calendar_id'] ?? ''));
        $token = $this->connections->getValidAccessToken($conexao);
        $body = $this->eventBody($payload, true);
        $query = $this->mutationQuery($payload);

        if (!empty($payload['generate_meet'])) {
            $query['conferenceDataVersion'] = 1;
        }

        try {
            $res = $this->client->post($this->eventsPath($calendar->calendar_id), $token, $body, $query);
            $this->assertSuccess($res, 'create_failed');
        } catch (GoogleCalendarException $e) {
            $this->log($conexao, $usuarioId, 'create', 'erro', $calendar->calendar_id, null, $body, $e->context, $e);
            throw $e;
        }
        $this->invalidateCache();

        $event = $this->formatEvent($res['json'] ?? [], $calendar);
        $this->log($conexao, $usuarioId, 'create', 'sucesso', $calendar->calendar_id, $event['id'] ?? null, $body, $res['json'] ?? null);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateEvent(string $calendarId, string $eventId, array $payload, ?int $usuarioId = null): array
    {
        $conexao = $this->requireConnection();
        $calendar = $this->writableCalendar($conexao, $calendarId);
        $token = $this->connections->getValidAccessToken($conexao);
        $body = $this->eventBody($payload, false);
        $query = $this->mutationQuery($payload);

        if (!empty($payload['generate_meet'])) {
            $body['conferenceData'] = [
                'createRequest' => [
                    'requestId' => 'sierra-' . Str::uuid()->toString(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
            $query['conferenceDataVersion'] = 1;
        }

        try {
            $res = $this->client->patch($this->eventPath($calendarId, $eventId), $token, $body, $query);
            $this->assertSuccess($res, 'update_failed');
        } catch (GoogleCalendarException $e) {
            $this->log($conexao, $usuarioId, 'update', 'erro', $calendarId, $eventId, $body, $e->context, $e);
            throw $e;
        }
        $this->invalidateCache();

        $event = $this->formatEvent($res['json'] ?? [], $calendar);
        $this->log($conexao, $usuarioId, 'update', 'sucesso', $calendarId, $eventId, $body, $res['json'] ?? null);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function deleteEvent(string $calendarId, string $eventId, array $payload = [], ?int $usuarioId = null): void
    {
        $conexao = $this->requireConnection();
        $this->writableCalendar($conexao, $calendarId);
        $token = $this->connections->getValidAccessToken($conexao);
        try {
            $res = $this->client->delete($this->eventPath($calendarId, $eventId), $token, $this->mutationQuery($payload));
            $this->assertSuccess($res, 'delete_failed');
        } catch (GoogleCalendarException $e) {
            $this->log($conexao, $usuarioId, 'delete', 'erro', $calendarId, $eventId, $payload, $e->context, $e);
            throw $e;
        }
        $this->invalidateCache();
        $this->log($conexao, $usuarioId, 'delete', 'sucesso', $calendarId, $eventId, $payload, $res['json'] ?? ['status' => $res['status']]);
    }

    public function invalidateCache(): void
    {
        Cache::add('google_calendar_events_version', 1, now()->addYear());
        Cache::increment('google_calendar_events_version');
    }

    private function requireConnection(): GoogleCalendarConexao
    {
        $conexao = $this->connections->latest();
        if (!$conexao || $conexao->status !== 'ativa') {
            throw new GoogleCalendarException('Nenhuma conexao ativa com Google Agenda.', 'conexao_inativa');
        }

        return $conexao;
    }

    /**
     * @param array<int, string>|string $calendarIds
     */
    private function selectedCalendars(GoogleCalendarConexao $conexao, array|string $calendarIds)
    {
        $ids = is_array($calendarIds) ? $calendarIds : explode(',', $calendarIds);
        $ids = array_values(array_filter(array_map(fn ($id) => trim((string) $id), $ids)));

        $query = GoogleCalendarCalendar::query()
            ->where('conexao_id', $conexao->id)
            ->where('enabled', true);

        if ($ids !== []) {
            $query->whereIn('calendar_id', $ids);
        }

        return $query->orderByDesc('primary')->orderBy('summary')->get();
    }

    private function writableCalendar(GoogleCalendarConexao $conexao, string $calendarId): GoogleCalendarCalendar
    {
        $calendar = GoogleCalendarCalendar::query()
            ->where('conexao_id', $conexao->id)
            ->where('calendar_id', $calendarId)
            ->first();

        if (!$calendar) {
            throw new GoogleCalendarException('Agenda Google nao encontrada ou nao habilitada.', 'calendar_not_found');
        }

        if (!$calendar->isWritable()) {
            throw new GoogleCalendarException('A conta conectada nao tem permissao de escrita nesta agenda.', 'calendar_readonly');
        }

        return $calendar;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function listCalendarEvents(string $token, GoogleCalendarCalendar $calendar, array $filters): array
    {
        $path = $this->eventsPath($calendar->calendar_id);
        $query = [
            'timeMin' => $this->toRfc3339((string) ($filters['start'] ?? now()->startOfWeek()->toIso8601String())),
            'timeMax' => $this->toRfc3339((string) ($filters['end'] ?? now()->endOfWeek()->toIso8601String())),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ];

        if (!empty($filters['q'])) {
            $query['q'] = (string) $filters['q'];
        }

        $events = [];
        $pageToken = null;
        do {
            $pageQuery = $query;
            if ($pageToken) {
                $pageQuery['pageToken'] = $pageToken;
            }

            $res = $this->client->get($path, $token, $pageQuery);
            $this->assertSuccess($res, 'list_failed');
            $json = is_array($res['json']) ? $res['json'] : [];
            foreach (($json['items'] ?? []) as $event) {
                if (is_array($event)) {
                    $events[] = $this->formatEvent($event, $calendar);
                }
            }

            $pageToken = isset($json['nextPageToken']) ? (string) $json['nextPageToken'] : null;
        } while ($pageToken);

        return $events;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function eventBody(array $payload, bool $creating): array
    {
        $body = [];
        foreach (['summary', 'description', 'location'] as $field) {
            if (array_key_exists($field, $payload)) {
                $body[$field] = $payload[$field];
            }
        }

        if (array_key_exists('attendees', $payload)) {
            $attendees = is_array($payload['attendees']) ? $payload['attendees'] : [];
            $body['attendees'] = collect($attendees)
                ->map(fn ($attendee) => is_array($attendee) ? ($attendee['email'] ?? null) : $attendee)
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()
                ->map(fn ($email) => ['email' => (string) $email])
                ->values()
                ->all();
        }

        $hasDates = array_key_exists('start', $payload) || array_key_exists('end', $payload);
        if ($creating || $hasDates) {
            $allDay = filter_var($payload['all_day'] ?? false, FILTER_VALIDATE_BOOL);
            $timezone = (string) ($payload['timezone'] ?? $this->config['timezone'] ?? 'America/Sao_Paulo');
            $startValue = (string) ($payload['start'] ?? '');
            $endValue = (string) ($payload['end'] ?? '');
            $body['start'] = $this->googleDateValue($startValue, $allDay, $timezone);
            $body['end'] = $this->googleDateValue($this->normalizeEndValue($startValue, $endValue, $allDay, $timezone), $allDay, $timezone);
        }

        if ($creating && !empty($payload['generate_meet'])) {
            $body['conferenceData'] = [
                'createRequest' => [
                    'requestId' => 'sierra-' . Str::uuid()->toString(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function mutationQuery(array $payload): array
    {
        $sendUpdates = array_key_exists('send_updates', $payload)
            ? filter_var($payload['send_updates'], FILTER_VALIDATE_BOOL)
            : true;

        return ['sendUpdates' => $sendUpdates ? 'all' : 'none'];
    }

    /**
     * @return array<string, string>
     */
    private function googleDateValue(string $value, bool $allDay, string $timezone): array
    {
        if ($allDay) {
            return ['date' => CarbonImmutable::parse($value, $timezone)->toDateString()];
        }

        return [
            'dateTime' => CarbonImmutable::parse($value, $timezone)->toRfc3339String(),
            'timeZone' => $timezone,
        ];
    }

    private function normalizeEndValue(string $start, string $end, bool $allDay, string $timezone): string
    {
        if (!$allDay) {
            return $end;
        }

        $startDate = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $endDate = CarbonImmutable::parse($end, $timezone)->startOfDay();

        if ($endDate->lessThanOrEqualTo($startDate)) {
            return $startDate->addDay()->toDateString();
        }

        return $endDate->toDateString();
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function formatEvent(array $event, GoogleCalendarCalendar $calendar): array
    {
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        $startValue = is_array($start) ? ($start['dateTime'] ?? $start['date'] ?? null) : null;
        $endValue = is_array($end) ? ($end['dateTime'] ?? $end['date'] ?? null) : null;
        $attendees = collect(is_array($event['attendees'] ?? null) ? $event['attendees'] : [])
            ->map(fn ($attendee) => [
                'email' => $attendee['email'] ?? null,
                'display_name' => $attendee['displayName'] ?? null,
                'response_status' => $attendee['responseStatus'] ?? null,
            ])
            ->filter(fn ($attendee) => !empty($attendee['email']))
            ->values()
            ->all();

        return [
            'id' => $event['id'] ?? null,
            'calendar_id' => $calendar->calendar_id,
            'calendar_summary' => $calendar->summary,
            'summary' => $event['summary'] ?? '(Sem titulo)',
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'start' => $startValue,
            'end' => $endValue,
            'all_day' => is_array($start) && array_key_exists('date', $start),
            'attendees' => $attendees,
            'html_link' => $event['htmlLink'] ?? null,
            'meet_link' => $this->meetLink($event),
            'status' => $event['status'] ?? null,
            'editable' => $calendar->isWritable(),
            'source' => 'google',
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function meetLink(array $event): ?string
    {
        if (!empty($event['hangoutLink'])) {
            return (string) $event['hangoutLink'];
        }

        $entryPoints = $event['conferenceData']['entryPoints'] ?? null;
        if (!is_array($entryPoints)) {
            return null;
        }

        foreach ($entryPoints as $entryPoint) {
            if (is_array($entryPoint) && ($entryPoint['entryPointType'] ?? null) === 'video' && !empty($entryPoint['uri'])) {
                return (string) $entryPoint['uri'];
            }
        }

        return null;
    }

    /**
     * @param array{status:int,json:mixed,body:?string} $res
     */
    private function assertSuccess(array $res, string $reason): void
    {
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return;
        }

        $json = is_array($res['json']) ? $res['json'] : [];
        $message = $json['error']['message'] ?? $json['error_description'] ?? $res['body'] ?? 'Falha na API Google Calendar.';

        throw new GoogleCalendarException((string) $message, $reason, ['status' => $res['status'], 'response' => $res['json']]);
    }

    private function eventsPath(string $calendarId): string
    {
        return str_replace('{calendar_id}', rawurlencode($calendarId), (string) ($this->config['paths']['events'] ?? '/calendars/{calendar_id}/events'));
    }

    private function eventPath(string $calendarId, string $eventId): string
    {
        return str_replace(
            ['{calendar_id}', '{event_id}'],
            [rawurlencode($calendarId), rawurlencode($eventId)],
            (string) ($this->config['paths']['event'] ?? '/calendars/{calendar_id}/events/{event_id}')
        );
    }

    private function toRfc3339(string $value): string
    {
        return CarbonImmutable::parse($value, (string) ($this->config['timezone'] ?? 'America/Sao_Paulo'))->toRfc3339String();
    }

    private function cacheTtl(): int
    {
        return max(30, min((int) ($this->config['cache_ttl_seconds'] ?? 90), 300));
    }

    private function log(
        GoogleCalendarConexao $conexao,
        ?int $usuarioId,
        string $action,
        string $status,
        ?string $calendarId,
        ?string $eventId,
        mixed $request,
        mixed $response,
        ?GoogleCalendarException $exception = null
    ): void {
        GoogleCalendarLog::create([
            'conexao_id' => $conexao->id,
            'usuario_id' => $usuarioId,
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'acao' => $action,
            'status' => $status,
            'request_resumo' => $this->summarize($request),
            'response_resumo' => $this->summarize($response),
            'erro_codigo' => $exception?->reason,
            'erro_mensagem' => $exception?->getMessage(),
            'executado_em' => now(),
        ]);
    }

    private function summarize(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return null;
        }

        return mb_substr($json, 0, 1000);
    }
}
