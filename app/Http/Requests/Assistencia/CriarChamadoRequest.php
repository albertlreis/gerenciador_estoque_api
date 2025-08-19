<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CriarChamadoRequest extends FormRequest
{
    public function authorize(): bool { return true; } // use Policies se necessário

    public function rules(): array
    {
        return [
            'origem_tipo' => ['required', Rule::in(['pedido','consignacao','estoque'])],
            'origem_id' => ['nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'fornecedor_id' => ['nullable', 'integer', 'exists:fornecedores,id'],
            'assistencia_id' => ['nullable', 'integer', 'exists:assistencias,id'],
            'prioridade' => ['nullable', Rule::in(['baixa','media','alta','critica'])],
            'canal_abertura' => ['nullable', 'string', 'max:30'],
            'observacoes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
