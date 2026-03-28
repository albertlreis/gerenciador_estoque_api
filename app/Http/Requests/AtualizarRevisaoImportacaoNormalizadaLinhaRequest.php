<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class AtualizarRevisaoImportacaoNormalizadaLinhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AuthHelper::podeImportarEstoquePlanilhaDev();
    }

    public function rules(): array
    {
        return [
            'status_revisao' => ['required', 'string', 'in:pendente_revisao,aprovado,rejeitado'],
            'decisao' => ['required', 'string', 'max:100'],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'detalhes' => ['nullable', 'array'],
            'produto_id_vinculado' => ['nullable', 'integer', 'exists:produtos,id'],
            'variacao_id_vinculada' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
        ];
    }
}
