<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParceiroUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $documento = $this->input('documento');

        if ($documento !== null) {
            $this->merge([
                'documento' => is_string($documento) ? preg_replace('/\D+/', '', $documento) : $documento,
            ]);
        }
    }

    public function rules(): array
    {
        $parceiroId = (int) $this->route('parceiro', $this->route('id'));

        return [
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'tipo' => ['sometimes', 'required', 'string', 'max:50'],
            'documento' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('parceiros', 'documento')->ignore($parceiroId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:100'],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'data_nascimento' => ['sometimes', 'nullable', 'date'],
            'consultor_nome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nivel_fidelidade' => ['sometimes', 'nullable', 'string', 'max:50'],
            'endereco' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'integer', 'in:0,1'],
            'observacoes' => ['sometimes', 'nullable', 'string'],

            'contatos' => ['sometimes', 'array'],
            'contatos.*.tipo' => ['required_with:contatos', 'string', Rule::in(['email', 'telefone', 'outro'])],
            'contatos.*.valor' => ['required_with:contatos', 'string', 'max:255'],
            'contatos.*.valor_e164' => ['nullable', 'string', 'max:20'],
            'contatos.*.rotulo' => ['nullable', 'string', 'max:50'],
            'contatos.*.principal' => ['nullable', 'boolean'],
            'contatos.*.observacoes' => ['nullable', 'string'],
        ];
    }
}
