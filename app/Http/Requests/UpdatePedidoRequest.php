<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cliente' => ['sometimes', 'required', 'integer', 'exists:clientes,id'],
            'id_parceiro' => ['sometimes', 'nullable', 'integer', 'exists:parceiros,id'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'prazo_dias_uteis' => ['nullable', 'integer', 'min:0', 'max:365'],

            'itens' => ['required', 'array', 'min:1'],
            'itens.*.id' => ['nullable', 'integer', 'exists:pedido_itens,id'],
            'itens.*.id_variacao' => ['required', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
            'itens.*.preco_unitario' => ['nullable', 'numeric', 'min:0'],
            'itens.*.id_deposito' => ['nullable', 'integer', 'exists:depositos,id'],
        ];
    }
}
