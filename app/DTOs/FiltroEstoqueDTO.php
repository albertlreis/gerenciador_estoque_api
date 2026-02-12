<?php

namespace App\DTOs;

/**
 * DTO para filtros da consulta de estoque.
 *
 * @phpstan-type PeriodoRange array{0:string,1:string}
 */
class FiltroEstoqueDTO
{
    /** Texto de busca (produto.nome ou produto_variacoes.referencia) */
    public ?string $produto = null;

    /** ID do depósito (quando definido, agrega/filtra o estoque por depósito) */
    public ?int $deposito = null;

    /** ID da categoria do produto */
    public ?int $categoria = null;

    /** ID do fornecedor do produto */
    public ?int $fornecedor = null;

    /** Tipo de movimentação relacionado ao filtro de período (entrada|saida). */
    public ?string $tipo = null;

    /**
     * Período [início, fim] para filtrar variações com movimentação no intervalo.
     *
     * @var PeriodoRange|null
     */
    public ?array $periodo = null;

    /** Número de registros por página */
    public int $perPage = 10;

    /** Status de estoque (com_estoque | sem_estoque) */
    public ?string $estoqueStatus = null;

    /** Se deve exibir apenas produtos com estoque zerado */
    public bool $zerados = false;

    /** Se deve exibir apenas produtos com estoque positivo */
    public bool $comEstoque = false;

    /** Campo para ordenação */
    public ?string $sortField = null;

    /** Ordem de ordenação (asc|desc) */
    public ?string $sortOrder = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        // Produto (busca textual)
        $produto = isset($data['produto']) ? trim((string) $data['produto']) : '';
        $this->produto = $produto !== '' ? $produto : null;

        // IDs numéricos (aceita int/string; trata '', null, 0 como null)
        $this->deposito = $this->toNullablePositiveInt($data['deposito'] ?? null);
        $this->categoria = $this->toNullablePositiveInt($data['categoria'] ?? null);
        $this->fornecedor = $this->toNullablePositiveInt($data['fornecedor'] ?? null);
        $this->tipo = isset($data['tipo']) ? trim((string) $data['tipo']) : null;
        if ($this->tipo === '') {
            $this->tipo = null;
        }

        // Período: [inicio, fim]
        $periodo = $data['periodo'] ?? null;
        if (is_array($periodo) && count($periodo) === 2) {
            $ini = isset($periodo[0]) ? trim((string) $periodo[0]) : '';
            $fim = isset($periodo[1]) ? trim((string) $periodo[1]) : '';
            $this->periodo = ($ini !== '' && $fim !== '') ? [$ini, $fim] : null;
        } else {
            $this->periodo = null;
        }

        // per_page (proteção)
        $perPage = (int) ($data['per_page'] ?? 10);
        $this->perPage = max(1, min(200, $perPage));

        // estoque_status (com_estoque | sem_estoque) tem precedência sobre "zerados"
        $status = isset($data['estoque_status']) ? strtolower(trim((string) $data['estoque_status'])) : null;
        $this->estoqueStatus = in_array($status, ['com_estoque', 'sem_estoque'], true) ? $status : null;

        // zerados (aceita 1/0, "true"/"false", true/false)
        $zerados = filter_var(
            $data['zerados'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? false;

        $this->comEstoque = $this->estoqueStatus === 'com_estoque';
        if ($this->comEstoque) {
            $this->zerados = false;
        } else {
            $this->zerados = $this->estoqueStatus === 'sem_estoque' ? true : $zerados;
        }

        // sort
        $this->sortField = isset($data['sort_field']) ? trim((string) $data['sort_field']) : null;

        $sortOrder = isset($data['sort_order']) ? strtolower(trim((string) $data['sort_order'])) : null;
        $this->sortOrder = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : null;
    }

    private function toNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) return null;

        $s = trim((string) $value);
        if ($s === '' || $s === '0') return null;

        $n = (int) $s;
        return $n > 0 ? $n : null;
    }
}
