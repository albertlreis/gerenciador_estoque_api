<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class ConfiguracaoController extends Controller
{
    /**
     * Lista todas as configurações do sistema como { chave: valor }
     */
    public function listar(): JsonResponse
    {
        $dados = DB::table('configuracoes')->pluck('valor', 'chave');
        return response()->json($dados);
    }

    /**
     * Atualiza uma configuração com base na chave fornecida
     */
    public function atualizar(Request $request, string $chave): JsonResponse
    {
        $request->validate([
            'valor' => 'required'
        ]);

        DB::table('configuracoes')->updateOrInsert(
            ['chave' => $chave],
            ['valor' => $request->valor]
        );

        return response()->json(['success' => true]);
    }
}
