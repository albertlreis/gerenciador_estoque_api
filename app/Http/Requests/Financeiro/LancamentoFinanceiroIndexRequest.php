<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // depois você pluga permissões/policies
    }

    public function rules(): array
    {
        return [
            'data_inicio' => ['nullable', 'date'],
            'data_fim'    => ['nullable', 'date', 'after_or_equal:data_inicio'],

            'status'      => ['nullable', 'in:pendente,pago,cancelado'],
            'atrasado'    => ['nullable', 'boolean'],

            'categoria_id'=> ['nullable', 'integer', 'min:1'],
            'conta_id'    => ['nullable', 'integer', 'min:1'],
            'tipo'        => ['nullable', 'in:receita,despesa'],

            'q'           => ['nullable', 'string', 'max:255'],

            'order_by'    => ['nullable', 'in:data_vencimento,data_pagamento,valor,created_at,id'],
            'order_dir'   => ['nullable', 'in:asc,desc'],

            'page'        => ['nullable', 'integer', 'min:1'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
