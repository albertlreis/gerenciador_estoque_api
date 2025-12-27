<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ContaFinanceiraIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q'     => $this->input('q') ? trim((string)$this->input('q')) : null,
            'tipo'  => $this->input('tipo') ? strtolower((string)$this->input('tipo')) : null,
            'ativo' => $this->toBoolOrNull($this->input('ativo')),
        ]);
    }

    private function toBoolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') return null;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function rules(): array
    {
        return [
            'q'     => ['nullable', 'string', 'max:255'],
            'tipo'  => ['nullable', 'string', 'max:30'], // mantém flexível
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
