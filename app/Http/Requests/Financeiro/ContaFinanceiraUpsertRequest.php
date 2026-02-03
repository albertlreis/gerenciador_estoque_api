<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ContaFinanceiraUpsertRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => $this->input('nome') ? trim((string)$this->input('nome')) : null,
            'slug' => $this->input('slug') ? trim((string)$this->input('slug')) : null,
            'tipo' => $this->input('tipo') ? strtolower(trim((string)$this->input('tipo'))) : null,
            'moeda' => $this->input('moeda') ? strtoupper(trim((string)$this->input('moeda'))) : 'BRL',
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
            'tipo' => ['required', 'string', 'max:30'],
            'moeda' => ['required', 'string', 'max:10'],
            'ativo' => ['nullable', 'boolean'],
            'padrao' => ['nullable', 'boolean'],
            'saldo_inicial' => ['nullable', 'numeric'],
            'banco_nome' => ['nullable', 'string', 'max:80'],
            'banco_codigo' => ['nullable', 'string', 'max:20'],
            'agencia' => ['nullable', 'string', 'max:20'],
            'agencia_dv' => ['nullable', 'string', 'max:5'],
            'conta' => ['nullable', 'string', 'max:20'],
            'conta_dv' => ['nullable', 'string', 'max:5'],
            'titular_nome' => ['nullable', 'string', 'max:120'],
            'titular_documento' => ['nullable', 'string', 'max:30'],
            'chave_pix' => ['nullable', 'string', 'max:120'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'meta_json' => ['nullable', 'array'],
        ];
    }
}
