<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao'      => ['required', 'string', 'max:255'],
            'tipo'           => ['required', 'in:receita,despesa'],
            'status'         => ['nullable', 'in:pendente,pago,cancelado'],

            'categoria_id'   => ['nullable', 'integer', 'min:1'],
            'conta_id'       => ['nullable', 'integer', 'min:1'],

            'valor'          => ['required', 'numeric', 'min:0.01'],

            'data_vencimento'=> ['required', 'date'],
            'data_pagamento' => ['nullable', 'date'],

            'competencia'    => ['nullable', 'date'],
            'observacoes'    => ['nullable', 'string'],

            'referencia_type'=> ['nullable', 'string', 'max:120'],
            'referencia_id'  => ['nullable', 'integer', 'min:1'],
        ];
    }
}
