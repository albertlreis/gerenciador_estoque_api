<?php

namespace App\Exports\Relatorios;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class ConsignacoesExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected array $linhas,
        protected float $totalGeral,
        protected bool $consolidado
    ) {}

    public function headings(): array
    {
        if ($this->consolidado) {
            return ['Cliente', 'Total (R$)'];
        }
        // detalhado
        return ['Cliente', 'Produto', 'Data de Envio', 'Vencimento', 'Status', 'Total (R$)'];
    }

    public function collection(): Collection
    {
        $linhas = collect($this->linhas)->map(function ($i) {
            if ($this->consolidado) {
                return [
                    $i['cliente'],
                    number_format((float)$i['total'], 2, ',', '.'),
                ];
            }
            return [
                $i['cliente'],
                $i['produto'],
                $i['data_envio_br'],
                $i['vencimento_br'],
                $i['status_label'],
                number_format((float)$i['total'], 2, ',', '.'),
            ];
        });

        // Total Geral
        if ($this->consolidado) {
            $linhas = $linhas->push(['Total Geral', number_format($this->totalGeral, 2, ',', '.')]);
        } else {
            $linhas = $linhas->push(['', '', '', 'Total Geral', number_format($this->totalGeral, 2, ',', '.')]);
        }

        return $linhas;
    }
}
