<?php

namespace App\Http\Controllers;

use App\Exports\PedidosExport;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoStatusRequest;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Carrinho;
use Carbon\Carbon;
use Exception;
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
    public function index(Request $request)
    {
        $query = Pedido::with(['cliente', 'parceiro', 'itens.variacao.produto']);

        // Filtros recebidos via query
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('data_inicio') || $request->filled('data_fim')) {
            try {
                $dataInicio = $request->filled('data_inicio') ? Carbon::parse($request->data_inicio)->startOfDay() : null;
                $dataFim = $request->filled('data_fim') ? Carbon::parse($request->data_fim)->endOfDay() : null;

                $query->when($dataInicio, fn($q) => $q->where('data_pedido', '>=', $dataInicio));
                $query->when($dataFim, fn($q) => $q->where('data_pedido', '<=', $dataFim));
            } catch (Exception) {
                return response()->json(['erro' => 'Formato de data inválido. Use AAAA-MM-DD.'], 422);
            }
        }

        if ($request->filled('busca')) {
            $busca = strtolower($request->busca);
            $tipo = $request->get('tipo_busca', 'todos'); // valores: cliente, parceiro, vendedor, todos

            $query->where(function ($q) use ($busca, $tipo) {
                if (in_array($tipo, ['todos', 'id'])) {
                    if (is_numeric($busca)) {
                        $q->orWhere('id', (int) $busca);
                    }
                }

                if (in_array($tipo, ['todos', 'cliente'])) {
                    $q->orWhereHas('cliente', fn($sub) =>
                    $sub->whereRaw('LOWER(nome) LIKE ?', ["%{$busca}%"])
                    );
                }

                if (in_array($tipo, ['todos', 'parceiro'])) {
                    $q->orWhereHas('parceiro', fn($sub) =>
                    $sub->whereRaw('LOWER(nome) LIKE ?', ["%{$busca}%"])
                    );
                }

                if (in_array($tipo, ['todos', 'vendedor', 'usuario'])) {
                    $q->orWhereHas('usuario', fn($sub) =>
                    $sub->whereRaw('LOWER(nome) LIKE ?', ["%{$busca}%"])
                    );
                }
            });
        }


        // Ordenação
        $ordenarPor = $request->get('ordenarPor', 'data_pedido');
        $ordem = $request->get('ordem', 'desc');

        $query->orderBy($ordenarPor, $ordem);

        $perPage = (int) $request->get('per_page', 10);
        $paginado = $query->paginate($perPage)->appends($request->query());

        $paginado->getCollection()->transform(function ($pedido) {
            return [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'data' => $pedido->data_pedido,
                'cliente' => $pedido->cliente,
                'parceiro' => $pedido->parceiro,
                'valor_total' => $pedido->valor_total,
                'status' => $pedido->status,
                'observacoes' => $pedido->observacoes,
                'produtos' => $pedido->itens->map(function ($item) {
                    return [
                        'nome' => $item->variacao->produto->nome ?? '-',
                        'variacao' => $item->variacao->descricao ?? '-',
                        'quantidade' => $item->quantidade,
                        'preco_unitario' => $item->preco_unitario,
                        'subtotal' => $item->subtotal,
                    ];
                })
            ];
        });

        return response()->json($paginado);
    }


    public function store(StorePedidoRequest $request)
    {
        $usuarioLogado = Auth::user();

        $idUsuarioFinal = $usuarioLogado->perfil === 'Administrador'
            ? ($request->id_usuario ?? $usuarioLogado->id)
            : $usuarioLogado->id;

        $carrinho = Carrinho::where('id_usuario', $usuarioLogado->id)->with('itens')->first();

        if (!$carrinho || $carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho vazio.'], 422);
        }

        return DB::transaction(function () use ($request, $carrinho, $idUsuarioFinal) {
            $total = $carrinho->itens->sum('subtotal');

            $pedido = Pedido::create([
                'id_cliente'   => $request->id_cliente,
                'id_usuario'   => $idUsuarioFinal,
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

    public function estatisticas(Request $request): JsonResponse
    {
        $intervalo = (int) $request->query('meses', 6);

        $meses = collect(range(0, $intervalo - 1))
            ->map(fn($i) => Carbon::now()->subMonths($i)->startOfMonth())
            ->reverse();

        $dados = DB::table('pedidos')
            ->selectRaw("DATE_FORMAT(data_pedido, '%Y-%m-01') as mes, COUNT(*) as total, SUM(valor_total) as valor")
            ->where('data_pedido', '>=', $meses->first()->format('Y-m-d'))
            ->whereNotNull('data_pedido')
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        $labels = [];
        $quantidades = [];
        $valores = [];

        foreach ($meses as $mes) {
            $chave = $mes->format('Y-m-01');
            $label = $mes->format('M/Y');

            $linha = $dados->firstWhere('mes', $chave);

            $labels[] = $label;
            $quantidades[] = $linha->total ?? 0;
            $valores[] = (float) ($linha->valor ?? 0);
        }

        return response()->json([
            'labels' => $labels,
            'quantidades' => $quantidades,
            'valores' => $valores,
        ]);
    }
}
