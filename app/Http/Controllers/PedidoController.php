<?php

namespace App\Http\Controllers;

use App\Exports\PedidosExport;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoStatusRequest;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Carrinho;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controller responsável por criar e gerenciar pedidos.
 */
class PedidoController extends Controller
{
    public function index()
    {
        $pedidos = Pedido::with(['cliente', 'parceiro', 'itens.variacao.produto'])->get();

        return $pedidos->map(function ($pedido) {
            return [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'data' => $pedido->data_pedido,
                'cliente' => $pedido->cliente,
                'parceiro' => $pedido->parceiro,
                'total' => $pedido->valor_total,
                'status' => $pedido->status,
                'observacoes' => $pedido->observacoes,
                'produtos' => $pedido->itens->map(function ($item) {
                    return [
                        'nome' => $item->variacao->produto->nome ?? '-',
                        'variacao' => $item->variacao->descricao ?? '-',
                        'quantidade' => $item->quantidade,
                        'valor' => $item->valor,
                    ];
                })
            ];
        });
    }

    public function store(StorePedidoRequest $request)
    {
        $usuarioId = Auth::id();

        $carrinho = Carrinho::where('id_usuario', $usuarioId)->with('itens')->first();

        if (!$carrinho || $carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho vazio.'], 422);
        }

        return DB::transaction(function () use ($request, $carrinho, $usuarioId) {
            $total = $carrinho->itens->sum('subtotal');

            $pedido = Pedido::create([
                'id_cliente'   => $request->id_cliente,
                'id_usuario'   => $usuarioId,
                'id_parceiro'  => $request->id_parceiro,
                'data_pedido'  => now(),
                'status'       => 'confirmado',
                'valor_total'  => $total,
                'observacoes'  => $request->observacoes,
            ]);

            foreach ($carrinho->itens as $item) {
                PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $item->id_variacao,
                    'quantidade'     => $item->quantidade,
                    'preco_unitario' => $item->preco_unitario,
                    'subtotal'       => $item->subtotal,
                ]);
            }

            // Limpa o carrinho após finalizar o pedido
            $carrinho->itens()->delete();

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
            ], 201);
        });
    }

    public function show($id): array
    {
        $pedido = Pedido::with(['cliente', 'parceiro', 'itens.variacao.produto'])->findOrFail($id);

        return [
            'id' => $pedido->id,
            'numero' => $pedido->numero,
            'data' => $pedido->data,
            'cliente' => $pedido->cliente,
            'parceiro' => $pedido->parceiro,
            'total' => $pedido->total,
            'status' => $pedido->status,
            'observacoes' => $pedido->observacoes,
            'produtos' => $pedido->itens->map(function ($item) {
                return [
                    'nome' => $item->variacao->produto->nome ?? '-',
                    'variacao' => $item->variacao->descricao ?? '-',
                    'quantidade' => $item->quantidade,
                    'valor' => $item->valor,
                ];
            })
        ];
    }

    public function updateStatus(UpdatePedidoStatusRequest $request, $id): JsonResponse
    {
        $pedido = Pedido::findOrFail($id);
        $pedido->update(['status' => $request->status]);

        return response()->json(['message' => 'Status atualizado com sucesso.', 'pedido' => $pedido]);
    }

    public function exportar(Request $request): Response|BinaryFileResponse|JsonResponse
    {
        $formato = $request->query('formato');
        $detalhado = $request->boolean('detalhado', false);

        $pedidos = Pedido::with(['cliente', 'parceiro'])->get();

        if ($formato === 'excel') {
            return Excel::download(new PedidosExport($pedidos), 'pedidos.xlsx');
        }

        if ($formato === 'pdf') {
            $view = $detalhado ? 'exports.pedidos-pdf-detalhado' : 'exports.pedidos-pdf';
            $pdf = Pdf::loadView($view, ['pedidos' => $pedidos]);
            return $pdf->download($detalhado ? 'pedidos-detalhado.pdf' : 'pedidos.pdf');
        }

        return response()->json(['erro' => 'Formato inválido'], 400);
    }

    public function estatisticas(): JsonResponse
    {
        // Últimos 6 meses
        $meses = collect(range(0, 5))->map(function ($i) {
            return Carbon::now()->subMonths($i)->startOfMonth();
        })->reverse();

        // Buscar pedidos agrupados por mês no MySQL
        $dados = DB::table('pedidos')
            ->selectRaw("DATE_FORMAT(data_pedido, '%Y-%m-01') as mes, COUNT(*) as total")
            ->where('data_pedido', '>=', $meses->first()->format('Y-m-d'))
            ->whereNotNull('data_pedido')
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        // Mapear todos os meses com os resultados
        $labels = [];
        $valores = [];

        foreach ($meses as $mes) {
            $chave = $mes->format('Y-m-01');
            $label = $mes->format('M/Y');

            $quantidade = $dados->firstWhere('mes', $chave)->total ?? 0;

            $labels[] = $label;
            $valores[] = (int) $quantidade;
        }

        return response()->json([
            'labels' => $labels,
            'valores' => $valores,
        ]);
    }

}
