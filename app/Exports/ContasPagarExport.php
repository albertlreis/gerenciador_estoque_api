<?php

namespace App\Exports;

use App\DTOs\FiltroContaPagarDTO;
use App\Models\ContaPagar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ContasPagarExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles
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
            forma_pagamento: $this->params['forma_pagamento'] ?? null,
            centro_custo_id: isset($this->params['centro_custo_id']) ? (int) $this->params['centro_custo_id'] : null,
            categoria_id: isset($this->params['categoria_id']) ? (int) $this->params['categoria_id'] : null,
            data_ini: $this->params['data_ini'] ?? null,
            data_fim: $this->params['data_fim'] ?? null,
            vencidas: array_key_exists('vencidas', $this->params)
                ? filter_var($this->params['vencidas'], FILTER_VALIDATE_BOOLEAN)
                : false,
            em_aberto: array_key_exists('em_aberto', $this->params)
                ? filter_var($this->params['em_aberto'], FILTER_VALIDATE_BOOLEAN)
                : false,
            origem: $this->params['origem'] ?? null,
            conta_financeira_id: isset($this->params['conta_financeira_id']) ? (int) $this->params['conta_financeira_id'] : null,
        );

        $q = ContaPagar::query()->with(['fornecedor', 'categoria', 'centroCusto', 'pagamentos']);

        if ($f->busca) {
            $busca = "%{$f->busca}%";
            $q->where(fn ($w) => $w
                ->where('descricao', 'like', $busca)
                ->orWhere('numero_documento', 'like', $busca)
            );
        }
        if ($f->fornecedor_id) $q->where('fornecedor_id', $f->fornecedor_id);
        if ($f->status) $q->where('status', $f->status);
        if ($f->forma_pagamento) $q->where('forma_pagamento', $f->forma_pagamento);
        if ($f->centro_custo_id) $q->where('centro_custo_id', $f->centro_custo_id);
        if ($f->categoria_id) $q->where('categoria_id', $f->categoria_id);
        if ($f->conta_financeira_id) {
            $q->whereHas('pagamentos', fn ($pagamento) => $pagamento->where('conta_financeira_id', $f->conta_financeira_id));
        }
        if ($f->origem === 'recorrente') $q->whereNotNull('despesa_recorrente_id');
        if ($f->data_ini) $q->whereDate('data_vencimento', '>=', $f->data_ini);
        if ($f->data_fim) $q->whereDate('data_vencimento', '<=', $f->data_fim);

        if ($f->em_aberto) {
            $q->whereNotIn('status', ['PAGA', 'CANCELADA']);
        }

        if ($f->vencidas) {
            $q->whereDate('data_vencimento', '<', now()->toDateString())
                ->whereNotIn('status', ['PAGA', 'CANCELADA']);
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
        $pago = $c->relationLoaded('pagamentos')
            ? (float) $c->pagamentos->sum('valor')
            : (float) $c->valor_pago;
        $saldo = max(0, $liquido - $pago);

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
            $pago,
            $saldo,
            $c->status?->value ?? $c->status,
            $c->forma_pagamento,
            $c->categoria?->nome,
            $c->centroCusto?->nome,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_NUMBER_00,
            'H' => NumberFormat::FORMAT_NUMBER_00,
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
            'K' => NumberFormat::FORMAT_NUMBER_00,
            'L' => NumberFormat::FORMAT_NUMBER_00,
            'M' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = max(1, $sheet->getHighestRow());

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '0F172A'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EAF2FF'],
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'BFD0E2'],
                ],
            ],
        ]);

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP);

        $sheet->getStyle("B2:D{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("O2:Q{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("G2:M{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $lastRow = max(1, $sheet->getHighestRow());

                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}1");
                $sheet->getRowDimension(1)->setRowHeight(24);
                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(false);
                $sheet->getStyle("B2:D{$lastRow}")->getAlignment()->setWrapText(true);
                $sheet->getStyle("O2:Q{$lastRow}")->getAlignment()->setWrapText(true);

                $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(28);
                $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(38);
                $sheet->getColumnDimension('D')->setAutoSize(false)->setWidth(20);
                $sheet->getColumnDimension('Q')->setAutoSize(false)->setWidth(28);
            },
        ];
    }
}
