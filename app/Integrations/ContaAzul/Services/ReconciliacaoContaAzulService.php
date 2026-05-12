<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulReconciliationState;
use App\Integrations\ContaAzul\Support\StructuredLog;

class ReconciliacaoContaAzulService
{
    public function __construct(
        private readonly ImportacaoContaAzulService $importacao,
        private readonly ConciliacaoContaAzulService $conciliacao
    ) {
    }

    public function reconciliarRecurso(ContaAzulConexao $conexao, string $recurso, ?int $lojaId = null): void
    {
        if (!filter_var(config('conta_azul.flags.reconciliacao_ativa', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $tipo = $this->mapRecursoToTipo($recurso);
        if ($tipo !== null) {
            $this->importacao->importarParaStaging($conexao, $tipo, $lojaId);
        }

        match ($recurso) {
            'pessoas' => $this->conciliacao->conciliarPessoas($lojaId),
            'produtos' => $this->conciliacao->conciliarProdutos($lojaId),
            'vendas' => $this->conciliacao->conciliarVendas($lojaId),
            'financeiro', 'titulos' => $this->conciliacao->conciliarTitulos($lojaId),
            'parcelas' => $this->conciliacao->conciliarParcelas($lojaId),
            'baixas' => $this->conciliacao->conciliarBaixas($lojaId),
            'contas_financeiras', 'contas-financeiras' => $this->conciliacao->conciliarContasFinanceiras($lojaId),
            'saldos_contas_financeiras', 'saldos-contas-financeiras' => $this->conciliacao->conciliarSaldosContasFinanceiras($lojaId),
            'categorias_financeiras', 'categorias-financeiras' => $this->conciliacao->conciliarCategoriasFinanceiras($lojaId),
            'centros_custo', 'centros-custo' => $this->conciliacao->conciliarCentrosCusto($lojaId),
            'formas_pagamento', 'formas-pagamento' => $this->conciliacao->conciliarFormasPagamento($lojaId),
            'notas' => null,
            default => null,
        };

        ContaAzulReconciliationState::updateOrCreate(
            [
                'loja_id' => $lojaId,
                'recurso' => $recurso,
            ],
            [
                'ultima_execucao_em' => now(),
                'ultima_data_consulta' => now(),
                'ultimo_cursor' => (string) now()->timestamp,
                'status' => 'ok',
            ]
        );

        StructuredLog::integration('conta_azul.reconcile.done', [
            'conexao_id' => $conexao->id,
            'recurso' => $recurso,
        ]);
    }

    public function reconciliarTodos(ContaAzulConexao $conexao, ?int $lojaId = null): void
    {
        foreach (['pessoas', 'produtos', 'vendas', 'titulos', 'contas_financeiras', 'categorias_financeiras', 'centros_custo', 'parcelas', 'baixas', 'saldos_contas_financeiras', 'formas_pagamento', 'notas'] as $r) {
            $this->reconciliarRecurso($conexao, $r, $lojaId);
        }
    }

    private function mapRecursoToTipo(string $recurso): ?string
    {
        return match ($recurso) {
            'pessoas' => ContaAzulEntityType::PESSOA,
            'produtos' => ContaAzulEntityType::PRODUTO,
            'vendas' => ContaAzulEntityType::VENDA,
            'financeiro', 'titulos' => ContaAzulEntityType::TITULO,
            'parcelas' => ContaAzulEntityType::PARCELA,
            'baixas' => ContaAzulEntityType::BAIXA,
            'contas_financeiras', 'contas-financeiras' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'saldos_contas_financeiras', 'saldos-contas-financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            'categorias_financeiras', 'categorias-financeiras' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            'centros_custo', 'centros-custo' => ContaAzulEntityType::CENTRO_CUSTO,
            'formas_pagamento', 'formas-pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
            'notas' => ContaAzulEntityType::NOTA,
            default => null,
        };
    }
}
