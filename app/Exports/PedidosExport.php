<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PedidosExport implements FromCollection, WithHeadings
{
    protected $pedidos;

    public function __construct(Collection $pedidos)
    {
        $this->pedidos = $pedidos;
    }

    public function collection()
    {
        return $this->pedidos->map(function ($pedido) {
            return [
                $pedido->numero,
                $pedido->data,
                $pedido->cliente->nome ?? '',
                $pedido->parceiro->nome ?? '',
                number_format($pedido->valor_total , 2, ',', '.'),
                ucfirst($pedido->status),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Nº Pedido',
            'Data',
            'Cliente',
            'Parceiro',
            'Total',
            'Status',
        ];
    }
}
