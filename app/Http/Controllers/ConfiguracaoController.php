<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Controller responsável pela gestão das configurações do sistema.
 *
 * Permite listar e atualizar valores armazenados na tabela `configuracoes`,
 * utilizada para definir parâmetros ajustáveis da aplicação.
 */
class ConfiguracaoController extends Controller
{
    /**
     * Retorna todas as configurações registradas no sistema.
     *
     * @return \Illuminate\Support\Collection
     *
     * Campos retornados:
     * - chave (string): identificador único da configuração
     * - valor (string): valor atual configurado
     * - label (string|null): nome amigável para exibição
     * - tipo (string): tipo de dado (string, integer, boolean)
     * - descricao (string|null): descrição explicativa para uso no front-end
     */
    public function listar(): Collection
    {
        return DB::table('configuracoes')
            ->select('chave', 'valor', 'label', 'tipo', 'descricao')
            ->orderBy('chave')
            ->get();
    }

    /**
     * Atualiza o valor de uma configuração com base em sua chave.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $chave  Chave da configuração a ser atualizada
     * @return \Illuminate\Http\JsonResponse
     *
     * Validação:
     * - valor (string): obrigatório, até 255 caracteres
     *
     * Retorna:
     * - success: true se a configuração foi atualizada com sucesso
     */
    public function atualizar(Request $request, string $chave): JsonResponse
    {
        // Valida o novo valor enviado
        $request->validate([
            'valor' => 'required|string|max:255',
        ]);

        // Realiza a atualização do valor e timestamp
        $atualizado = DB::table('configuracoes')
            ->where('chave', $chave)
            ->update([
                'valor' => $request->valor,
                'updated_at' => now(),
            ]);

        return response()->json(['success' => (bool) $atualizado]);
    }
}
