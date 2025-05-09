<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a atualização de status de um pedido.
 */
class UpdatePedidoStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:rascunho,confirmado,cancelado',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status inválido. Valores permitidos: rascunho, confirmado, cancelado.',
        ];
    }
}
