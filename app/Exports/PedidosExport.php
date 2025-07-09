<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Exporta uma coleção de pedidos para planilha Excel.
 */
class PedidosExport implements FromCollection, WithHeadings
{
    /**
     * @var Collection
     */
    protected Collection $pedidos;

    /**
     * Construtor
     *
     * @param Collection $pedidos
     */
    public function __construct(Collection $pedidos)
    {
        $this->pedidos = $pedidos;
    }

    /**
     * Retorna a coleção formatada para exportação.
     *
     * @return Collection
     */
    public function collection(): Collection
    {
        return $this->pedidos->map(function ($pedido) {
            return [
                'numero' => $pedido->numero ?? '',
                'data' => optional($pedido->data)->format('d/m/Y') ?? '',
                'cliente' => $pedido->cliente->nome ?? '',
                'parceiro' => $pedido->parceiro->nome ?? '',
                'valor_total' => number_format((float) $pedido->valor_total, 2, ',', '.'),
                'status' => ucfirst($pedido->status ?? ''),
            ];
        });
    }

    /**
     * Define os cabeçalhos da planilha.
     *
     * @return array<int, string>
     */
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
