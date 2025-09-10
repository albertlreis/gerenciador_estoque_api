<?php

namespace App\DTOs\Assistencia;

class AtualizarChamadoDTO
{
    public function __construct(
        public readonly ?string $origemTipo,
        public readonly ?int    $origemId,
        public readonly ?int    $pedidoId,
        public readonly ?int    $assistenciaId,
        public readonly ?string $prioridade,
        public readonly ?string $observacoes,
        public readonly ?string $localReparo,
        public readonly ?string $custoResponsavel,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            origemTipo:       $data['origem_tipo']       ?? null,
            origemId:         $data['origem_id']         ?? null,
            pedidoId:         $data['pedido_id']         ?? null,
            assistenciaId:    $data['assistencia_id']    ?? null,
            prioridade:       $data['prioridade']        ?? null,
            observacoes:      $data['observacoes']       ?? null,
            localReparo:      $data['local_reparo']      ?? null,
            custoResponsavel: $data['custo_responsavel'] ?? null,
        );
    }

    public function toUpdateArray(): array
    {
        return array_filter([
            'origem_tipo'       => $this->origemTipo,
            'origem_id'         => $this->origemId,
            'pedido_id'         => $this->pedidoId,
            'assistencia_id'    => $this->assistenciaId,
            'prioridade'        => $this->prioridade,
            'observacoes'       => $this->observacoes,
            'local_reparo'      => $this->localReparo,
            'custo_responsavel' => $this->custoResponsavel,
        ], static fn($v) => !is_null($v));
    }
}
