<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ConciliacaoBancariaConfirmarTransacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'forma_pagamento' => ['nullable', 'string', 'max:50'],
        ];
    }
}
