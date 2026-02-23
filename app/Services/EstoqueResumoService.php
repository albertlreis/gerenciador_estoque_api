<?php

namespace App\Services;

use App\Helpers\AuthHelper;
use App\DTOs\FiltroEstoqueDTO;
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

        $totalProdutos = (int) (clone $baseSub)->distinct()->count('produto_id');
        $totalPecas = (int) ((clone $baseSub)->sum('quantidade_estoque') ?? 0);

        $variacoesSub = DB::query()
            ->fromSub((clone $query)->toBase(), 'estoque_base')
            ->select('id');

        $depositosQuery = DB::table('estoque as e')
            ->whereIn('e.id_variacao', $variacoesSub);

        if ($filtros->deposito) {
            $depositosQuery->where('e.id_deposito', (int) $filtros->deposito);
        }
        if (!$filtros->zerados) {
            $depositosQuery->where('e.quantidade', '>', 0);
        }

        $resumo = [
            'totalProdutos'  => $totalProdutos,
            'totalPecas'     => $totalPecas,
            'totalDepositos' => (int) $depositosQuery->distinct()->count('e.id_deposito'),
        ];

        if (AuthHelper::hasPermissao('pedidos.visualizar.todos')) {
            $totalValorEstoque = (float) (
                (clone $baseSub)->sum(DB::raw('COALESCE(quantidade_estoque, 0) * COALESCE(custo, 0)')) ?? 0
            );
            $resumo['totalValorEstoque'] = $totalValorEstoque;
        }

        return $resumo;
    }
}
