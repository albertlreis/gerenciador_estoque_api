<?php

namespace App\Http\Requests\Financeiro;

use App\Models\FormaPagamento;
use Illuminate\Foundation\Http\FormRequest;

class ContaPagarPagamentoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'data_pagamento' => ['required','date'],
            'valor' => ['required','numeric','gt:0'],
            'forma_pagamento' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $legacy = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];
                    $normalized = strtoupper((string) $value);

                    if (in_array($normalized, $legacy, true)) {
                        return;
                    }

                    if (FormaPagamento::query()->where('nome', (string) $value)->exists()) {
                        return;
                    }

                    $fail('A forma de pagamento informada é inválida.');
                },
            ],
            'observacoes' => ['nullable','string'],
            'comprovante' => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:4096'],
            'conta_financeira_id' => ['required','integer','exists:contas_financeiras,id'],
        ];
    }
}
