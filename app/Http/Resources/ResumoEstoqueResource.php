<?php

namespace App\Http\Resources;

use App\Helpers\AuthHelper;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource que representa o resumo geral do estoque.
 *
 * @property array $resource
 */
class ResumoEstoqueResource extends JsonResource
{
    /**
     * Transforma os dados do recurso em array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $canViewCusto = AuthHelper::hasPermissao('pedidos.visualizar.todos');

        return [
            'totalProdutos' => (int) ($this['totalProdutos'] ?? 0),
            'totalPecas' => (int) ($this['totalPecas'] ?? 0),
            'totalDepositos' => (int) ($this['totalDepositos'] ?? 0),
            'totalValorEstoque' => $this->when(
                $canViewCusto,
                (float) ($this['totalValorEstoque'] ?? 0)
            ),
        ];
    }
}
