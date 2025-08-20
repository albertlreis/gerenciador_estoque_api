<?php

namespace App\Exports\Relatorios;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class PedidosPorPeriodoExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected array $dados,
        protected float $totalGeral
    ) {}

    public function headings(): array
    {
        return ['Número', 'Data', 'Cliente', 'Status', 'Total (R$)'];
    }

    public function collection(): Collection
    {
        $linhas = collect($this->dados)->map(fn($p) => [
            $p['numero'],
            $p['data_br'],
            $p['cliente'],
            $p['status_label'],
            number_format((float)$p['total'], 2, ',', '.'),
        ]);

        // Linha em branco + total geral
        return $linhas
            ->push(['', '', '', 'Total Geral', number_format($this->totalGeral, 2, ',', '.')]);
    }
}
