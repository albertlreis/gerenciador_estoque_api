<?php

namespace App\DTOs;

use Illuminate\Http\Request;

class FiltroLancamentoFinanceiroDTO
{
    public ?string $dataInicio;
    public ?string $dataFim;

    public ?string $status;
    public ?bool $atrasado;

    public ?int $categoriaId;
    public ?int $contaId;
    public ?string $tipo;

    public ?string $q;

    public string $orderBy;
    public string $orderDir;

    public int $page;
    public int $perPage;

    public function __construct(array|Request $input = [])
    {
        $data = $input instanceof Request ? $input->all() : $input;

        $this->dataInicio  = $data['data_inicio'] ?? null;
        $this->dataFim     = $data['data_fim'] ?? null;

        $this->status      = $data['status'] ?? null;
        $this->atrasado    = array_key_exists('atrasado', $data) ? filter_var($data['atrasado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        $this->categoriaId = isset($data['categoria_id']) ? (int)$data['categoria_id'] : null;
        $this->contaId     = isset($data['conta_id']) ? (int)$data['conta_id'] : null;
        $this->tipo        = $data['tipo'] ?? null;

        $this->q           = isset($data['q']) ? trim((string)$data['q']) : null;

        $this->orderBy     = $data['order_by'] ?? 'data_vencimento';
        $this->orderDir    = $data['order_dir'] ?? 'desc';

        $this->page        = isset($data['page']) ? max(1, (int)$data['page']) : 1;
        $this->perPage     = isset($data['per_page']) ? min(200, max(1, (int)$data['per_page'])) : 25;
    }
}
