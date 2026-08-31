<?php

namespace App\Services;

use App\DTOs\FiltroEstoqueDTO;
use App\Helpers\AuthHelper;
use App\Repositories\EstoqueRepository;
use Illuminate\Support\Facades\DB;

/**
 * Serviço responsável por gerar métricas/resumo de estoque.
 */
class EstoqueResumoService
{
    public function __construct(
        private readonly EstoqueRepository $estoqueRepository
    ) {}

    /**
     * Gera resumo com a mesma base de filtros da listagem de /estoque/atual.
     *
     * @return array{totalProdutos:int,totalPecas:int,totalDepositos:int}
     */
    public function gerarResumoEstoque(FiltroEstoqueDTO $filtros): array
    {
        $query = $this->estoqueRepository->queryBase($filtros);
        $baseSub = DB::query()->fromSub((clone $query)->toBase(), 'estoque_base');
        $podeVisualizarValor = AuthHelper::hasPermissao('pedidos.visualizar.todos');
        $agregadosQuery = (clone $baseSub)->selectRaw(
            'COUNT(DISTINCT produto_id) AS total_produtos, COALESCE(SUM(quantidade_estoque), 0) AS total_pecas'
        );

        if ($podeVisualizarValor) {
            $agregadosQuery->selectRaw(
                'COALESCE(SUM(COALESCE(quantidade_estoque, 0) * COALESCE(custo, 0)), 0) AS total_valor_estoque'
            );
        }

        $agregados = $agregadosQuery->first();

        $variacoesSub = DB::query()
            ->fromSub((clone $query)->toBase(), 'estoque_base')
            ->select('id');

        $depositosQuery = DB::table('estoque as e')
            ->whereIn('e.id_variacao', $variacoesSub);

        if ($filtros->deposito) {
            $depositosQuery->where('e.id_deposito', (int) $filtros->deposito);
        }
        if (! $filtros->zerados) {
            $depositosQuery->where('e.quantidade', '>', 0);
        }

        $resumo = [
            'totalProdutos' => (int) ($agregados->total_produtos ?? 0),
            'totalPecas' => (int) ($agregados->total_pecas ?? 0),
            'totalDepositos' => (int) $depositosQuery->distinct()->count('e.id_deposito'),
        ];

        if ($podeVisualizarValor) {
            $resumo['totalValorEstoque'] = (float) ($agregados->total_valor_estoque ?? 0);
        }

        return $resumo;
    }
}
