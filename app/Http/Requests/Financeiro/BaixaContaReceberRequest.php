<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class BaixaContaReceberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data_pagamento' => ['required', 'date'],
            'valor_pago' => ['required', 'numeric', 'min:0.01'],
            'forma_pagamento' => ['required', 'string', 'max:100'],
            'comprovante' => ['nullable', 'string', 'max:255'],
        ];
    }
}
