<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Consolida validações antes de registrar movimentações.
 */
final class PedidoFinalizacaoValidator
{
    public function __construct(
        private readonly EstoqueDisponibilidadeService $disponibilidade,
        private readonly DepositoResolver $resolver
    ) {}

    /**
     * Valida se todos os itens possuem depósito definido e estoque suficiente.
     *
     * @param  Collection $itensCarrinho
     * @param  array      $depositosMap
     * @throws ValidationException
     */
    public function validarAntesDeMovimentar(Collection $itensCarrinho, array $depositosMap): void
    {
        $erros = [];

        foreach ($itensCarrinho as $item) {
            $depId = $this->resolver->resolverParaItem($item, $depositosMap);

            if (!$depId) {
                $erros[] = "Selecione o depósito para o item {$item->id} ({$item->nome_completo}).";
                continue;
            }

            $disp = $this->disponibilidade->getDisponivel($item->id_variacao, (int) $depId);
            if ($disp < (int) $item->quantidade) {
                $erros[] = "Estoque insuficiente no depósito para o item {$item->id} ({$item->nome_completo}). Disponível: {$disp}, solicitado: {$item->quantidade}.";
            }
        }

        if ($erros) {
            throw ValidationException::withMessages(['estoque' => $erros]);
        }
    }
}
