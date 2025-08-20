<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para adicionar item ao chamado.
 */
class AdicionarItemDTO
{
    public function __construct(
        public int     $chamadoId,
        public ?int    $produtoId,
        public ?int    $variacaoId,
        public ?string $numeroSerie,
        public ?string $lote,
        public ?int    $defeitoId,
        public ?string $descricaoDefeitoLivre,
        public ?int    $depositoOrigemId,
        public ?int    $pedidoId,
        public ?int    $pedidoItemId,
        public ?int    $consignacaoId,
        public ?string $observacoes,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            chamadoId: $data['chamado_id'],
            produtoId: $data['produto_id'] ?? null,
            variacaoId: $data['variacao_id'] ?? null,
            numeroSerie: $data['numero_serie'] ?? null,
            lote: $data['lote'] ?? null,
            defeitoId: $data['defeito_id'] ?? null,
            descricaoDefeitoLivre: $data['descricao_defeito_livre'] ?? null,
            depositoOrigemId: $data['deposito_origem_id'] ?? null,
            pedidoId: $data['pedido_id'] ?? null,
            pedidoItemId: $data['pedido_item_id'] ?? null,
            consignacaoId: $data['consignacao_id'] ?? null,
            observacoes: $data['observacoes'] ?? null,
        );
    }
}
