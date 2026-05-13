<?php

namespace App\Exports;

use App\DTOs\FiltroContaPagarDTO;
use App\Models\ContaPagar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContasPagarExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly array $params = []) {}

    public function collection(): Collection
    {
        return $this->query()->get();
    }

    public function query(): Builder
    {
        $f = new FiltroContaPagarDTO(
            busca: $this->params['busca'] ?? null,
            fornecedor_id: $this->params['fornecedor_id'] ?? null,
            status: $this->params['status'] ?? null,
            centro_custo_id: isset($this->params['centro_custo_id']) ? (int) $this->params['centro_custo_id'] : null,
            categoria_id: isset($this->params['categoria_id']) ? (int) $this->params['categoria_id'] : null,
            data_ini: $this->params['data_ini'] ?? null,
            data_fim: $this->params['data_fim'] ?? null,
            vencidas: array_key_exists('vencidas', $this->params)
                ? filter_var($this->params['vencidas'], FILTER_VALIDATE_BOOLEAN)
                : false,
        );

        $q = ContaPagar::query()->with(['fornecedor', 'categoria', 'centroCusto']);

        if ($f->busca) {
            $busca = "%{$f->busca}%";
            $q->where(fn ($w) => $w
                ->where('descricao', 'like', $busca)
                ->orWhere('numero_documento', 'like', $busca)
            );
        }
        if ($f->fornecedor_id) $q->where('fornecedor_id', $f->fornecedor_id);
        if ($f->status) $q->where('status', $f->status);
        if ($f->centro_custo_id) $q->where('centro_custo_id', $f->centro_custo_id);
        if ($f->categoria_id) $q->where('categoria_id', $f->categoria_id);
        if ($f->data_ini) $q->whereDate('data_vencimento', '>=', $f->data_ini);
        if ($f->data_fim) $q->whereDate('data_vencimento', '<=', $f->data_fim);

        if ($f->vencidas) {
            $q->whereDate('data_vencimento', '<', now()->toDateString())
                ->where('status', '!=', 'PAGA');
        }

        return $q->orderBy('data_vencimento')->orderBy('id');
    }

    public function headings(): array
    {
        return [
            '#',
            'Fornecedor',
            'Descricao',
            'No Doc',
            'Emissao',
            'Vencimento',
            'Bruto',
            'Desconto',
            'Juros',
            'Multa',
            'Liquido',
            'Pago',
            'Saldo',
            'Status',
            'Forma',
            'Categoria',
            'Centro de custo',
        ];
    }

    public function map($c): array
    {
        $liquido = (float) $c->valor_bruto - (float) $c->desconto + (float) $c->juros + (float) $c->multa;

        return [
            $c->id,
            $c->fornecedor?->nome,
            $c->descricao,
            $c->numero_documento,
            optional($c->data_emissao)->format('d/m/Y'),
            optional($c->data_vencimento)->format('d/m/Y'),
            (float) $c->valor_bruto,
            (float) $c->desconto,
            (float) $c->juros,
            (float) $c->multa,
            $liquido,
            (float) $c->valor_pago,
            (float) $c->saldo_aberto,
            $c->status?->value ?? $c->status,
            $c->forma_pagamento,
            $c->categoria?->nome,
            $c->centroCusto?->nome,
        ];
    }
}
