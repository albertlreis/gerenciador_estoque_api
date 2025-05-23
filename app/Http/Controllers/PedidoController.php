<?php

namespace App\Http\Controllers;

use App\Exports\PedidosExport;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoStatusRequest;
use App\Models\Cliente;
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
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Smalot\PdfParser\Parser;
use Throwable;

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

        $carrinho = Carrinho::where('id', $request->id_carrinho)
            ->where('id_usuario', $usuarioLogado->id)
            ->with('itens')
            ->first();

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
            'data_pedido' => $pedido->data_pedido,
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

    /**
     * @throws \Exception
     */
    public function importarPDF(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:pdf',
        ]);

        $path = $request->file('arquivo')->getRealPath();

        $parser = new Parser();
        $pdf = $parser->parseFile($path);
        $texto = $pdf->getText();

        // Texto para debug
        Log::debug('Texto PDF bruto extraído:', ['texto' => $texto]);

        $cliente = $this->extrairCliente($texto);
        $pedido = $this->extrairPedido($texto);
        $itens = $this->extrairItens($texto);

        return response()->json([
            'cliente' => $cliente,
            'pedido' => $pedido,
            'itens' => $itens,
        ]);
    }

    private function extrairItens(string $texto): array
    {
        $itens = [];

        // Pré-processamento: normaliza quebras de linha e espaços
        $linhas = preg_split('/\r\n|\n|\r/', $texto);
        $bloco = '';
        $padraoQuantidade = '/^\d{1,2}\.\d{4}/';
        $padraoValor = '/\d{1,3}(?:\.\d{3})*,\d{2}$/';

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;

            // Se começa com quantidade, é o início de um novo produto
            if (preg_match($padraoQuantidade, $linha)) {
                // Salva bloco anterior se houver
                if (!empty($bloco)) {
                    $item = $this->processarBlocoProduto($bloco);
                    if ($item) $itens[] = $item;
                    $bloco = '';
                }
            }

            $bloco .= ' ' . $linha;

            // Se terminar com um valor, provavelmente fechamos um item
            if (preg_match($padraoValor, $linha)) {
                $item = $this->processarBlocoProduto($bloco);
                if ($item) $itens[] = $item;
                $bloco = '';
            }
        }

        // Bloco final
        if (!empty($bloco)) {
            $item = $this->processarBlocoProduto($bloco);
            if ($item) $itens[] = $item;
        }

        Log::debug('Itens extraídos:', ['itens' => $itens]);
        return $itens;
    }

    private function processarBlocoProduto(string $bloco): ?array
    {
        $bloco = trim(preg_replace('/\s+/', ' ', $bloco));

        // Tenta capturar valor no final (mesmo colado)
        if (!preg_match('/(?<valor>\d{1,3}(?:\.\d{3})*,\d{2})$/', $bloco, $valorMatch)) {
            Log::debug('Valor não encontrado:', ['bloco' => $bloco]);
            return null;
        }

        $valor = (float) str_replace(['.', ','], ['', '.'], $valorMatch['valor']);

        // Remove o valor do bloco para facilitar os outros matches
        $blocoSemValor = trim(str_replace($valorMatch[0], '', $bloco));

        // Tenta capturar quantidade, tipo, ref, e descrição
        if (!preg_match('/^(?<quantidade>\d{1,2}\.\d{4})\s*(?:(?<tipo>PEDIDO|PRONTA\s+ENTREGA))?\s*(?<ref>[A-Z0-9]+)?\s+(?<descricao>.+)$/i', $blocoSemValor, $match)) {
            Log::debug('Bloco não reconhecido como produto:', ['bloco' => $bloco]);
            return null;
        }

        $quantidade = (float) str_replace(',', '.', $match['quantidade']);
        $descricao = trim($match['descricao']);
        $tipo = strtoupper(trim($match['tipo'] ?? ''));
        $ref = strtoupper(trim($match['ref'] ?? ''));

        if ($quantidade <= 0 || $valor <= 0 || strlen($descricao) < 10) {
            return null;
        }

        $produto = $this->extrairProduto($descricao);
        $medidas = $this->extrairMedidas($produto['atributos']['medidas'] ?? '');

        return [
            'descricao' => $descricao,
            'quantidade' => $quantidade,
            'valor' => $valor,
            'nome' => $produto['nome'],
            'tipo' => $tipo,
            'ref' => $ref,
            'atributos' => $produto['atributos'],
            'fixos' => [
                'largura' => $medidas[0],
                'profundidade' => $medidas[1],
                'altura' => $medidas[2],
            ],
        ];
    }

    private function extrairCliente(string $texto): array
    {
        return [
            'nome' => $this->extrairValor('/CLIENTE\s+(.+)/', $texto),
            'documento' => $this->extrairValor('/CPF\s+([0-9\.\-\/]+)/', $texto),
            'endereco' => $this->extrairValor('/ENDEREÇO\s+(.+)/', $texto),
            'bairro' => $this->extrairValor('/BAIRRO\s+(.+)/', $texto),
            'cidade' => $this->extrairValor('/CIDADE\s+(.+)/', $texto),
            'cep' => $this->extrairValor('/CEP\s+([\d\-]+)/', $texto),
            'telefone' => $this->extrairValor('/CELULAR\s+([\(\)\d\s\-]+)/', $texto),
            'email' => $this->extrairValor('/E-MAIL\s+([^\s]+)/', $texto),
            'endereco_entrega' => $this->extrairValor('/ENDEREÇO DE ENTREGA\s+(.+)/', $texto),
            'prazo_entrega' => $this->extrairValor('/PRAZO DE ENTREGA\s+(.+?)(?=\s+BAIRRO|CIDADE|CEP|$)/s', $texto),
        ];
    }

    private function extrairPedido(string $texto): array
    {
        $parcelas = [];
        if (preg_match_all('/(\w+)\s+(\d{2}\/\d{2}\/\d{4})\s+(\w+)\s+([\d\.]+,\d{2})/', $texto, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parcelas[] = [
                    'descricao' => $match[1],
                    'vencimento' => $match[2],
                    'forma' => $match[3],
                    'valor' => (float) str_replace(['.', ','], ['', '.'], $match[4]),
                ];
            }
        }

        return [
            'data_venda' => $this->extrairValor('/DATA VENDA\s+(\d{2}\/\d{2}\/\d{4})/', $texto),
            'vendedor' => $this->extrairValor('/VENDEDOR RESPONSÁVEL\s+(.+)/', $texto),
            'forma_pagamento' => $this->extrairValor('/FORMA DE PAGAMENTO\s+(.+)/', $texto) ?: 'À vista',
            'parcelas' => $parcelas,
        ];
    }

    private function extrairProduto(string $descricao): array
    {
        $descricao = preg_replace('/\s+/', ' ', mb_strtoupper($descricao, 'UTF-8'));

        // Nome antes do primeiro atributo ou medida
        preg_match('/^(.*?)(\* COR|\bCOR:|\bTEC:|\bMED:|\bØ|\d{2,3} X \d{2,3})/', $descricao, $matchNome);
        $nome = trim($matchNome[1] ?? $descricao);

        preg_match('/COR(?:[^:]*):\s*([^\*]+?)(?=\s+[A-Z]+:|\s*$)/', $descricao, $cor);
        preg_match('/TEC(?:IDO)?[^:]*:\s*([^\*]+?)(?=\s+[A-Z]+:|\s*$)/', $descricao, $tecido);
        preg_match('/MED[^:]*:\s*([^\*]+?)(?=\s+[A-Z]+:|\s*$)/', $descricao, $medidas);
        preg_match('/PESP[^:]*:\s*([^\*]+?)(?=\s+[A-Z]+:|\s*$)/', $descricao, $pesp);
        preg_match('/MARMORE[^:]*:\s*([^\*]+?)(?=\s+[A-Z]+:|\s*$)/', $descricao, $marmore);

        return [
            'nome' => $nome,
            'atributos' => [
                'cor' => trim($cor[1] ?? ''),
                'tecido' => trim($tecido[1] ?? ''),
                'medidas' => trim($medidas[1] ?? ''),
                'pesponto' => trim($pesp[1] ?? ''),
                'marmore' => trim($marmore[1] ?? ''),
            ],
            'fixos' => [
                'altura' => null,
                'largura' => null,
                'profundidade' => null,
            ],
        ];
    }

    private function extrairMedidas(string $texto): array
    {
        $texto = preg_replace('/[^\d xXØ]/', '', strtoupper($texto));
        $partes = preg_split('/[xXØ]/', $texto);

        return [
            isset($partes[2]) ? (int) trim($partes[2]) : null, // Altura
            isset($partes[0]) ? (int) trim($partes[0]) : null, // Largura
            isset($partes[1]) ? (int) trim($partes[1]) : null, // Profundidade
        ];
    }

    private function extrairValor(string $pattern, string $texto): string
    {
        preg_match($pattern, $texto, $match);
        return isset($match[1]) ? trim($match[1]) : '';
    }

    public function confirmarImportacaoPDF(Request $request): JsonResponse
    {
        $request->validate([
            'cliente.nome' => 'required|string|max:255',
            'cliente.documento' => 'required|string|max:20',
            'pedido.numero' => 'nullable|string|max:50',
            'pedido.vendedor' => 'nullable|string|max:255',
            'pedido.total' => 'nullable|numeric',
            'pedido.observacoes' => 'nullable|string',
            'itens' => 'required|array|min:1',
            'itens.*.descricao' => 'required|string',
            'itens.*.quantidade' => 'required|numeric|min:0.01',
            'itens.*.valor' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $dadosCliente = $request->cliente;
            $dadosPedido = $request->pedido;
            $itens = $request->itens;
            $usuario = Auth::user();

            // Verifica se cliente já existe
            $cliente = Cliente::firstOrCreate(
                ['documento' => $dadosCliente['documento']],
                [
                    'nome' => $dadosCliente['nome'],
                    'email' => $dadosCliente['email'] ?? null,
                    'telefone' => $dadosCliente['telefone'] ?? null,
                    'endereco' => $dadosCliente['endereco'] ?? null,
                ]
            );

            // Cria o pedido
            $pedido = Pedido::create([
                'id_cliente' => $cliente->id,
                'id_usuario' => $usuario->id,
                'data_pedido' => now(),
                'numero' => $dadosPedido['numero'] ?? null,
                'status' => 'confirmado',
                'valor_total' => $dadosPedido['total'] ?? array_sum(array_map(fn($item) => $item['quantidade'] * $item['valor'], $itens)),
                'observacoes' => $dadosPedido['observacoes'] ?? null,
            ]);

            // Cria os itens do pedido
            foreach ($itens as $item) {
                PedidoItem::create([
                    'id_pedido' => $pedido->id,
                    'id_variacao' => null, // Sem vínculo direto com variação ainda
                    'descricao_manual' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'preco_unitario' => $item['valor'],
                    'subtotal' => $item['quantidade'] * $item['valor'],
                ]);
            }

            return response()->json([
                'message' => 'Pedido importado e salvo com sucesso.',
                'pedido_id' => $pedido->id,
            ]);
        });
    }
}
