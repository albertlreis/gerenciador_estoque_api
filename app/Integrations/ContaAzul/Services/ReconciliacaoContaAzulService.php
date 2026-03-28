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
            'baixas' => $this->conciliacao->conciliarBaixas($lojaId),
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
        foreach (['pessoas', 'produtos', 'vendas', 'titulos', 'baixas', 'notas'] as $r) {
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
            'baixas' => ContaAzulEntityType::BAIXA,
            'notas' => ContaAzulEntityType::NOTA,
            default => null,
        };
    }
}
