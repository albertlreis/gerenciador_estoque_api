<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class AdicionarItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'variacao_id'        => ['nullable','integer','exists:produto_variacoes,id','required_without:pedido_item_id'],
            'defeito_id'         => ['nullable','integer','exists:assistencia_defeitos,id'],
            'deposito_origem_id' => ['nullable','integer','exists:depositos,id'],
            'pedido_item_id'     => ['nullable','integer','exists:pedido_itens,id','required_without:variacao_id'],
            'consignacao_id'     => ['nullable','integer','exists:consignacoes,id'],
            'observacoes'        => ['nullable','string','max:5000'],
            'nota_numero'        => ['nullable','string','max:60'],
            'prazo_finalizacao'  => ['nullable','date_format:Y-m-d'],
        ];
    }

    public function attributes(): array
    {
        return [
            'variacao_id'        => 'variação',
            'defeito_id'         => 'defeito',
            'deposito_origem_id' => 'depósito de origem',
            'pedido_item_id'     => 'item do pedido',
            'nota_numero'        => 'número da nota',
            'prazo_finalizacao'  => 'prazo de finalização',
        ];
    }
}
