<?php

namespace App\Exports;

use App\Models\ContaReceber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContasReceberExport implements FromCollection, WithHeadings, WithMapping
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
            ->with(['pedido.cliente', 'categoria', 'centroCusto']);

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
            $q->whereHas('pedido.cliente', fn ($c) => $c->where('nome', 'like', $cliente));
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
                ->where('status', '!=', 'PAGA');
        }

        if (!empty($params['forma_recebimento'])) {
            $q->where('forma_recebimento', (string) $params['forma_recebimento']);
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
        return [
            $conta->id,
            $conta->pedido?->cliente?->nome,
            $conta->pedido?->numero_externo,
            $conta->descricao,
            $conta->numero_documento,
            optional($conta->data_emissao)->format('d/m/Y'),
            optional($conta->data_vencimento)->format('d/m/Y'),
            (float) $conta->valor_liquido,
            (float) $conta->valor_recebido,
            (float) $conta->saldo_aberto,
            $conta->status?->value ?? $conta->status,
            $conta->forma_recebimento,
            $conta->categoria?->nome,
            $conta->centroCusto?->nome,
        ];
    }
}
