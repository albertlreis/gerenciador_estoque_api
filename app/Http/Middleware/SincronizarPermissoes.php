<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SincronizarPermissoes
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->hasHeader('X-Permissoes')) {
            $usuarioId = $request->user()->id;
            $permissoesHeader = $request->header('X-Permissoes');

            try {
                $permissoes = json_decode($permissoesHeader, true);

                if (is_array($permissoes)) {
                    Cache::put("permissoes_usuario_{$usuarioId}", $permissoes, now()->addHours(6));
                }
            } catch (Throwable $e) {
                logger()->warning("Erro ao decodificar X-Permissoes para o usuário {$usuarioId}: {$e->getMessage()}");
            }
        }

        return $next($request);
    }
}
