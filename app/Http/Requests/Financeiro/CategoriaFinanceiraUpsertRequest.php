<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class CategoriaFinanceiraUpsertRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => $this->input('nome') ? trim((string)$this->input('nome')) : null,
            'slug' => $this->input('slug') ? trim((string)$this->input('slug')) : null,
            'tipo' => $this->input('tipo') ? strtolower(trim((string)$this->input('tipo'))) : null,
            'ordem' => $this->input('ordem') !== null && $this->input('ordem') !== '' ? (int)$this->input('ordem') : null,
            'ativo' => $this->toBoolOrNull($this->input('ativo')) ?? true,
            'padrao' => $this->toBoolOrNull($this->input('padrao')) ?? false,
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
            'nome' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', 'in:receita,despesa'],
            'categoria_pai_id' => ['nullable', 'integer', 'exists:categorias_financeiras,id'],
            'ordem' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
            'padrao' => ['nullable', 'boolean'],
            'meta_json' => ['nullable', 'array'],
        ];
    }
}
