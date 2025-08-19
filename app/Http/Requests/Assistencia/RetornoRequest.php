<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class RetornoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'deposito_retorno_id' => ['required', 'integer', 'exists:depositos,id'],
            'rastreio_retorno' => ['nullable', 'string', 'max:150'],
            'data_retorno' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
