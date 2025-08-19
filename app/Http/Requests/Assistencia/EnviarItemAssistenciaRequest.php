<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

class EnviarItemAssistenciaRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'assistencia_id' => ['required', 'integer', 'exists:assistencias,id'],
            'deposito_assistencia_id' => ['required', 'integer', 'exists:depositos,id'],
            'rastreio_envio' => ['nullable', 'string', 'max:150'],
            'data_envio' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
