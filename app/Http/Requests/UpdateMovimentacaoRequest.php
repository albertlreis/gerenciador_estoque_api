<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação para atualização de movimentações de estoque.
 */
class UpdateMovimentacaoRequest extends FormRequest
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
            'tipo'                => 'sometimes|required|string|max:50',
            'quantidade'          => 'sometimes|required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ];
    }
}
