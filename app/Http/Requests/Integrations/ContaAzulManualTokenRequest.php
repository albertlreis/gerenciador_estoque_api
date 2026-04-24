<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class ContaAzulManualTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'access_token' => $this->filled('access_token') ? trim((string) $this->input('access_token')) : null,
            'refresh_token' => $this->filled('refresh_token') ? trim((string) $this->input('refresh_token')) : null,
            'nome_externo' => $this->filled('nome_externo') ? trim((string) $this->input('nome_externo')) : null,
            'observacoes' => $this->filled('observacoes') ? trim((string) $this->input('observacoes')) : null,
            'expires_in' => $this->input('expires_in', 3600),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loja_id' => ['nullable', 'integer', 'min:1'],
            'ambiente' => ['required', 'in:homologacao,producao'],
            'access_token' => ['required', 'string', 'min:10'],
            'refresh_token' => ['nullable', 'string', 'min:10', 'required_if:ambiente,producao'],
            'expires_in' => ['nullable', 'integer', 'min:60'],
            'nome_externo' => ['nullable', 'string', 'max:190'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
