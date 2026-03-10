<?php

namespace App\Http\Requests;

use App\Models\ProdutoConjunto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProdutoConjuntoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'descricao' => ['sometimes', 'nullable', 'string'],
            'ativo' => ['sometimes', 'boolean'],
            'preco_modo' => ['sometimes', 'required', Rule::in(['soma', 'individual', 'apartir'])],
            'principal_variacao_id' => ['sometimes', 'nullable', 'integer', 'exists:produto_variacoes,id'],
            'itens' => ['sometimes', 'array'],
            'itens.*.produto_variacao_id' => ['required_with:itens', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.label' => ['nullable', 'string', 'max:80'],
            'itens.*.ordem' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var ProdutoConjunto|null $conjunto */
            $conjunto = $this->route('produtoConjunto');
            $modo = (string) ($this->input('preco_modo', $conjunto?->preco_modo));
            $dados = $this->all();
            $principalId = array_key_exists('principal_variacao_id', $dados)
                ? $this->input('principal_variacao_id')
                : $conjunto?->principal_variacao_id;

            $itemIds = array_key_exists('itens', $dados)
                ? collect($this->input('itens', []))
                    ->pluck('produto_variacao_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                : ($conjunto?->itens?->pluck('produto_variacao_id')->map(fn ($id) => (int) $id)->values() ?? collect());

            if ($modo === 'apartir' && empty($principalId)) {
                $validator->errors()->add('principal_variacao_id', 'O item principal é obrigatório quando o modo de preço é "apartir".');
            }

            if (!empty($principalId) && $itemIds->isNotEmpty() && !$itemIds->contains((int) $principalId)) {
                $validator->errors()->add('principal_variacao_id', 'A variação principal precisa fazer parte dos itens do conjunto.');
            }
        });
    }
}
