<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CriarChamadoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'origem_tipo'       => ['required', Rule::in(['pedido','consignacao','estoque'])],
            'origem_id'         => ['nullable','integer'],
            'pedido_id'         => ['nullable','integer','exists:pedidos,id'],
            'assistencia_id'    => ['nullable','integer','exists:assistencias,id'],
            'prioridade'        => ['nullable', Rule::in(['baixa','media','alta','critica'])],
            'observacoes'       => ['nullable','string','max:5000'],
            'local_reparo'      => ['nullable', Rule::in(['deposito','fabrica','cliente'])],
            'custo_responsavel' => ['nullable', Rule::in(['cliente','loja'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'origem_tipo'       => 'origem',
            'origem_id'         => 'ID da origem',
            'pedido_id'         => 'pedido',
            'assistencia_id'    => 'assistência',
            'prioridade'        => 'prioridade',
            'observacoes'       => 'observações',
            'local_reparo'      => 'local do reparo',
            'custo_responsavel' => 'responsável pelo custo do reparo',
        ];
    }
}
