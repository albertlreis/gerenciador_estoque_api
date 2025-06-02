<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\PedidoStatus;
use Illuminate\Validation\Rules\Enum;

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
            'status' => ['required', new Enum(PedidoStatus::class)],
            'observacoes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
