<?php

namespace App\DTOs;

/**
 * DTO para filtros da listagem de movimentações de estoque.
 */
class FiltroMovimentacaoEstoqueDTO
{
    public const ESTOQUE_CLIENTE_STATUSES = [
        'todos_pendentes',
        'aguardando_estoque',
        'reservado',
        'pendente_entrega',
    ];

    /** @var int|null ID da variação do produto */
    public ?int $variacao;

    /** @var string|null Tipo de movimentação (ex: entrada, saída) */
    public ?string $tipo;

    /** @var string|null Nome ou referência do produto */
    public ?string $produto;

    /** @var int|null ID do depósito (origem ou destino) */
    public ?int $deposito;

    /** @var int|null ID da categoria do produto */
    public ?int $categoria;

    /** @var int|null ID do fornecedor do produto */
    public ?int $fornecedor;

    public ?string $localizacao;

    public ?string $area;

    public ?int $localizacaoId;

    public bool $estoqueCliente;

    public ?string $estoqueClienteStatus;

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
        $this->variacao = $this->toNullablePositiveInt($data['variacao'] ?? null);
        $this->tipo = isset($data['tipo']) ? trim((string) $data['tipo']) : null;
        $this->produto = isset($data['produto']) ? trim((string) $data['produto']) : null;
        if ($this->tipo === '') {
            $this->tipo = null;
        }
        if ($this->produto === '') {
            $this->produto = null;
        }
        $this->deposito = $this->toNullablePositiveInt($data['deposito'] ?? null);
        $this->categoria = $this->toNullablePositiveInt($data['categoria'] ?? null);
        $this->fornecedor = $this->toNullablePositiveInt($data['fornecedor'] ?? null);
        $this->localizacaoId = $this->toNullablePositiveInt($data['localizacao_id'] ?? null);
        $this->localizacao = isset($data['localizacao']) ? trim((string) $data['localizacao']) : null;
        if ($this->localizacao === '') {
            $this->localizacao = null;
        }
        $this->area = isset($data['area']) ? trim((string) $data['area']) : null;
        if ($this->area === '') {
            $this->area = null;
        }
        $estoqueClienteLegado = filter_var(
            $data['estoque_cliente'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $estoqueClienteStatus = isset($data['estoque_cliente_status'])
            ? strtolower(trim((string) $data['estoque_cliente_status']))
            : null;
        $this->estoqueClienteStatus = in_array($estoqueClienteStatus, self::ESTOQUE_CLIENTE_STATUSES, true)
            ? $estoqueClienteStatus
            : ($estoqueClienteLegado ? 'todos_pendentes' : null);
        $this->estoqueCliente = $this->estoqueClienteStatus !== null;
        $this->periodo = $data['periodo'] ?? null;
        $this->sortField = $data['sort_field'] ?? null;
        $this->sortOrder = $data['sort_order'] ?? 'desc';
        $this->perPage = $data['per_page'] ?? 10;
    }

    private function toNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) return null;
        $n = (int) $value;
        return $n > 0 ? $n : null;
    }
}
