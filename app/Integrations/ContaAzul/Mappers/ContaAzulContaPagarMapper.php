<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\ContaPagar;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ContaAzulContaPagarMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(ContaPagar $conta, ?int $lojaId = null): array
    {
        if ($conta->exists || $conta->fornecedor_id || $conta->relationLoaded('fornecedor')) {
            $conta->loadMissing(['fornecedor', 'categoria', 'centroCusto']);
        }

        $valor = (float) $conta->valor_liquido;
        $dataCompetencia = $this->date($conta->data_emissao) ?: $this->date($conta->data_vencimento);
        $dataVencimento = $this->date($conta->data_vencimento) ?: $dataCompetencia;
        $formaPagamento = $this->formaPagamento($conta->forma_pagamento);

        $idFornecedorExt = null;
        if ($conta->fornecedor_id) {
            $idFornecedorExt = ContaAzulMapeamento::idExternoPorLocal(
                ContaAzulEntityType::FORNECEDOR,
                (int) $conta->fornecedor_id,
                $lojaId
            );
        }

        $idCategoriaExt = $this->idExternoComFallbackMeta(
            ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            $conta->categoria_id ? (int) $conta->categoria_id : null,
            $conta->relationLoaded('categoria') ? $conta->getRelation('categoria') : null,
            $lojaId
        );

        if ($idCategoriaExt === null || $idCategoriaExt === '') {
            throw new ContaAzulException(
                'Exportacao de conta a pagar bloqueada: categoria financeira local sem mapeamento externo na Conta Azul.',
                'conta_azul_categoria_financeira_sem_mapeamento',
                [
                    'conta_pagar_id' => $conta->id,
                    'categoria_id' => $conta->categoria_id,
                    'loja_id' => $lojaId,
                ]
            );
        }

        $rateio = [
            'id_categoria' => $idCategoriaExt,
            'valor' => $valor,
        ];

        $idCentroCustoExt = $this->idExternoComFallbackMeta(
            ContaAzulEntityType::CENTRO_CUSTO,
            $conta->centro_custo_id ? (int) $conta->centro_custo_id : null,
            $conta->relationLoaded('centroCusto') ? $conta->getRelation('centroCusto') : null,
            $lojaId
        );
        if ($idCentroCustoExt !== null && $idCentroCustoExt !== '') {
            $rateio['rateio_centro_custo'] = [[
                'id_centro_custo' => $idCentroCustoExt,
                'valor' => $valor,
            ]];
        }

        return array_filter([
            'descricao' => $conta->descricao,
            'numero_documento' => $conta->numero_documento,
            'valor' => $valor,
            'competenceDate' => $dataCompetencia,
            'idFornecedor' => $idFornecedorExt,
            'rateio' => [$rateio],
            'condicao_pagamento' => [
                'tipo_pagamento' => $formaPagamento,
                'parcelas' => [[
                    'data_vencimento' => $dataVencimento,
                    'valor' => $valor,
                    'metodo_pagamento' => $formaPagamento,
                ]],
            ],
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function idExternoComFallbackMeta(string $tipoEntidade, ?int $idLocal, ?Model $model, ?int $lojaId): ?string
    {
        if ($idLocal) {
            $idExterno = ContaAzulMapeamento::idExternoPorLocal($tipoEntidade, $idLocal, $lojaId);
            if ($idExterno !== null && $idExterno !== '') {
                return $idExterno;
            }
        }

        $meta = is_array($model?->getAttribute('meta_json')) ? $model->getAttribute('meta_json') : [];

        return $this->stringOrNull(data_get($meta, 'conta_azul.id'))
            ?: $this->stringOrNull(data_get($meta, 'conta_azul_id'));
    }

    private function formaPagamento(mixed $formaPagamento): string
    {
        $code = Str::ascii(trim((string) $formaPagamento));
        $code = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', $code));
        $code = trim($code, '_');

        return match ($code) {
            '', 'OUTROS' => 'OUTRO',
            'PIX', 'PAGAMENTO_INSTANTANEO' => 'PIX_PAGAMENTO_INSTANTANEO',
            default => $code,
        };
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
