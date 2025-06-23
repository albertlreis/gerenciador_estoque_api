<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os dados enviados para adicionar ou atualizar um item no carrinho.
 */
class StoreCarrinhoItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_variacao'    => 'required|exists:produto_variacoes,id',
            'quantidade'     => 'required|integer|min:1',
            'preco_unitario' => 'required|numeric|min:0',
            'outlet_id' => 'nullable|exists:produto_variacao_outlets,id',
        ];
    }

    public function messages(): array
    {
        return [
            'id_variacao.required' => 'A variação do produto é obrigatória.',
            'quantidade.min'       => 'A quantidade deve ser maior que zero.',
        ];
    }
}
