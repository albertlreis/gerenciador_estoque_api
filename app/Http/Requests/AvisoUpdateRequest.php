<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvisoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('ativo')) {
            $this->merge([
                'ativo' => $this->boolean('ativo'),
            ]);
        }

        if ($this->has('data_inicio')) {
            $this->merge([
                'data_inicio' => $this->filled('data_inicio') ? $this->input('data_inicio') : null,
            ]);
        }

        if ($this->has('data_fim')) {
            $this->merge([
                'data_fim' => $this->filled('data_fim') ? $this->input('data_fim') : null,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'required', 'string', 'max:255'],
            'conteudo' => ['sometimes', 'required', 'string'],
            'ativo' => ['sometimes', 'boolean'],
            'data_inicio' => ['sometimes', 'nullable', 'date'],
            'data_fim' => ['sometimes', 'nullable', 'date', 'after_or_equal:data_inicio'],
        ];
    }
}
