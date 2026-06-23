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
            ->with(['cliente', 'pedido.cliente', 'categoria', 'centroCusto']);

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
            $conta->cliente?->nome ?: $conta->pedido?->cliente?->nome,
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
