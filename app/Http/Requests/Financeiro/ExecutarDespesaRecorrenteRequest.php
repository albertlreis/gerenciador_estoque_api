<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ExecutarDespesaRecorrenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'competencia' => ['nullable', 'date'],      // ex: 2025-12-01
            'data_vencimento' => ['nullable', 'date'],  // se quiser forçar
            'valor_bruto' => ['nullable', 'numeric', 'min:0'],

            // overrides opcionais para conta_pagar
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'juros' => ['nullable', 'numeric', 'min:0'],
            'multa' => ['nullable', 'numeric', 'min:0'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'centro_custo' => ['nullable', 'string', 'max:60'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
