<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\ContaPagar;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ContaAzulContaPagarMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(ContaPagar $conta, ?int $lojaId = null): array
    {
        if ($conta->exists || $conta->fornecedor_id || $conta->relationLoaded('fornecedor')) {
            $conta->loadMissing(['fornecedor', 'categoria', 'centroCusto', 'pagamentos.contaFinanceira']);
        }

        $valor = (float) $conta->valor_liquido;
        $dataCompetencia = $this->date($conta->data_emissao) ?: $this->date($conta->data_vencimento);
        $dataVencimento = $this->date($conta->data_vencimento) ?: $dataCompetencia;
        $descricao = $this->descricao($conta);
        $descricaoParcela = $this->descricaoParcela($conta, $descricao);

        $idFornecedorExt = $this->idExternoComFallbackMeta(
            ContaAzulEntityType::FORNECEDOR,
            $conta->fornecedor_id ? (int) $conta->fornecedor_id : null,
            $conta->relationLoaded('fornecedor') ? $conta->getRelation('fornecedor') : null,
            $lojaId
        );

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

        if ($idFornecedorExt === null || $idFornecedorExt === '') {
            throw new ContaAzulException(
                'Exportacao de conta a pagar bloqueada: fornecedor local sem mapeamento externo na Conta Azul.',
                'conta_azul_fornecedor_sem_mapeamento',
                [
                    'conta_pagar_id' => $conta->id,
                    'fornecedor_id' => $conta->fornecedor_id,
                    'loja_id' => $lojaId,
                ]
            );
        }

        $idContaFinanceiraExt = $this->idContaFinanceiraExterna($conta, $lojaId);
        if ($idContaFinanceiraExt === null || $idContaFinanceiraExt === '') {
            throw new ContaAzulException(
                'Exportacao de conta a pagar bloqueada: conta financeira local sem mapeamento externo na Conta Azul.',
                'conta_azul_conta_financeira_sem_mapeamento',
                [
                    'conta_pagar_id' => $conta->id,
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
            'data_competencia' => $dataCompetencia,
            'valor' => $valor,
            'observacao' => $this->observacao($conta, $descricaoParcela),
            'descricao' => $descricao,
            'contato' => $idFornecedorExt,
            'conta_financeira' => $idContaFinanceiraExt,
            'rateio' => [$rateio],
            'condicao_pagamento' => [
                'parcelas' => [[
                    'descricao' => $descricaoParcela,
                    'data_vencimento' => $dataVencimento,
                    'nota' => $this->notaParcela($conta),
                    'conta_financeira' => $idContaFinanceiraExt,
                    'detalhe_valor' => [
                        'multa' => (float) $conta->multa,
                        'juros' => (float) $conta->juros,
                        'valor_bruto' => (float) $conta->valor_bruto,
                        'valor_liquido' => $valor,
                        'desconto' => (float) $conta->desconto,
                        'taxa' => 0.0,
                    ],
                ]],
            ],
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function idContaFinanceiraExterna(ContaPagar $conta, ?int $lojaId): ?string
    {
        $pagamentos = $conta->relationLoaded('pagamentos')
            ? $conta->getRelation('pagamentos')
            : ($conta->exists
                ? $conta->pagamentos()->with('contaFinanceira')->orderBy('data_pagamento')->orderBy('id')->get()
                : collect());

        foreach ($pagamentos as $pagamento) {
            $contaFinanceiraId = $pagamento->conta_financeira_id ? (int) $pagamento->conta_financeira_id : null;
            if (!$contaFinanceiraId) {
                continue;
            }

            $contaFinanceira = $pagamento->relationLoaded('contaFinanceira')
                ? $pagamento->getRelation('contaFinanceira')
                : null;

            $idExterno = $this->idExternoComFallbackMeta(
                ContaAzulEntityType::CONTA_FINANCEIRA,
                $contaFinanceiraId,
                $contaFinanceira,
                $lojaId
            );
            if ($idExterno !== null && $idExterno !== '') {
                return $idExterno;
            }
        }

        return null;
    }

    private function descricao(ContaPagar $conta): string
    {
        $descricao = trim((string) $conta->descricao);

        return $descricao !== '' ? $descricao : $this->fallbackDescricao($conta);
    }

    private function descricaoParcela(ContaPagar $conta, string $descricao): string
    {
        $documento = trim((string) $conta->numero_documento);
        if ($documento !== '' && $descricao !== '') {
            return $documento . ' - ' . $descricao;
        }

        return $documento !== '' ? $documento : ($descricao !== '' ? $descricao : $this->fallbackDescricao($conta));
    }

    private function observacao(ContaPagar $conta, string $descricaoParcela): string
    {
        $observacoes = trim((string) $conta->observacoes);

        return $observacoes !== '' ? $observacoes : $descricaoParcela;
    }

    private function notaParcela(ContaPagar $conta): string
    {
        $formaPagamento = trim((string) $conta->forma_pagamento);

        return $formaPagamento !== '' ? 'Pagamento via ' . $formaPagamento : 'Pagamento de conta a pagar';
    }

    private function fallbackDescricao(ContaPagar $conta): string
    {
        $id = $conta->id ? ' #' . $conta->id : '';

        return 'Conta a pagar' . $id;
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
