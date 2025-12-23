<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class CategoriaFinanceiraIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ativo' => $this->toBoolOrNull($this->input('ativo')),
            'tree'  => $this->toBoolOrNull($this->input('tree')),
        ]);
    }

    private function toBoolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') return null;

        // aceita true/false, "true"/"false", 1/0, "1"/"0", "on"/"off", etc
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE); // bool|null
    }

    public function rules(): array
    {
        return [
            'q'      => ['nullable', 'string', 'max:255'],
            'tipo'   => ['nullable', 'in:receita,despesa'],
            'ativo'  => ['nullable', 'boolean'],
            'tree'   => ['nullable', 'boolean'],
        ];
    }
}
