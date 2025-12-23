<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao'      => ['sometimes', 'required', 'string', 'max:255'],
            'tipo'           => ['sometimes', 'required', 'in:receita,despesa'],
            'status'         => ['sometimes', 'required', 'in:pendente,pago,cancelado'],

            'categoria_id'   => ['sometimes', 'nullable', 'integer', 'min:1'],
            'conta_id'       => ['sometimes', 'nullable', 'integer', 'min:1'],

            'valor'          => ['sometimes', 'required', 'numeric', 'min:0.01'],

            'data_vencimento'=> ['sometimes', 'required', 'date'],
            'data_pagamento' => ['sometimes', 'nullable', 'date'],

            'competencia'    => ['sometimes', 'nullable', 'date'],
            'observacoes'    => ['sometimes', 'nullable', 'string'],

            'referencia_type'=> ['sometimes', 'nullable', 'string', 'max:120'],
            'referencia_id'  => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
