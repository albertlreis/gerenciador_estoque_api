<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para abertura de chamado de assistência.
 */
class CriarChamadoDTO
{
    public function __construct(
        public string  $origemTipo,            // 'pedido'|'consignacao'|'estoque'
        public ?int    $origemId,
        public ?int    $clienteId,
        public ?int    $fornecedorId,
        public ?int    $assistenciaId,         // pode ser null na abertura
        public ?string $prioridade,            // 'baixa'|'media'|'alta'|'critica' (opcional)
        public ?string $canalAbertura,         // loja|site|telefone|whatsapp
        public ?string $observacoes
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            origemTipo: $data['origem_tipo'],
            origemId: $data['origem_id'] ?? null,
            clienteId: $data['cliente_id'] ?? null,
            fornecedorId: $data['fornecedor_id'] ?? null,
            assistenciaId: $data['assistencia_id'] ?? null,
            prioridade: $data['prioridade'] ?? null,
            canalAbertura: $data['canal_abertura'] ?? null,
            observacoes: $data['observacoes'] ?? null,
        );
    }
}
