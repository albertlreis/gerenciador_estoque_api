<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação para atualização de localização.
 *
 * Regras iguais ao Store:
 * - Exclusividade entre área e localização física (mesmo parcial);
 * - Localização física aceita parcial;
 * - Exige pelo menos uma das opções.
 */
class UpdateLocalizacaoEstoqueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('localizacoes_estoque');

        return [
            'estoque_id'  => 'required|exists:estoque,id|unique:localizacoes_estoque,estoque_id,' . $id,
            'setor'       => 'nullable|string|max:10',
            'coluna'      => 'nullable|string|max:10',
            'nivel'       => 'nullable|string|max:10',
            'area_id'     => 'nullable|exists:areas_estoque,id',
            'observacoes' => 'nullable|string',
            'dimensoes'   => 'nullable|array',
            'dimensoes.*' => 'nullable|string|max:30',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $area   = $this->input('area_id');
            $setor  = trim((string)($this->input('setor')  ?? ''));
            $coluna = trim((string)($this->input('coluna') ?? ''));
            $nivel  = trim((string)($this->input('nivel')  ?? ''));

            $temLocalParcial = ($setor !== '' || $coluna !== '' || $nivel !== '');

            if ($area && $temLocalParcial) {
                $v->errors()->add('area_id', 'Não pode informar Área e Localização física simultaneamente.');
            }

            if (!$area && !$temLocalParcial) {
                $v->errors()->add('area_id', 'Informe uma Área OU pelo menos um dos campos: Setor, Coluna ou Nível.');
            }
        });
    }
}
