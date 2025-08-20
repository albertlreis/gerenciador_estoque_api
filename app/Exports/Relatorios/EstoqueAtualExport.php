<?php

namespace App\Exports\Relatorios;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EstoqueAtualExport implements FromArray, WithHeadings, WithMapping, WithColumnFormatting
{
    public function __construct(
        protected array $dados
    ) {}

    public function headings(): array
    {
        return ['Produto', 'Estoque Total', 'Valor Total (R$)', 'Depósito', 'Qtd Depósito', 'Valor Depósito (R$)'];
    }

    public function array(): array
    {
        // "Achata" por depósito para leitura em planilha
        $linhas = [];
        foreach ($this->dados as $produto => $info) {
            $deps = $info['estoque_por_deposito'] ?? [];
            if (empty($deps)) {
                $linhas[] = [$produto, $info['estoque_total'] ?? 0, $info['valor_total'] ?? 0, '-', 0, 0];
                continue;
            }
            foreach ($deps as $dep) {
                $linhas[] = [
                    $produto,
                    (int)($info['estoque_total'] ?? 0),
                    (float)($info['valor_total'] ?? 0),
                    $dep['nome'] ?? '-',
                    (int)($dep['quantidade'] ?? 0),
                    (float)($dep['valor'] ?? 0),
                ];
            }
        }
        return $linhas;
    }

    public function map($row): array
    {
        return $row;
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }
}
