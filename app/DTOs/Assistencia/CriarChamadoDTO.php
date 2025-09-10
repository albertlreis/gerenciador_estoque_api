<?php

namespace App\DTOs\Assistencia;

class CriarChamadoDTO
{
    public function __construct(
        public string  $origemTipo,
        public ?int    $origemId,
        public ?int    $pedidoId,
        public ?int    $assistenciaId,
        public ?string $prioridade,
        public ?string $observacoes,
        public ?string $localReparo,
        public ?string $custoResponsavel,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            origemTipo:       $data['origem_tipo'],
            origemId:         $data['origem_id']   ?? null,
            pedidoId:         $data['pedido_id']   ?? (($data['origem_tipo'] ?? null) === 'pedido' ? ($data['origem_id'] ?? null) : null),
            assistenciaId:    $data['assistencia_id'] ?? null,
            prioridade:       $data['prioridade']  ?? null,
            observacoes:      $data['observacoes'] ?? null,
            localReparo:      $data['local_reparo'] ?? null,
            custoResponsavel: $data['custo_responsavel'] ?? null,
        );
    }
}
