<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Support\StructuredLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ContaAzulOAuthController extends Controller
{
    public function __construct(
        private readonly ContaAzulOAuthService $oauth,
        private readonly ContaAzulConnectionService $connections
    ) {
    }

    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        $lojaId = $request->query('loja_id');
        $lojaId = $lojaId !== null && $lojaId !== '' ? (int) $lojaId : null;

        $state = Str::random(48);
        Cache::put(
            'ca_oauth:' . $state,
            [
                'user_id' => $request->user()?->id,
                'loja_id' => $lojaId,
            ],
            now()->addMinutes(10)
        );

        $url = $this->oauth->buildAuthorizationUrl($state);

        if ($request->wantsJson()) {
            return response()->json(['url' => $url]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $front = rtrim((string) config('conta_azul.oauth_front_redirect'), '/');

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            StructuredLog::integration('conta_azul.oauth.provider_denied', [
                'state' => $state !== '' ? $state : null,
                'provider_error' => $error,
            ], 'warning');

            return redirect()->away($front . '?ca=erro&reason=' . urlencode('oauth_denied'));
        }

        if ($state === '' || $code === '') {
            return redirect()->away($front . '?ca=erro&reason=' . urlencode('parametros_invalidos'));
        }

        $payload = Cache::pull('ca_oauth:' . $state);
        if (!is_array($payload)) {
            return redirect()->away($front . '?ca=erro&reason=' . urlencode('state_invalido'));
        }

        $lojaId = $payload['loja_id'] ?? null;
        if ($lojaId !== null) {
            $lojaId = (int) $lojaId;
        }

        try {
            $tokens = $this->oauth->exchangeCodeForToken($code);
            $conexao = $this->connections->findOrCreateConexao($lojaId);
            $this->connections->persistTokensFromOAuth($conexao, $tokens);
            $ok = $this->connections->healthcheck($conexao);

            if (!$ok) {
                StructuredLog::integration('conta_azul.oauth.healthcheck_failed', [
                    'loja_id' => $lojaId,
                    'state' => $state,
                    'conexao_id' => $conexao->id,
                ], 'warning');

                return redirect()->away($front . '?ca=erro&reason=' . urlencode('healthcheck_failed'));
            }
        } catch (ContaAzulException $e) {
            StructuredLog::integration('conta_azul.oauth.callback_failed', array_merge([
                'loja_id' => $lojaId,
                'state' => $state,
                'reason' => $e->reason,
            ], $e->context), 'warning');

            return redirect()->away($front . '?ca=erro&reason=' . urlencode($e->reason));
        } catch (\Throwable $e) {
            StructuredLog::integration('conta_azul.oauth.callback_unexpected_failed', [
                'loja_id' => $lojaId,
                'state' => $state,
                'message' => $e->getMessage(),
            ], 'error');

            return redirect()->away($front . '?ca=erro&reason=' . urlencode('oauth_callback_failed'));
        }

        return redirect()->away($front . '?ca=ok');
    }
}
