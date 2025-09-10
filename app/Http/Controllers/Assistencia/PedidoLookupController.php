<?php

namespace App\Http\Controllers\Assistencia;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use Illuminate\Http\Request;

class PedidoLookupController extends Controller
{
    public function buscar(Request $r)
    {
        $q = trim((string) $r->input('q'));

        $rows = Pedido::query()
            ->with('cliente:id,nome')
            ->when($q, function($query) use ($q) {
                $q2 = mb_strtolower($q);
                $query->where(function($w) use ($q2) {
                    $w->where('numero_externo', 'like', "%{$q2}%")
                        ->orWhereHas('cliente', fn($c) => $c->where('nome','like',"%{$q2}%"));
                });
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id','numero_externo','data_pedido','id_cliente']);

        $rows->transform(function($p) {
            return [
                'id'             => $p->id,
                'numero_externo' => $p->numero_externo,
                'data_pedido'    => $p->data_pedido,
                'cliente'        => ['id' => $p->cliente?->id, 'nome' => $p->cliente?->nome],
            ];
        });

        return response()->json($rows);
    }

    public function produtos(Pedido $pedido)
    {
        $pedido->load([
            'itens.variacao:id,produto_id,referencia,nome',
            'itens.variacao.produto:id,nome',

            // ✅ Qualifica as colunas do related (pedidos_fabrica) para evitar "Column 'id' ... is ambiguous"
            'pedidosFabrica' => function ($q) {
                $q->select(
                    'pedidos_fabrica.id',
                    'pedidos_fabrica.status',
                    'pedidos_fabrica.data_previsao_entrega'
                );
                // Observação: o Laravel acrescenta automaticamente
                // pedidos_fabrica_itens.pedido_venda_id AS laravel_through_key
                // para manter a associação no hasManyThrough.
            },
        ]);

        $itensFmt = $pedido->itens->map(function ($i) {
            $v = $i->variacao;
            $p = $v?->produto;
            return [
                'id'         => $i->id,
                'quantidade' => $i->quantidade,
                'variacao'   => $v ? [
                    'id'            => $v->id,
                    'nome'          => $v->nome,
                    'nome_completo' => $v->nome_completo ?? trim(($p?->nome ?? '').($v->nome ? " - {$v->nome}" : '')),
                    'produto_id'    => $v->produto_id,
                    'referencia'    => $v->referencia,
                ] : null,
                'produto'    => $p ? [
                    'id'   => $p->id,
                    'nome' => $p->nome,
                ] : null,
            ];
        });

        $pedidosFabricaFmt = $pedido->pedidosFabrica
            ->unique('id')
            ->map(fn ($pf) => [
                'id'                    => $pf->id,
                'status'                => $pf->status,
                'data_previsao_entrega' => optional($pf->data_previsao_entrega)->toDateString(),
            ])
            ->values();

        return response()->json([
            'pedido' => [
                'id'       => $pedido->id,
                'numero'   => $pedido->numero_externo,
                'data'     => $pedido->data_pedido,
                'cliente'  => $pedido->cliente?->nome,
                'pedidos_fabrica' => $pedidosFabricaFmt,
            ],
            'itens'  => $itensFmt,
        ]);
    }

}
