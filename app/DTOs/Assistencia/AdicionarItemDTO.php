<?php

namespace App\DTOs\Assistencia;

class AdicionarItemDTO
{
    public function __construct(
        public int     $chamadoId,
        public ?int    $variacaoId,
        public ?int    $defeitoId,
        public ?int    $depositoOrigemId,
        public ?int    $pedidoItemId,
        public ?int    $consignacaoId,
        public ?string $observacoes,
        public ?string $notaNumero,
        public ?string $prazoFinalizacao,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            chamadoId:         $data['chamado_id'],
            variacaoId:        $data['variacao_id'] ?? null,
            defeitoId:         $data['defeito_id'] ?? null,
            depositoOrigemId:  $data['deposito_origem_id'] ?? null,
            pedidoItemId:      $data['pedido_item_id'] ?? null,
            consignacaoId:     $data['consignacao_id'] ?? null,
            observacoes:       $data['observacoes'] ?? null,
            notaNumero:        $data['nota_numero'] ?? null,
            prazoFinalizacao:  $data['prazo_finalizacao'] ?? null,
        );
    }
}
