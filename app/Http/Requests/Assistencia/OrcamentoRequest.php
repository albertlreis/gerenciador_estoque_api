<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class OrcamentoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'valor_orcado' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
