<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDespesaRecorrenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'direcao' => ['nullable', Rule::in(['PAGAR','RECEBER'])],
            'fornecedor_id' => ['nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'descricao' => ['required', 'string', 'max:180'],
            'numero_documento' => ['nullable', 'string', 'max:80'],
            'centro_custo_id' => ['nullable', 'integer', 'exists:centros_custo,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias_financeiras,id'],

            'valor_bruto' => ['nullable', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'juros' => ['nullable', 'numeric', 'min:0'],
            'multa' => ['nullable', 'numeric', 'min:0'],

            'tipo' => ['required', Rule::in(['FIXA','VARIAVEL'])],
            'frequencia' => ['required', Rule::in(['DIARIA','SEMANAL','MENSAL','ANUAL','PERSONALIZADA'])],
            'intervalo' => ['nullable', 'integer', 'min:1', 'max:365'],
            'dia_vencimento' => ['nullable', 'integer', 'min:1', 'max:31'],
            'mes_vencimento' => ['nullable', 'integer', 'min:1', 'max:12'],

            'data_inicio' => ['required', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],

            'criar_conta_pagar_auto' => ['nullable', 'boolean'],
            'ocorrencias_total' => ['nullable', 'integer', 'min:1', 'max:366'],
            'dias_antecedencia' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['nullable', Rule::in(['ATIVA','PAUSADA','CANCELADA'])],

            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        $data['intervalo'] = (int)($data['intervalo'] ?? 1);
        $data['direcao'] = $data['direcao'] ?? 'PAGAR';
        $data['status'] = $data['status'] ?? 'ATIVA';
        return $data;
    }
}
