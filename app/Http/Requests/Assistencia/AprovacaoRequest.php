<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class AprovacaoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'aprovado' => ['required', 'boolean'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
