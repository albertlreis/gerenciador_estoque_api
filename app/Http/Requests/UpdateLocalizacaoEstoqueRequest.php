<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Classe responsável pela validação da atualização de uma localização de estoque.
 *
 * Campos esperados:
 * - estoque_id: obrigatório, existente na tabela estoque e único (exceto para o próprio ID).
 * - corredor, prateleira, coluna, nivel: strings opcionais com no máximo 10 caracteres.
 * - observacoes: texto livre opcional.
 */
class UpdateLocalizacaoEstoqueRequest extends FormRequest
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
     * Regras de validação para atualização de localização de estoque.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        $id = $this->route('localizacoes_estoque');

        return [
            'estoque_id'   => 'required|exists:estoque,id|unique:localizacoes_estoque,estoque_id,' . $id,
            'corredor'     => 'nullable|string|max:10',
            'prateleira'   => 'nullable|string|max:10',
            'coluna'       => 'nullable|string|max:10',
            'nivel'        => 'nullable|string|max:10',
            'observacoes'  => 'nullable|string',
        ];
    }
}
