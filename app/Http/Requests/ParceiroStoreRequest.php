<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParceiroStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $documento = $this->input('documento');

        $this->merge([
            'documento' => is_string($documento) ? preg_replace('/\D+/', '', $documento) : $documento,
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:50'],
            'documento' => ['required', 'string', 'max:50', Rule::unique('parceiros', 'documento')],
            'email' => ['nullable', 'email', 'max:100'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['nullable', 'date'],
            'consultor_nome' => ['nullable', 'string', 'max:255'],
            'nivel_fidelidade' => ['nullable', 'string', 'max:50'],
            'endereco' => ['nullable', 'string'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'observacoes' => ['nullable', 'string'],

            'contatos' => ['nullable', 'array'],
            'contatos.*.tipo' => ['required_with:contatos', 'string', Rule::in(['email', 'telefone', 'outro'])],
            'contatos.*.valor' => ['required_with:contatos', 'string', 'max:255'],
            'contatos.*.valor_e164' => ['nullable', 'string', 'max:20'],
            'contatos.*.rotulo' => ['nullable', 'string', 'max:50'],
            'contatos.*.principal' => ['nullable', 'boolean'],
            'contatos.*.observacoes' => ['nullable', 'string'],
        ];
    }
}
