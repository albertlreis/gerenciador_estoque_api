<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Classe responsável pela validação do cadastro de uma nova localização de estoque.
 *
 * Campos esperados:
 * - estoque_id: obrigatório, existente na tabela estoque e único.
 * - corredor, prateleira, coluna, nivel: strings opcionais com no máximo 10 caracteres.
 * - observacoes: texto livre opcional.
 */
class StoreLocalizacaoEstoqueRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a realizar esta requisição.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de validação para criação de localização de estoque.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'estoque_id'   => 'required|exists:estoque,id|unique:localizacoes_estoque,estoque_id',
            'corredor'     => 'nullable|string|max:10',
            'prateleira'   => 'nullable|string|max:10',
            'coluna'       => 'nullable|string|max:10',
            'nivel'        => 'nullable|string|max:10',
            'observacoes'  => 'nullable|string',
        ];
    }
}
