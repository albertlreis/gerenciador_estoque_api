<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Atualização de status manual + histórico.
 */
class PedidoFabricaUpdateStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'     => ['required', 'in:pendente,enviado,parcial,entregue,cancelado'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
