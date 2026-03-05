<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class FormaPagamentoUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => $this->input('nome') ? trim((string) $this->input('nome')) : null,
            'slug' => $this->input('slug') ? trim((string) $this->input('slug')) : null,
            'ativo' => $this->toBoolOrNull($this->input('ativo')) ?? true,
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:50'],
            'slug' => ['nullable', 'string', 'max:60'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

    private function toBoolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
