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
            'motivo' => 'required|string|max:50',
            'quantidade' => 'required|integer|min:1',
            'percentual_desconto' => 'required|numeric|min:1|max:99.99',
        ];
    }
}
