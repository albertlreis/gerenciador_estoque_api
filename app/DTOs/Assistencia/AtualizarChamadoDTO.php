<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para atualização de campos básicos do Chamado.
 */
class AtualizarChamadoDTO
{
    public function __construct(
        public readonly ?string $origemTipo,
        public readonly ?int    $origemId,
        public readonly ?int    $clienteId,
        public readonly ?int    $fornecedorId,
        public readonly ?int    $assistenciaId,
        public readonly ?string $prioridade,
        public readonly ?string $canalAbertura,
        public readonly ?string $observacoes,
    ) {}

    /**
     * Cria o DTO a partir de um array validado (FormRequest).
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        return new self(
            origemTipo:    $data['origem_tipo']    ?? null,
            origemId:      $data['origem_id']      ?? null,
            clienteId:     $data['cliente_id']     ?? null,
            fornecedorId:  $data['fornecedor_id']  ?? null,
            assistenciaId: $data['assistencia_id'] ?? null,
            prioridade:    $data['prioridade']     ?? null,
            canalAbertura: $data['canal_abertura'] ?? null,
            observacoes:   $data['observacoes']    ?? null,
        );
    }

    /**
     * Retorna apenas campos presentes para atualização em massa.
     *
     * @return array<string, mixed>
     */
    public function toUpdateArray(): array
    {
        return array_filter([
            'origem_tipo'    => $this->origemTipo,
            'origem_id'      => $this->origemId,
            'cliente_id'     => $this->clienteId,
            'fornecedor_id'  => $this->fornecedorId,
            'assistencia_id' => $this->assistenciaId,
            'prioridade'     => $this->prioridade,
            'canal_abertura' => $this->canalAbertura,
            'observacoes'    => $this->observacoes,
        ], static fn($v) => !is_null($v));
    }
}
