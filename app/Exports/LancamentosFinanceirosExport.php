<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LancamentosFinanceirosExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $lancamentos) {}

    public function collection(): Collection
    {
        return $this->lancamentos;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Tipo',
            'Descricao',
            'Categoria',
            'Centro de custo',
            'Conta',
            'Movimento',
            'Competencia',
            'Valor',
            'Status',
            'Observacoes',
        ];
    }

    public function map($lancamento): array
    {
        return [
            $lancamento->id,
            $lancamento->tipo?->value ?? $lancamento->tipo,
            $lancamento->descricao,
            $lancamento->categoria?->nome,
            $lancamento->centroCusto?->nome,
            $lancamento->conta?->nome,
            optional($lancamento->data_movimento)->format('d/m/Y H:i'),
            optional($lancamento->competencia)->format('d/m/Y'),
            (float) $lancamento->valor,
            $lancamento->status?->value ?? $lancamento->status,
            $lancamento->observacoes,
        ];
    }
}
