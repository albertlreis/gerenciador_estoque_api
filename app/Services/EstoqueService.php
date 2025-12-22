<?php

namespace App\Services;

use App\DTOs\FiltroEstoqueDTO;
use App\Models\Produto;
use App\Repositories\EstoqueRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * Serviço de orquestração do módulo de estoque:
 * - Lista paginada com filtros/ordenação
 * - Exportação em PDF
 * - Resumo do estoque (métricas)
 */
class EstoqueService
{
    public function __construct(
        private readonly EstoqueRepository $estoqueRepository,
        private readonly EstoqueResumoService $estoqueResumoService
    ) {}

    /**
     * Lista o estoque conforme filtros informados.
     *
     * @param FiltroEstoqueDTO $filtros
     * @return LengthAwarePaginator
     */
    public function listar(FiltroEstoqueDTO $filtros): LengthAwarePaginator
    {
        $query = $this->estoqueRepository
            ->aplicarRelacoesDeEstoque(
                $this->estoqueRepository->queryBase($filtros),
                $filtros
            );

        $this->aplicarOrdenacao($query, $filtros);

        return $query->paginate($filtros->perPage);
    }

    /**
     * Exporta o estoque filtrado em PDF.
     *
     * Regras:
     * - Quando NÃO estiver filtrando "zerados", protege contra exportações gigantes.
     *
     * Observação:
     * - Contagem é feita usando query base (toBase) para evitar custo extra de Eloquent.
     *
     * @param FiltroEstoqueDTO $filtros
     * @return Response
     */
    public function exportarPdf(FiltroEstoqueDTO $filtros): Response
    {
        $query = $this->estoqueRepository
            ->aplicarRelacoesDeEstoque(
                $this->estoqueRepository->queryBase($filtros),
                $filtros
            );

        $this->aplicarOrdenacao($query, $filtros);

        if (!$filtros->zerados) {
            $countQuery = clone $query;
            $total = $countQuery->toBase()->getCountForPagination();

            if ($total > 3000) {
                abort(422, 'Quantidade de registros muito grande para exportação em PDF.');
            }
        }

        $estoque = $query->get();

        return Pdf::loadView('pdf.estoque-atual', [
            'estoque' => $estoque,
            'filtros' => $filtros,
        ])
            ->setPaper('a4', 'landscape')
            ->download('estoque-atual.pdf');
    }

    /**
     * Aplica ordenação segura na query.
     *
     * Importante:
     * - Evita JOIN para ordenar por produto.nome, para não quebrar paginação/duplicar linhas.
     * - Mantém shape da query em ProdutoVariacao.
     *
     * @param Builder $query
     * @param FiltroEstoqueDTO $filtros
     * @return void
     */
    private function aplicarOrdenacao(Builder $query, FiltroEstoqueDTO $filtros): void
    {
        $direction = $filtros->sortOrder === 'desc' ? 'desc' : 'asc';

        match ($filtros->sortField) {
            'produto_nome' => $query->orderBy(
                Produto::select('nome')->whereColumn('produtos.id', 'produto_variacoes.produto_id'),
                $direction
            ),

            'referencia' => $query->orderBy('produto_variacoes.referencia', $direction),

            'quantidade_estoque' => $query->orderBy('quantidade_estoque', $direction),

            default => null,
        };
    }

    /**
     * Gera resumo simples do estoque.
     *
     * @param string|null $produto
     * @param int|null $deposito
     * @param array|null $periodo Intervalo [inicio, fim]
     * @return array{totalProdutos:int,totalPecas:int,totalDepositos:int}
     */
    public function gerarResumo(
        ?string $produto = null,
        ?int $deposito = null,
        ?array $periodo = null
    ): array {
        return $this->estoqueResumoService->gerarResumoEstoque($produto, $deposito, $periodo);
    }
}
