<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContaReceberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'descricao' => $this->input('descricao') !== null ? trim((string)$this->input('descricao')) : null,
            'numero_documento' => $this->input('numero_documento') !== null ? trim((string)$this->input('numero_documento')) : null,
            'observacoes' => $this->input('observacoes') !== null ? trim((string)$this->input('observacoes')) : null,
            'forma_recebimento' => $this->input('forma_recebimento') !== null ? trim((string)$this->input('forma_recebimento')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes','string','max:255'],
            'numero_documento' => ['sometimes','string','max:255'],
            'data_emissao' => ['sometimes','date'],
            'data_vencimento' => ['sometimes','date'],
            'valor_bruto' => ['sometimes','numeric','min:0'],
            'desconto' => ['sometimes','numeric','min:0'],
            'juros' => ['sometimes','numeric','min:0'],
            'multa' => ['sometimes','numeric','min:0'],
            'valor_recebido' => ['sometimes','numeric','min:0'],
            'forma_recebimento' => ['sometimes','nullable','string','max:30'],
            'categoria_id' => ['sometimes','nullable','integer','exists:categorias_financeiras,id'],
            'centro_custo_id' => ['sometimes','nullable','integer','exists:centros_custo,id'],
            'observacoes' => ['nullable','string'],
            'status' => ['nullable','in:ABERTA,PARCIAL,PAGA,CANCELADA'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $emissao = $this->input('data_emissao');
            $venc = $this->input('data_vencimento');
            if ($emissao && $venc) {
                try {
                    if (strtotime($venc) < strtotime($emissao)) {
                        $v->errors()->add('data_vencimento', 'Data de vencimento não pode ser anterior à data de emissão.');
                    }
                } catch (\Throwable $e) {
                    // deixa o validator padrão lidar com formato inválido
                }
            }
        });
    }
}
