<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os dados enviados para finalizar um pedido.
 */
class StorePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cliente'  => 'required|exists:clientes,id',
            'id_parceiro' => 'nullable|exists:parceiros,id',
            'observacoes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'id_cliente.required' => 'O cliente é obrigatório para finalizar o pedido.',
        ];
    }
}
