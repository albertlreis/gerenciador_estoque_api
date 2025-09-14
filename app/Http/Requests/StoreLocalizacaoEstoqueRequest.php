<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação para criação de localização.
 *
 * Regras de negócio:
 * - Exclusividade: OU área_id OU (setor/coluna/nivel parcial). Não aceita ambos.
 * - Localização física pode ser parcial (qualquer combinação).
 * - Exige pelo menos uma das duas opções (área OU algum campo físico informado).
 */
class StoreLocalizacaoEstoqueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estoque_id'  => 'required|exists:estoque,id|unique:localizacoes_estoque,estoque_id',
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

            // Exclusividade: não pode informar área e localização (mesmo parcial) ao mesmo tempo
            if ($area && $temLocalParcial) {
                $v->errors()->add('area_id', 'Não pode informar Área e Localização física simultaneamente.');
            }

            // Deve informar pelo menos uma opção
            if (!$area && !$temLocalParcial) {
                $v->errors()->add('area_id', 'Informe uma Área OU pelo menos um dos campos: Setor, Coluna ou Nível.');
            }
        });
    }
}
