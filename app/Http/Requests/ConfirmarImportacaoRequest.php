<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarImportacaoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nota' => ['required', 'array'],
            'nota.numero' => ['required', 'string'],
            'deposito_id' => ['required', 'integer', 'exists:depositos,id'],
            'produtos' => ['required', 'array', 'min:1'],
            'produtos.*.descricao_xml' => ['required', 'string'],
            'produtos.*.referencia' => ['nullable', 'string'],
            'produtos.*.id_categoria' => ['nullable', 'integer', 'exists:categorias,id'],
            'produtos.*.variacao_id_manual' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'produtos.*.variacao_id' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'produtos.*.quantidade' => ['required', 'numeric', 'min:0.0001'],
            'produtos.*.custo_unitario' => ['required', 'numeric', 'min:0'],
            'produtos.*.preco' => ['nullable', 'numeric', 'min:0'],
            'produtos.*.descricao_final' => ['nullable', 'string', 'max:255'],
            'produtos.*.atributos' => ['array'],
            'produtos.*.atributos.*.atributo' => ['required_with:produtos.*.atributos','string','max:80'],
            'produtos.*.atributos.*.valor' => ['required_with:produtos.*.atributos','string','max:120'],
            'produtos.*.pedido_id' => ['nullable','integer','exists:pedidos,id'],
            'data_entrada' => ['nullable', 'date'],
        ];
    }
}
