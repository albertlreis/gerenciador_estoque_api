<?php

namespace App\Http\Requests\Relatorios;

use App\Enums\AssistenciaStatus;
use App\Enums\CustoResponsavel;
use App\Enums\LocalReparo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistenciaRelatorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statusValues = array_map(fn ($e) => $e->value, AssistenciaStatus::cases());
        $localValues  = array_map(fn ($e) => $e->value, LocalReparo::cases());
        $custoValues  = array_map(fn ($e) => $e->value, CustoResponsavel::cases());

        return [
            'formato' => ['nullable', Rule::in(['pdf', 'excel'])],

            'status' => ['nullable', Rule::in($statusValues)],

            'abertura_inicio' => ['nullable', 'date_format:Y-m-d'],
            'abertura_fim'    => ['nullable', 'date_format:Y-m-d'],

            'conclusao_inicio' => ['nullable', 'date_format:Y-m-d'],
            'conclusao_fim'    => ['nullable', 'date_format:Y-m-d'],

            'locais_reparo'   => ['nullable', 'array'],
            'locais_reparo.*' => ['nullable', Rule::in($localValues)],

            // front manda custo_resp (cliente|loja)
            'custo_resp' => ['nullable', Rule::in($custoValues)],
        ];
    }

    public function messages(): array
    {
        return [
            'formato.in' => 'Formato inválido. Use pdf ou excel.',
        ];
    }
}
