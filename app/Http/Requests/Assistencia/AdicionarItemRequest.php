<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class AdicionarItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'produto_id' => ['nullable', 'integer', 'exists:produtos,id'],
            'variacao_id' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'numero_serie' => ['nullable', 'string', 'max:120'],
            'lote' => ['nullable', 'string', 'max:120'],
            'defeito_id' => ['nullable', 'integer', 'exists:assistencia_defeitos,id'],
            'descricao_defeito_livre' => ['nullable', 'string', 'max:255'],
            'deposito_origem_id' => ['nullable', 'integer', 'exists:depositos,id'],
            'pedido_id' => ['nullable', 'integer', 'exists:pedidos,id'],
            'pedido_item_id' => ['nullable', 'integer', 'exists:pedido_itens,id'],
            'consignacao_id' => ['nullable', 'integer', 'exists:consignacoes,id'],
            'consignacao_item_id' => ['nullable', 'integer', 'exists:consignacao_itens,id'],
            'observacoes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
