<?php

namespace App\Exports;

use App\Models\ContaReceber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

class ContasReceberExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private readonly array $params = []) {}

    public function collection(): Collection
    {
        return self::query($this->params)->get();
    }

    public static function queryFromRequest(Request $request): Builder
    {
        return self::query($request->all());
    }

    public static function query(array $params): Builder
    {
        $q = ContaReceber::query()
            ->with(['cliente', 'pedido.cliente', 'categoria', 'centroCusto', 'pagamentos']);

        if (!empty($params['busca'])) {
            $busca = '%' . trim((string) $params['busca']) . '%';
            $q->where(fn ($w) => $w
                ->where('descricao', 'like', $busca)
                ->orWhere('numero_documento', 'like', $busca)
            );
        }

        if (!empty($params['status'])) {
            $q->where('status', (string) $params['status']);
        }

        if (!empty($params['cliente'])) {
            $cliente = '%' . trim((string) $params['cliente']) . '%';
            $q->where(fn ($w) => $w
                ->whereHas('cliente', fn ($c) => $c->where('nome', 'like', $cliente))
                ->orWhereHas('pedido.cliente', fn ($c) => $c->where('nome', 'like', $cliente))
            );
        }

        if (!empty($params['cliente_id'])) {
            $clienteId = (int) $params['cliente_id'];
            $q->where(fn ($w) => $w
                ->where('cliente_id', $clienteId)
                ->orWhereHas('pedido', fn ($p) => $p->where('id_cliente', $clienteId))
            );
        }

        if (!empty($params['numero_pedido'])) {
            $numeroPedido = '%' . trim((string) $params['numero_pedido']) . '%';
            $q->whereHas('pedido', fn ($p) => $p->where('numero_externo', 'like', $numeroPedido));
        }

        $dataInicio = $params['data_ini'] ?? $params['data_inicio'] ?? null;
        $dataFim = $params['data_fim'] ?? null;

        if ($dataInicio) {
            $q->whereDate('data_vencimento', '>=', $dataInicio);
        }

        if ($dataFim) {
            $q->whereDate('data_vencimento', '<=', $dataFim);
        }

        if (array_key_exists('vencidas', $params) && filter_var($params['vencidas'], FILTER_VALIDATE_BOOLEAN)) {
            $q->whereDate('data_vencimento', '<', now()->toDateString())
                ->whereNotIn('status', ['PAGA', 'CANCELADA']);
        }

        if (array_key_exists('em_aberto', $params) && filter_var($params['em_aberto'], FILTER_VALIDATE_BOOLEAN)) {
            $q->whereNotIn('status', ['PAGA', 'CANCELADA']);
        }

        if (!empty($params['forma_recebimento'])) {
            $q->where('forma_recebimento', (string) $params['forma_recebimento']);
        }

        if (!empty($params['centro_custo_id'])) {
            $q->where('centro_custo_id', (int) $params['centro_custo_id']);
        }

        if (!empty($params['categoria_id'])) {
            $q->where('categoria_id', (int) $params['categoria_id']);
        }

        if (!empty($params['conta_financeira_id'])) {
            $contaFinanceiraId = (int) $params['conta_financeira_id'];
            $q->whereHas('pagamentos', fn ($pagamento) => $pagamento->where('conta_financeira_id', $contaFinanceiraId));
        }

        if (($params['origem'] ?? null) === 'recorrente') {
            $q->whereNotNull('despesa_recorrente_id');
        }

        return $q->orderBy('data_vencimento')->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Cliente',
            'Pedido',
            'Descricao',
            'No Doc',
            'Emissao',
            'Vencimento',
            'Valor Liquido',
            'Recebido',
            'Saldo',
            'Status',
            'Forma',
            'Categoria',
            'Centro de custo',
        ];
    }

    public function map($conta): array
    {
        $liquido = (float) $conta->valor_liquido;
        $recebido = $conta->relationLoaded('pagamentos')
            ? (float) $conta->pagamentos->sum('valor')
            : (float) $conta->valor_recebido;
        $saldo = max(0, $liquido - $recebido);

        return [
            $conta->id,
            $conta->cliente?->nome ?: $conta->pedido?->cliente?->nome,
            $conta->pedido?->numero_externo,
            $conta->descricao,
            $conta->numero_documento,
            optional($conta->data_emissao)->format('d/m/Y'),
            optional($conta->data_vencimento)->format('d/m/Y'),
            $liquido,
            $recebido,
            $saldo,
            $conta->status?->value ?? $conta->status,
            $conta->forma_recebimento,
            $conta->categoria?->nome,
            $conta->centroCusto?->nome,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => NumberFormat::FORMAT_NUMBER_00,
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
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

        $sheet->getStyle("B2:E{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("L2:N{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("H2:J{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

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
                $sheet->getStyle("B2:E{$lastRow}")->getAlignment()->setWrapText(true);
                $sheet->getStyle("L2:N{$lastRow}")->getAlignment()->setWrapText(true);

                $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(28);
                $sheet->getColumnDimension('D')->setAutoSize(false)->setWidth(38);
                $sheet->getColumnDimension('E')->setAutoSize(false)->setWidth(20);
                $sheet->getColumnDimension('N')->setAutoSize(false)->setWidth(28);
            },
        ];
    }
}
