<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida o registro de uma entrega (parcial/total) para um item.
 */
class PedidoFabricaEntregaParcialRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantidade' => ['required', 'integer', 'min:1'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'deposito_id' => ['nullable', 'integer', 'exists:depositos,id'],
        ];
    }
}
