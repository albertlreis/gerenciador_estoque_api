<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-type Filtros array{
 *   status?: 'pendente'|'enviado'|'parcial'|'entregue'|'cancelado'|null
 * }
 */
class PedidoFabricaIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pendente,enviado,parcial,entregue,cancelado'],
        ];
    }
}
