<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ContaPagarPagamentoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'data_pagamento' => ['required','date'],
            'valor' => ['required','numeric','gt:0'],
            'forma_pagamento' => ['nullable','in:PIX,BOLETO,TED,DINHEIRO,CARTAO'],
            'observacoes' => ['nullable','string'],
            'comprovante' => ['nullable','file','mimes:pdf,jpg,jpeg,png','max:4096'],
        ];
    }
}
