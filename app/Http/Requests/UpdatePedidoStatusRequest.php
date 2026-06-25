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
            'status' => ['required', 'string', 'max:50'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'data_status' => ['nullable', 'date_format:Y-m-d'],
            'data_prevista' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
