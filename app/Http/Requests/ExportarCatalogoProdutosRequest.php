<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class ExportarCatalogoProdutosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AuthHelper::hasPermissao('produtos.catalogo')
            || AuthHelper::hasPermissao('produtos.gerenciar');
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:selected,filtered'],
            'variation_ids' => ['nullable', 'array', 'min:1', 'max:2000'],
            'variation_ids.*' => ['integer', 'distinct', 'exists:produto_variacoes,id'],
            'filters' => ['nullable', 'array'],
            'filters.q' => ['nullable', 'string', 'max:255'],
            'filters.nome' => ['nullable', 'string', 'max:255'],
            'filters.id_categoria' => ['nullable', 'array'],
            'filters.id_categoria.*' => ['integer', 'distinct', 'exists:categorias,id'],
            'filters.ativo' => ['nullable', 'boolean'],
            'filters.is_outlet' => ['nullable', 'boolean'],
            'filters.estoque_status' => ['nullable', 'in:com_estoque,sem_estoque'],
            'filters.deposito_id' => ['nullable', 'integer', 'exists:depositos,id'],
            'filters.atributos' => ['nullable', 'array'],
            'filters.atributos.*' => ['nullable', 'array'],
            'filters.atributos.*.*' => ['string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('mode') === 'selected' && empty($this->input('variation_ids'))) {
                $validator->errors()->add('variation_ids', 'Selecione ao menos um grupo do catalogo.');
            }

            $allowedFilters = [
                'q',
                'nome',
                'id_categoria',
                'ativo',
                'is_outlet',
                'estoque_status',
                'deposito_id',
                'atributos',
            ];
            $unknownFilters = array_diff(
                array_keys((array) $this->input('filters', [])),
                $allowedFilters
            );

            if ($unknownFilters !== []) {
                $validator->errors()->add(
                    'filters',
                    'Filtros desconhecidos: ' . implode(', ', $unknownFilters) . '.'
                );
            }
        });
    }
}
