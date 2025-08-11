<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProdutoVariacaoOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo_id' => 'nullable|exists:outlet_motivos,id',
            'quantidade' => 'required|integer|min:1',
            'formas_pagamento' => 'required|array|min:1',
            'formas_pagamento.*.forma_pagamento_id' => 'nullable|exists:outlet_formas_pagamento,id',
            'formas_pagamento.*.percentual_desconto'=> 'required|numeric|min:0|max:100',
            'formas_pagamento.*.max_parcelas'       => 'nullable|integer|min:1|max:36',
        ];
    }
}
