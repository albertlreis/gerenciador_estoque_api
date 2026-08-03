<?php

namespace App\Http\Controllers\Integrations;

use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Support\StructuredLog;
use App\Models\Loja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        if (!AuthHelper::podeConfigurarContaAzul()) {
            return response()->json(['message' => 'Sem permissao para configurar a integracao Conta Azul.'], 403);
        }

        $lojaId = $request->integer('loja_id');
        $loja = $lojaId > 0 ? Loja::query()->find($lojaId) : null;
        if (! $loja) {
            return response()->json([
                'message' => 'Selecione uma loja cadastrada antes de iniciar a autenticação.',
                'reason' => 'loja_nao_informada',
            ], 422);
        }

        if (! $loja->ativo) {
            return response()->json([
                'message' => 'A loja selecionada está inativa.',
                'reason' => 'loja_inativa',
            ], 422);
        }

        $nonce = Str::random(48);
        $previousNonce = Cache::pull('ca_oauth_store:' . $lojaId);
        if (is_string($previousNonce) && $previousNonce !== '') {
            Cache::forget('ca_oauth:' . $previousNonce);
        }

        $issuedAt = now()->timestamp;
        $state = Crypt::encryptString(json_encode([
            'nonce' => $nonce,
            'user_id' => $request->user()?->id,
            'loja_id' => $lojaId,
            'issued_at' => $issuedAt,
        ], JSON_THROW_ON_ERROR));
        Cache::put(
            'ca_oauth:' . $nonce,
            [
                'user_id' => $request->user()?->id,
                'loja_id' => $lojaId,
                'issued_at' => $issuedAt,
            ],
            now()->addMinutes(10)
        );
        Cache::put('ca_oauth_store:' . $lojaId, $nonce, now()->addMinutes(10));

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

        $decodedState = $this->decodeState($state);
        $stateRef = $state === '' ? null : substr(hash('sha256', $state), 0, 12);

        if ($error !== '') {
            if (is_array($decodedState)) {
                $deniedNonce = (string) ($decodedState['nonce'] ?? '');
                $deniedLojaId = (int) ($decodedState['loja_id'] ?? 0);
                if ($deniedNonce !== '') {
                    Cache::pull('ca_oauth:' . $deniedNonce);
                }
                if ($deniedLojaId > 0 && Cache::get('ca_oauth_store:' . $deniedLojaId) === $deniedNonce) {
                    Cache::forget('ca_oauth_store:' . $deniedLojaId);
                }
            }

            StructuredLog::integration('conta_azul.oauth.provider_denied', [
                'state_ref' => $stateRef,
                'provider_error' => $error,
            ], 'warning');

            return redirect()->away($front . '?ca=erro&reason=' . urlencode('oauth_denied'));
        }

        if ($state === '' || $code === '') {
            return redirect()->away($front . '?ca=erro&reason=' . urlencode('parametros_invalidos'));
        }

        if (! is_array($decodedState)) {
            return redirect()->away($front . '?ca=erro&reason=' . urlencode('state_invalido'));
        }

        $nonce = (string) ($decodedState['nonce'] ?? '');
        $lojaId = (int) ($decodedState['loja_id'] ?? 0);
        $issuedAt = (int) ($decodedState['issued_at'] ?? 0);
        $payload = $nonce !== '' ? Cache::pull('ca_oauth:' . $nonce) : null;
        $currentNonce = $lojaId > 0 ? Cache::pull('ca_oauth_store:' . $lojaId) : null;
        $stateMatches = is_array($payload)
            && hash_equals((string) $currentNonce, $nonce)
            && (int) ($payload['loja_id'] ?? 0) === $lojaId
            && (int) ($payload['user_id'] ?? 0) === (int) ($decodedState['user_id'] ?? 0)
            && $issuedAt > 0
            && abs(now()->timestamp - $issuedAt) <= 600;

        if (! $stateMatches) {
            return redirect()->away($front . '?ca=erro&reason=' . urlencode('state_invalido'));
        }

        try {
            $loja = Loja::query()->find($lojaId);
            if (! $loja || ! $loja->ativo) {
                throw new ContaAzulException('A loja selecionada não está ativa.', 'loja_inativa');
            }

            $tokens = $this->oauth->exchangeCodeForToken($code);
            $conexao = $this->connections->findOrCreateConexao($lojaId);
            $this->connections->persistTokensFromOAuth($conexao, $tokens);
            $ok = $this->connections->healthcheck($conexao);

            if (!$ok) {
                StructuredLog::integration('conta_azul.oauth.healthcheck_failed', [
                    'loja_id' => $lojaId,
                    'state_ref' => $stateRef,
                    'conexao_id' => $conexao->id,
                ], 'warning');

                return redirect()->away($front . '?ca=erro&reason=' . urlencode('healthcheck_failed'));
            }
        } catch (ContaAzulException $e) {
            StructuredLog::integration('conta_azul.oauth.callback_failed', array_merge([
                'loja_id' => $lojaId,
                'state_ref' => $stateRef,
                'reason' => $e->reason,
            ], $e->context), 'warning');

            return redirect()->away($front . '?ca=erro&reason=' . urlencode($e->reason));
        } catch (\Throwable $e) {
            StructuredLog::integration('conta_azul.oauth.callback_unexpected_failed', [
                'loja_id' => $lojaId,
                'state_ref' => $stateRef,
                'message' => $e->getMessage(),
            ], 'error');

            return redirect()->away($front . '?ca=erro&reason=' . urlencode('oauth_callback_failed'));
        }

        return redirect()->away($front . '?ca=ok');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
