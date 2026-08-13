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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\AcessoUsuario;

class GoogleCalendarOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleCalendarOAuthService $oauth,
        private readonly GoogleCalendarConnectionService $connections
    ) {
    }

    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->user()?->ativo) {
            return response()->json(['message' => 'Usuario inativo.'], 403);
        }
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
            return response()->json([
                'ok' => false,
                'mensagem' => $e->getMessage(),
                'reason' => $e->reason,
                'missing_config' => $e->context['missing_config'] ?? [],
            ], 422);
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

        $usuarioId = (int) ($payload['user_id'] ?? 0);
        $usuario = $usuarioId > 0 ? AcessoUsuario::query()->find($usuarioId) : null;
        if (!$usuario || !$usuario->ativo) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('usuario_inativo'));
        }
        if (!$this->userHasPermission($usuarioId, 'google_calendar.configurar')) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('permissao_revogada'));
        }

        try {
            $tokens = $this->oauth->exchangeCodeForToken($code);
            $this->connections->completeOAuthForUser($usuarioId, $tokens);
        } catch (GoogleCalendarException $e) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode($e->reason));
        } catch (\Throwable $e) {
            return redirect()->away($front . '?gc=erro&reason=' . urlencode('oauth_callback_failed'));
        }

        return redirect()->away($front . '?gc=ok');
    }

    private function userHasPermission(int $usuarioId, string $slug): bool
    {
        if (!Schema::hasTable('acesso_usuario_perfil')
            || !Schema::hasTable('acesso_perfil_permissao')
            || !Schema::hasTable('acesso_permissoes')) {
            $cached = Cache::get('permissoes_usuario_' . $usuarioId, []);
            return is_array($cached) && in_array($slug, $cached, true);
        }

        return DB::table('acesso_usuario_perfil')
            ->join('acesso_perfil_permissao', 'acesso_usuario_perfil.id_perfil', '=', 'acesso_perfil_permissao.id_perfil')
            ->join('acesso_permissoes', 'acesso_perfil_permissao.id_permissao', '=', 'acesso_permissoes.id')
            ->where('acesso_usuario_perfil.id_usuario', $usuarioId)
            ->where('acesso_permissoes.slug', $slug)
            ->exists();
    }
}
