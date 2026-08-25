<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PedidoIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
            'dashboard_filtro' => ['sometimes', 'string', Rule::in([
                'abertos', 'atrasados', 'vencem_7_dias', 'prioritarios', 'periodo',
            ])],
            'data_inicio' => ['sometimes', 'date_format:Y-m-d'],
            'data_fim' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'deposito_id' => ['sometimes', 'integer', 'min:1'],
            'pedido_id' => ['sometimes', 'integer', 'min:1'],
            'entrega_pendente' => ['sometimes', 'boolean'],
        ];
    }
}
