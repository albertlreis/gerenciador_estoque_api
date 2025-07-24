<?php

namespace App\DTOs;

/**
 * DTO para filtros da listagem de movimentações de estoque.
 */
class FiltroMovimentacaoEstoqueDTO
{
    /** @var int|null ID da variação do produto */
    public ?int $variacao;

    /** @var string|null Tipo de movimentação (ex: entrada, saída) */
    public ?string $tipo;

    /** @var string|null Nome ou referência do produto */
    public ?string $produto;

    /** @var int|null ID do depósito (origem ou destino) */
    public ?int $deposito;

    /** @var array<int, string>|null Período da movimentação (inicial, final) */
    public ?array $periodo;

    /** @var string|null Campo de ordenação */
    public ?string $sortField;

    /** @var string Ordem da ordenação */
    public string $sortOrder;

    /** @var int Quantidade de registros por página */
    public int $perPage;

    /**
     * Construtor do DTO de filtro de movimentações.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->variacao = $data['variacao'] ?? null;
        $this->tipo = $data['tipo'] ?? null;
        $this->produto = $data['produto'] ?? null;
        $this->deposito = $data['deposito'] ?? null;
        $this->periodo = $data['periodo'] ?? null;
        $this->sortField = $data['sort_field'] ?? null;
        $this->sortOrder = $data['sort_order'] ?? 'desc';
        $this->perPage = $data['per_page'] ?? 10;
    }
}
