<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocalizacaoEstoqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'area' => ['nullable', 'string', 'max:80'],
            'corredor' => ['nullable', 'string', 'max:80'],
            'setor' => ['nullable', 'string', 'max:80'],
            'coluna' => ['nullable', 'string', 'max:80'],
            'nivel' => ['nullable', 'string', 'max:80'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

}
