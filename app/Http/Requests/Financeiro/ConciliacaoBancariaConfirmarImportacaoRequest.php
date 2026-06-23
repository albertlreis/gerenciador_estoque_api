<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ConciliacaoBancariaConfirmarImportacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transacao_ids' => ['nullable', 'array'],
            'transacao_ids.*' => ['integer', 'min:1'],
            'forma_pagamento' => ['nullable', 'string', 'max:50'],
        ];
    }
}
