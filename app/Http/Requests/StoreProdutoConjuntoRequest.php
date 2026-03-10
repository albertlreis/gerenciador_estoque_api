<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProdutoConjuntoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'ativo' => ['sometimes', 'boolean'],
            'preco_modo' => ['required', Rule::in(['soma', 'individual', 'apartir'])],
            'principal_variacao_id' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'itens' => ['sometimes', 'array'],
            'itens.*.produto_variacao_id' => ['required', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.label' => ['nullable', 'string', 'max:80'],
            'itens.*.ordem' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $modo = (string) $this->input('preco_modo');
            $principalId = $this->input('principal_variacao_id');
            $itemIds = collect($this->input('itens', []))
                ->pluck('produto_variacao_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($modo === 'apartir' && empty($principalId)) {
                $validator->errors()->add('principal_variacao_id', 'O item principal é obrigatório quando o modo de preço é "apartir".');
            }

            if (!empty($principalId) && $itemIds->isNotEmpty() && !$itemIds->contains((int) $principalId)) {
                $validator->errors()->add('principal_variacao_id', 'A variação principal precisa fazer parte dos itens do conjunto.');
            }
        });
    }
}
