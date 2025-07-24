<?php

namespace App\DTOs;

/**
 * DTO para filtros da consulta de estoque atual agrupado.
 */
class FiltroEstoqueDTO
{
    /** @var string|null Nome ou referência do produto */
    public ?string $produto;

    /** @var int|null ID do depósito */
    public ?int $deposito;

    /** @var array<int, string>|null Período (data inicial e final) */
    public ?array $periodo;

    /** @var int Número de registros por página */
    public int $perPage;

    /** @var bool Se deve exibir produtos com estoque zerado */
    public bool $zerados;

    /** @var string|null Campo para ordenação */
    public ?string $sortField;

    /** @var string|null Ordem de ordenação (asc ou desc) */
    public ?string $sortOrder;

    /**
     * Construtor do DTO de filtro de estoque.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->produto = $data['produto'] ?? null;
        $this->deposito = $data['deposito'] ?? null;
        $this->periodo = $data['periodo'] ?? null;
        $this->perPage = $data['per_page'] ?? 10;
        $this->zerados = (bool) ($data['zerados'] ?? false);
        $this->sortField = $data['sort_field'] ?? null;
        $this->sortOrder = $data['sort_order'] ?? null;
    }
}
