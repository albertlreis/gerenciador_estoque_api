<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação para criação de movimentações de estoque.
 */
class StoreMovimentacaoRequest extends FormRequest
{
    /**
     * Verifica se o usuário está autorizado.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de validação.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => 'required|string|max:50',
            'quantidade'          => 'required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ];
    }
}
