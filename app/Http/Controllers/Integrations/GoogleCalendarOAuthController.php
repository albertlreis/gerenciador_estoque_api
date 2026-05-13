<?php

namespace App\Http\Controllers\Integrations;

use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GoogleCalendarOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleCalendarOAuthService $oauth,
        private readonly GoogleCalendarConnectionService $connections
    ) {
    }

    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        if (!AuthHelper::hasPermissao('google_calendar.configurar')) {
            return response()->json(['message' => 'Sem permissao para configurar a integracao Google Agenda.'], 403);
        }

        try {
            $state = Str::random(48);
            Cache::put('google_calendar_oauth:' . $state, [
                'user_id' => $request->user()?->id,
            ], now()->addMinutes(10));

            $url = $this->oauth->buildAuthorizationUrl($state);
        } catch (GoogleCalendarException $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        if ($request->wantsJson()) {
            return response()->json(['url' => $url]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $front = rtrim((string) config('google_calendar.oauth_front_redirect'), '/');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('oauth_denied'));
        }

        if ($state === '' || $code === '') {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('parametros_invalidos'));
        }

        $payload = Cache::pull('google_calendar_oauth:' . $state);
        if (!is_array($payload)) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('state_invalido'));
        }

        try {
            $tokens = $this->oauth->exchangeCodeForToken($code);
            $conexao = $this->connections->findOrCreate();
            $this->connections->persistTokensFromOAuth($conexao, $tokens);
            if (!$this->connections->healthcheck($conexao)) {
                return redirect()->away($front . '?gc=erro&reason=' . urlencode('healthcheck_failed'));
            }
            $this->connections->syncCalendars($conexao->fresh(['token']));
        } catch (GoogleCalendarException $e) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode($e->reason));
        } catch (\Throwable $e) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('oauth_callback_failed'));
        }

        return redirect()->away($front . '?gc=ok');
    }
}
