<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ContaPagarRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fornecedor_id' => ['nullable','integer','exists:fornecedores,id'],
            'descricao' => ['required','string','max:180'],
            'numero_documento' => ['nullable','string','max:80'],
            'data_emissao' => ['nullable','date'],
            'data_vencimento' => ['required','date'],
            'valor_bruto' => ['required','numeric','min:0'],
            'desconto' => ['nullable','numeric','min:0'],
            'juros' => ['nullable','numeric','min:0'],
            'multa' => ['nullable','numeric','min:0'],
            'status' => ['required','in:ABERTA,PARCIAL,PAGA,CANCELADA'],
            'forma_pagamento' => ['nullable','in:PIX,BOLETO,TED,DINHEIRO,CARTAO'],
            'centro_custo' => ['nullable','string','max:60'],
            'categoria' => ['nullable','string','max:60'],
            'observacoes' => ['nullable','string'],
        ];
    }
}
