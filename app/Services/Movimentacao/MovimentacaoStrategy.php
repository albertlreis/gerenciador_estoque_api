<?php

namespace App\Services\Movimentacao;

use App\Models\Pedido;
use Illuminate\Support\Collection;

/**
 * Strategy para processar itens na finalização:
 * - movimentar estoque; OU
 * - reservar estoque.
 */
interface MovimentacaoStrategy
{
    /**
     * @param  Pedido     $pedido
     * @param  Collection $itensCarrinho
     * @param  array      $depositosMap
     * @param  int        $usuarioId
     * @return void
     */
    public function processar(Pedido $pedido, Collection $itensCarrinho, array $depositosMap, int $usuarioId): void;
}
