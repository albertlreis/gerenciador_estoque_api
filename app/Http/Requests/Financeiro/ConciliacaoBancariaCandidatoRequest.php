<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConciliacaoBancariaCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidato_tipo' => [
                'nullable',
                'string',
                Rule::in([
                    'conta_pagar',
                    'conta_receber',
                    'conta_pagar_pagamento',
                    'conta_receber_pagamento',
                    'lancamento_financeiro',
                    'fornecedor_provavel',
                ]),
            ],
            'candidato_id' => ['nullable', 'integer', 'min:1'],
            'forma_pagamento' => ['nullable', 'string', 'max:50'],
        ];
    }
}
