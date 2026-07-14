<?php

namespace App\Http\Requests;

use App\Helpers\StringHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmarImportacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nota' => ['required', 'array'],
            'nota.numero' => ['required', 'string'],
            'nota.data_emissao' => ['nullable', 'string'],
            'nota.fornecedor_cnpj' => ['nullable', 'string'],
            'nota.fornecedor_nome' => ['nullable', 'string'],
            'deposito_id' => ['required', 'integer', 'exists:depositos,id'],
            'token_xml' => ['required', 'string'],
            'produtos' => ['required', 'array', 'min:1'],
            'produtos.*' => ['required', 'array'],
            'produtos.*.descricao_xml' => ['required', 'string'],
            'produtos.*.referencia' => ['nullable', 'string', 'max:100'],
            'produtos.*.unidade' => ['nullable', 'string', 'max:20'],
            'produtos.*.id_categoria' => ['nullable', 'integer', 'exists:categorias,id'],
            'produtos.*.variacao_id_manual' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'produtos.*.variacao_id' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'produtos.*.quantidade' => ['required', 'integer', 'min:1'],
            'produtos.*.custo_unitario' => ['required', 'numeric', 'min:0'],
            'produtos.*.valor_total' => ['nullable', 'numeric', 'min:0'],
            'produtos.*.preco' => ['nullable', 'numeric', 'min:0'],
            'produtos.*.custo_cadastrado' => ['nullable', 'numeric', 'min:0'],
            'produtos.*.descricao_final' => ['nullable', 'string', 'max:255'],
            'produtos.*.observacao' => ['nullable', 'string'],
            'produtos.*.atributos' => ['array'],
            'produtos.*.atributos.*.atributo' => ['required_with:produtos.*.atributos', 'string', 'max:100'],
            'produtos.*.atributos.*.valor' => ['required_with:produtos.*.atributos', 'string', 'max:100'],
            'produtos.*.pedido_id' => ['nullable', 'integer', 'exists:pedidos,id'],
            'data_entrada' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('produtos', []) as $index => $produto) {
                if (! is_array($produto)) {
                    continue;
                }

                $variacaoId = $produto['variacao_id'] ?? null;
                $variacaoManualId = $produto['variacao_id_manual'] ?? null;
                $produtoNovo = blank($variacaoId) && blank($variacaoManualId);
                $prefixo = "produtos.{$index}";

                if ($produtoNovo) {
                    if (blank($produto['id_categoria'] ?? null)) {
                        $validator->errors()->add("{$prefixo}.id_categoria", 'Selecione uma categoria para cadastrar o produto novo.');
                    }

                    if (blank($produto['referencia'] ?? null)) {
                        $validator->errors()->add("{$prefixo}.referencia", 'Informe a referência para cadastrar o produto novo.');
                    }

                    if (blank($produto['descricao_final'] ?? null)) {
                        $validator->errors()->add("{$prefixo}.descricao_final", 'Informe a descrição final para cadastrar o produto novo.');
                    }
                }

                $atributos = is_array($produto['atributos'] ?? null) ? $produto['atributos'] : [];
                $paresNormalizados = [];

                foreach ($atributos as $attrIndex => $atributo) {
                    $nome = StringHelper::normalizarAtributo((string) ($atributo['atributo'] ?? ''));
                    $valor = StringHelper::normalizarValorAtributo((string) ($atributo['valor'] ?? ''));
                    if ($nome === '' || $valor === '') {
                        continue;
                    }

                    $par = $nome."\0".$valor;

                    if (isset($paresNormalizados[$par])) {
                        $validator->errors()->add(
                            "{$prefixo}.atributos.{$attrIndex}.atributo",
                            'Remova o par de atributo e valor duplicado no mesmo produto.'
                        );
                    }

                    $paresNormalizados[$par] = true;
                }
            }
        });
    }
}
