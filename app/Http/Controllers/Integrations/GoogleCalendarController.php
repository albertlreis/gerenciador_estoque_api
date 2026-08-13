<?php

namespace App\Http\Controllers\Integrations;

use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarCalendar;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarEventService;
use App\Models\AuditoriaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GoogleCalendarController extends Controller
{
    private const MANUAL_REVOKE_URL = 'https://myaccount.google.com/connections';

    public function __construct(
        private readonly GoogleCalendarConnectionService $connections,
        private readonly GoogleCalendarEventService $events
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.visualizar')) {
            return $response;
        }

        $conexao = $this->connections->forUser((int) $request->user()->id);
        if (!$conexao) {
            return response()->json(['conectado' => false, 'conexao' => null]);
        }

        return response()->json([
            'conectado' => $conexao->status === 'ativa',
            'conexao' => $conexao->only(['id', 'status', 'email_externo', 'nome_externo', 'ultimo_healthcheck_em', 'ultimo_erro']),
        ]);
    }

    public function calendars(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.visualizar')) {
            return $response;
        }

        $conexao = $this->connections->forUser((int) $request->user()->id);
        if (!$conexao) {
            return response()->json(['data' => []]);
        }

        try {
            $this->connections->syncCalendars($conexao);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        $calendars = GoogleCalendarCalendar::query()
            ->where('conexao_id', $conexao->id)
            ->orderByDesc('primary')
            ->orderBy('summary')
            ->get()
            ->map(fn (GoogleCalendarCalendar $calendar) => $this->formatCalendar($calendar))
            ->values();

        return response()->json(['data' => $calendars]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.configurar')) {
            return $response;
        }

        $result = $this->connections->disconnect((int) $request->user()->id);

        return response()->json([
            'ok' => true,
            'local_deleted' => $result['local_deleted'],
            'google_revoked' => $result['google_revoked'],
            'manual_revoke_url' => $result['google_revoked'] ? null : self::MANUAL_REVOKE_URL,
        ]);
    }

    public function enableCalendar(Request $request, string $calendarId): JsonResponse
    {
        return $this->setCalendarEnabled($request, $calendarId, true);
    }

    public function disableCalendar(Request $request, string $calendarId): JsonResponse
    {
        return $this->setCalendarEnabled($request, $calendarId, false);
    }

    public function updateCalendarVisibility(Request $request, string $calendarId): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.configurar')) {
            return $response;
        }

        $payload = $request->validate([
            'visibility' => ['required', 'string', 'in:public,private'],
        ]);

        $conexao = $this->connections->forUser((int) $request->user()->id);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Conexao Google Agenda nao encontrada.'], 404);
        }

        $calendar = GoogleCalendarCalendar::query()
            ->where('conexao_id', $conexao->id)
            ->where('calendar_id', $calendarId)
            ->first();

        if (!$calendar) {
            return response()->json(['ok' => false, 'mensagem' => 'Agenda Google nao encontrada.', 'reason' => 'calendar_not_found'], 404);
        }

        $calendar->update(['visibility' => $payload['visibility']]);

        return response()->json(['data' => $this->formatCalendar($calendar->fresh())]);
    }

    public function events(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.visualizar')) {
            return $response;
        }

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'q' => ['nullable', 'string', 'max:120'],
            'calendar_ids' => ['nullable'],
        ]);

        $validated['calendar_ids'] = $this->calendarIdsFromRequest($request);

        try {
            return response()->json([
                'data' => $this->events->listEvents($validated, $request->user()?->id),
            ]);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.criar')) {
            return $response;
        }

        $payload = $this->validateEventPayload($request, true);

        try {
            return response()->json(['data' => $this->events->createEvent($payload, $request->user()?->id)], 201);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    public function update(Request $request, string $eventId): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.editar')) {
            return $response;
        }

        $payload = $this->validateEventPayload($request, false);
        $calendarId = (string) $request->input('calendar_id', '');

        try {
            return response()->json(['data' => $this->events->updateEvent($calendarId, $eventId, $payload, $request->user()?->id)]);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    public function destroy(Request $request, string $eventId): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.cancelar')) {
            return $response;
        }

        $payload = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'send_updates' => ['nullable', 'boolean'],
        ]);

        try {
            $this->events->deleteEvent((string) $payload['calendar_id'], $eventId, $payload, $request->user()?->id);
            return response()->json(['ok' => true]);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    public function contacts(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.visualizar')) {
            return $response;
        }

        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min((int) $request->query('limit', 10), 20));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $items = collect();

        if (Schema::hasTable('acesso_usuarios')) {
            $items = $items->merge(DB::table('acesso_usuarios')
                ->select(['id', 'nome', 'email'])
                ->whereNotNull('email')
                ->where(function ($query) use ($like) {
                    $query->where('nome', 'like', $like)->orWhere('email', 'like', $like);
                })
                ->orderBy('nome')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'id' => 'usuario:' . $row->id,
                    'label' => (string) ($row->nome ?: $row->email),
                    'email' => (string) $row->email,
                    'type' => 'usuario',
                ]));
        }

        if (Schema::hasTable('clientes')) {
            $items = $items->merge(DB::table('clientes')
                ->select(['id', 'nome', 'nome_fantasia', 'email'])
                ->whereNotNull('email')
                ->where(function ($query) use ($like) {
                    $query->where('nome', 'like', $like)
                        ->orWhere('nome_fantasia', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('nome')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'id' => 'cliente:' . $row->id,
                    'label' => (string) ($row->nome_fantasia ?: $row->nome ?: $row->email),
                    'email' => (string) $row->email,
                    'type' => 'cliente',
                ]));
        }

        return response()->json([
            'data' => $items
                ->filter(fn ($item) => filter_var($item['email'] ?? null, FILTER_VALIDATE_EMAIL))
                ->unique('email')
                ->take($limit)
                ->values(),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.auditar')) {
            return $response;
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $page = AuditoriaLog::query()
            ->where('modulo', 'google_calendar')
            ->where('actor_id', (int) $request->user()->id)
            ->where(function ($query) {
                $query->where('source_table', 'google_calendar_logs')
                    ->orWhereNull('source_table');
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditoriaLog $log) => $this->formatGoogleLog($log))->values(),
            'meta' => [
                'total' => (int) $page->total(),
                'page' => (int) $page->currentPage(),
                'per_page' => (int) $page->perPage(),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatGoogleLog(AuditoriaLog $log): array
    {
        $context = $log->context_json ?? [];

        return [
            'id' => (int) ($log->source_id ?: $log->id),
            'conexao_id' => data_get($context, 'conexao_id'),
            'usuario_id' => $log->actor_id ?: data_get($context, 'usuario_id'),
            'calendar_id' => data_get($context, 'calendar_id'),
            'event_id' => $log->entity_id ?: data_get($context, 'event_id'),
            'acao' => $log->acao,
            'status' => $log->status,
            'request_resumo' => data_get($context, 'request_resumo'),
            'response_resumo' => data_get($context, 'response_resumo') ?: $log->message,
            'erro_codigo' => data_get($context, 'erro_codigo'),
            'erro_mensagem' => data_get($context, 'erro_mensagem'),
            'executado_em' => optional($log->occurred_at)->toISOString(),
            'created_at' => optional($log->created_at)->toISOString(),
            'updated_at' => optional($log->updated_at)->toISOString(),
        ];
    }

    private function setCalendarEnabled(Request $request, string $calendarId, bool $enabled): JsonResponse
    {
        if ($response = $this->autorizar('google_calendar.configurar')) {
            return $response;
        }

        try {
            $calendar = $this->connections->setCalendarEnabled((int) $request->user()->id, $calendarId, $enabled);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['data' => $this->formatCalendar($calendar)]);
    }

    private function autorizar(string $permissao): ?JsonResponse
    {
        if (!auth()->user()?->ativo) {
            return response()->json(['message' => 'Usuario inativo.'], 403);
        }

        if (AuthHelper::hasPermissao($permissao)) {
            return null;
        }

        return response()->json(['message' => 'Sem permissao para acessar a integracao Google Agenda.'], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCalendar(GoogleCalendarCalendar $calendar): array
    {
        return [
            'id' => $calendar->calendar_id,
            'summary' => $calendar->summary,
            'description' => $calendar->description,
            'timezone' => $calendar->timezone,
            'access_role' => $calendar->access_role,
            'primary' => $calendar->primary,
            'enabled' => $calendar->enabled,
            'visibility' => $calendar->visibility ?: 'private',
            'writable' => $calendar->isWritable(),
            'background_color' => $calendar->background_color,
            'foreground_color' => $calendar->foreground_color,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function calendarIdsFromRequest(Request $request): array
    {
        $value = $request->query('calendar_ids', $request->input('calendar_ids', []));
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return is_array($value) ? array_values(array_filter(array_map(fn ($id) => trim((string) $id), $value))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEventPayload(Request $request, bool $creating): array
    {
        return $request->validate(self::eventValidationRules($creating));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function eventValidationRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $dateRequired = $creating ? 'required' : 'nullable';

        $rules = [
            'calendar_id' => ['required', 'string', 'max:255'],
            'summary' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:6000'],
            'location' => ['nullable', 'string', 'max:255'],
            'start' => [$dateRequired, 'date', $creating ? null : 'required_with:end'],
            'end' => [$dateRequired, 'date', 'after:start', $creating ? null : 'required_with:start'],
            'all_day' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'generate_meet' => ['nullable', 'boolean'],
            'send_updates' => ['nullable', 'boolean'],
            'attendees' => ['nullable', 'array'],
        ];

        return array_map(
            fn (array $rule) => array_values(array_filter($rule, fn ($item) => $item !== null)),
            $rules
        );
    }
}
