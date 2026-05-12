<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSenhaNaoObrigatoria
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->forcar_troca_senha) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Troca de senha obrigatoria.',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
        ], 423);
    }
}
