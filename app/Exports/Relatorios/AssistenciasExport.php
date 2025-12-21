<?php

namespace App\Exports\Relatorios;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AssistenciasExport implements FromArray, WithHeadings
{
    /**
     * @param array<int,array<string,mixed>> $linhas
     */
    public function __construct(
        private readonly array $linhas
    ) {}

    public function headings(): array
    {
        return [
            'Chamado',
            'Status',
            'Prioridade',
            'Local Reparo',
            'Custo Resp',
            'Abertura (BR)',
            'Conclusão (BR)',
            'SLA Limite',
            'Assistência',
            'Pedido',
            'Cliente',
            'Fornecedor',
            'Observações',
        ];
    }

    public function array(): array
    {
        return array_map(function ($r) {
            return [
                $r['numero'] ?? '',
                $r['status'] ?? '',
                $r['prioridade'] ?? '',
                $r['local_reparo'] ?? '',
                $r['custo_resp'] ?? '',
                $r['aberto_em_br'] ?? '',
                $r['concluido_em_br'] ?? '',
                $r['sla_data_limite'] ?? '',
                $r['assistencia'] ?? '',
                $r['pedido_numero'] ?? ($r['pedido_id'] ?? ''),
                $r['cliente'] ?? '',
                $r['fornecedor'] ?? '',
                $r['observacoes'] ?? '',
            ];
        }, $this->linhas);
    }
}
