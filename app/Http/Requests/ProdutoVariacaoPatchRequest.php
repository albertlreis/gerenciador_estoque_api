<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProdutoVariacaoPatchRequest extends FormRequest
{
    private const CAMPOS_PERMITIDOS = [
        'referencia',
        'nome',
        'preco',
        'custo',
        'codigo_barras',
        'audit',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'referencia' => ['sometimes', 'required', 'string', 'max:255'],
            'nome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preco' => ['sometimes', 'required', 'numeric', 'min:0'],
            'custo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'codigo_barras' => ['sometimes', 'nullable', 'string', 'max:255'],
            'audit' => ['sometimes', 'array'],
            'audit.label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'audit.motivo' => ['sometimes', 'nullable', 'string', 'max:500'],
            'audit.origin' => ['sometimes', 'nullable', 'string', 'max:60'],
            'audit.metadata' => ['sometimes', 'array'],
            'audit.metadata.carrinho_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $input = $this->all();
            $camposNaoPermitidos = array_diff(array_keys($input), self::CAMPOS_PERMITIDOS);

            if (!empty($camposNaoPermitidos)) {
                $validator->errors()->add(
                    'payload',
                    'Campos não permitidos: ' . implode(', ', $camposNaoPermitidos)
                );
            }

            if (array_key_exists('preco', $input)) {
                $motivo = trim((string) data_get($input, 'audit.motivo', ''));
                if ($motivo === '') {
                    $validator->errors()->add(
                        'audit.motivo',
                        'O motivo é obrigatório ao alterar preço.'
                    );
                }
            }

            $camposAtualizaveis = ['referencia', 'nome', 'preco', 'custo', 'codigo_barras'];
            $payloadComCampos = array_intersect_key($input, array_flip($camposAtualizaveis));
            if (empty($payloadComCampos)) {
                $validator->errors()->add(
                    'payload',
                    'Informe ao menos um campo atualizável.'
                );
            }
        });
    }
}

