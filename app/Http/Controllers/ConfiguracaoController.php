<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracaoController extends Controller
{
    public function listar()
    {
        return DB::table('configuracoes')
            ->select('chave', 'valor', 'label', 'tipo')
            ->orderBy('chave')
            ->get();
    }

    public function atualizar(Request $request, string $chave)
    {
        $request->validate([
            'valor' => 'required|string|max:255',
        ]);

        $atualizado = DB::table('configuracoes')
            ->where('chave', $chave)
            ->update(['valor' => $request->valor, 'updated_at' => now()]);

        return response()->json(['success' => (bool) $atualizado]);
    }
}

