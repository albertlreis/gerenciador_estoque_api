<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Exports\PedidosExport;
use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoStatusRequest;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Carrinho;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\LogService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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
    public function index(Request $request): JsonResponse
    {
        $query = Pedido::with(['cliente:id,nome', 'parceiro:id,nome', 'itens.variacao.produto:id,nome']);

        if (!AuthHelper::hasPermissao('pedidos.visualizar.todos')) {
            $query->where('id_usuario', AuthHelper::getUsuarioId());
        }

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
                LogService::warning('FiltroData', 'Data inválida recebida.', $request->only(['data_inicio', 'data_fim']));
                return response()->json(['erro' => 'Formato de data inválido. Use AAAA-MM-DD.'], 422);
            }
        }

        if ($request->filled('busca')) {
            $busca = strtolower($request->busca);
            $tipo = $request->get('tipo_busca', 'todos');

            $query->where(function ($q) use ($busca, $tipo) {
                if (in_array($tipo, ['todos', 'id']) && is_numeric($busca)) {
                    $q->orWhere('id', (int) $busca);
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

        $ordenarPor = in_array($request->get('ordenarPor'), ['data_pedido', 'numero', 'valor_total']) ? $request->get('ordenarPor') : 'data_pedido';
        $ordem = in_array($request->get('ordem'), ['asc', 'desc']) ? $request->get('ordem') : 'desc';

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
            LogService::warning('Pedido', 'Tentativa de criação com carrinho vazio.', ['carrinho_id' => $request->id_carrinho]);

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

                if ($request->boolean('modo_consignacao')) {
                    $this->registrarConsignacoes($pedido, $carrinho->itens, $request->prazo_consignacao);
                }
            }

            logAuditoria('pedido', "Pedido #$pedido->id criado com sucesso.", [
                'acao' => 'criação',
                'nivel' => 'info',
                'cliente' => $pedido->cliente?->nome,
                'valor_total' => $pedido->valor_total,
                'itens' => $pedido->itens->map(fn($item) => [
                    'produto' => optional($item->variacao->produto)->nome,
                    'variacao' => optional($item->variacao)->descricao,
                    'quantidade' => $item->quantidade,
                ]),
            ], $pedido);

            $carrinho->itens()->delete();

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
            ], 201);
        });
    }

    private function registrarConsignacoes(Pedido $pedido, $itens, int $prazoDias): void
    {
        foreach ($itens as $item) {
            Consignacao::create([
                'pedido_id' => $pedido->id,
                'produto_variacao_id' => $item->id_variacao,
                'quantidade' => $item->quantidade,
                'data_envio' => now(),
                'prazo_resposta' => now()->addDays($prazoDias),
                'status' => 'pendente',
            ]);
        }
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
        $status = PedidoStatus::from($request->status);

        // Impede duplicidade de status no mesmo dia
        $jaExiste = $pedido->historicoStatus()
            ->where('status', $status->value)
            ->whereDate('data_status', now()->toDateString())
            ->exists();

        if ($jaExiste) {
            LogService::info('StatusPedido', "Status '$status->value' já registrado hoje para o pedido #$pedido->id");

            return response()->json([
                'message' => 'Esse status já foi registrado hoje para este pedido.'
            ], 422);
        }

        // Atualiza o status principal do pedido (se desejar manter isso)
        $pedido->update(['status' => $status->value]);

        // Cria entrada no histórico
        $historico = PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => $status,
            'data_status' => now(),
            'usuario_id' => Auth::id(),
            'observacoes' => $request->observacoes,
        ]);

        logAuditoria('pedido_status', "Status do Pedido #$pedido->id atualizado para '$status->value'.", [
            'acao' => 'atualizacao',
            'nivel' => 'info',
            'novo_status' => $status->value,
            'observacoes' => $request->observacoes,
        ], $pedido);

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'historico' => $historico,
            'pedido' => $pedido->fresh(),
        ]);
    }

    public function exportar(Request $request): Response|BinaryFileResponse|JsonResponse
    {
        $formato = $request->query('formato');
        $detalhado = $request->boolean('detalhado', false);

        $pedidos = Pedido::with(['cliente', 'parceiro'])->get();

        if ($formato === 'excel') {
            logAuditoria('pedido_exportacao', "Exportação de pedidos no formato $formato.", [
                'acao' => 'exportacao',
                'nivel' => 'info',
                'formato' => $formato,
                'detalhado' => $detalhado,
            ]);

            return Excel::download(new PedidosExport($pedidos), 'pedidos.xlsx');
        }

        if ($formato === 'pdf') {
            $view = $detalhado ? 'exports.pedidos-pdf-detalhado' : 'exports.pedidos-pdf';
            $pdf = Pdf::loadView($view, ['pedidos' => $pedidos]);

            logAuditoria('pedido_exportacao', "Exportação de pedidos no formato $formato.", [
                'acao' => 'exportacao',
                'nivel' => 'info',
                'formato' => $formato,
                'detalhado' => $detalhado,
            ]);

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

        LogService::debug('EstatisticasPedido', 'Estatísticas calculadas', ['intervalo_meses' => $intervalo]);

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

        $cliente = $this->extrairCliente($texto);
        $pedido = $this->extrairPedido($texto);
        $itens = $this->extrairItens($texto);

        $itensComVariacao = collect($itens)->map(function ($item) {
            $variacao = ProdutoVariacao::query()
                ->where('referencia', $item['ref'] ?? '')
                ->when(!empty($item['nome']), fn($q) =>
                $q->whereHas('produto', fn($q2) =>
                $q2->where('nome', 'like', '%' . $item['nome'] . '%')
                )
                )
                ->first();

            return array_merge($item, [
                'id_variacao' => $variacao?->id,
                'produto_id' => $variacao?->produto_id,
                'variacao_nome' => $variacao?->descricao,
                'id_categoria' => $variacao?->produto?->id_categoria,
            ]);
        });

        LogService::info('ImportacaoPDF', 'Arquivo PDF lido com sucesso.', ['arquivo' => $request->file('arquivo')->getClientOriginalName()]);

        return response()->json([
            'cliente' => $cliente,
            'pedido' => $pedido,
            'itens' => $itensComVariacao,
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

        LogService::debug('ImportacaoPDF', 'Itens extraídos do PDF.', ['itens' => $itens]);
        return $itens;
    }

    private function processarBlocoProduto(string $bloco): ?array
    {
        $bloco = trim(preg_replace('/\s+/', ' ', $bloco));

        // Captura o valor no final
        if (!preg_match('/(?<valor>\d{1,3}(?:\.\d{3})*,\d{2})$/', $bloco, $valorMatch)) {
            Log::debug('Valor não encontrado:', ['bloco' => $bloco]);
            return null;
        }

        $valor = (float) str_replace(['.', ','], ['', '.'], $valorMatch['valor']);
        $blocoSemValor = trim(str_replace($valorMatch[0], '', $bloco));

        // Captura quantidade, tipo, ref e descrição
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
        $fixos = $produto['fixos'] ?? [
            'largura' => null,
            'profundidade' => null,
            'altura' => null,
        ];

        return [
            'descricao' => $descricao,
            'quantidade' => $quantidade,
            'valor' => $valor,
            'nome' => $produto['nome'],
            'tipo' => $tipo,
            'ref' => $ref,
            'atributos' => $produto['atributos'],
            'fixos' => $fixos,
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

    private function extrairPedido(string $texto, array $itens = []): array
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

        $numero = $this->extrairValor('/PEDIDO DE VENDA[:\s]*([0-9]+)/i', $texto, ''); // ajustado para aceitar \t e espaços invisíveis
        $dataVenda = $this->extrairValor('/DATA VENDA\s+(\d{2}\/\d{2}\/\d{4})/i', $texto);
        $vendedor = $this->extrairValor('/VENDEDOR RESPONSÁVEL\s+(.+)/i', $texto);
        $formaPagamento = $this->extrairValor('/FORMA DE PAGAMENTO[:\s]+(.+)/i', $texto) ?: 'À vista';

        $valorExtraido = $this->extrairValor('/VALOR FINAL\s*-\s*R\$[\s\u00a0]*([\d\.,]+)/iu', $texto);
        $valorTotal = null;
        if ($valorExtraido) {
            $valorTotal = (float) str_replace(['.', ','], ['', '.'], $valorExtraido);
        } elseif (!empty($itens)) {
            $valorTotal = array_reduce($itens, function ($soma, $item) {
                return $soma + ($item['quantidade'] * $item['valor']);
            }, 0.0);
        }

        $observacoes = $this->extrairValor('/- Observações:\s*(.+?)(?=\n\S|\z)/is', $texto);

        $dataEntregaEstimada = $this->extrairValor('/PRAZO DE ENTREGA\s*(.+?)(?=\nBAIRRO|\nCIDADE|\nCEP|\nDADOS DOS PRODUTOS|$)/i', $texto);

        $tipos = collect($itens)->pluck('tipo')->unique()->values()->all();
        $tipoPedido = match (true) {
            count($tipos) === 1 => $tipos[0],
            count($tipos) > 1 => 'MISTO',
            default => 'PEDIDO'
        };

        Log::debug('[extrairPedido] Detalhes extraídos do pedido', [
            'numero' => $numero,
            'data_venda' => $dataVenda,
            'vendedor' => $vendedor,
            'forma_pagamento' => $formaPagamento,
            'valor_total' => $valorTotal,
            'observacoes' => $observacoes,
            'data_entrega_estimada' => $dataEntregaEstimada,
            'tipo_pedido' => $tipoPedido,
            'parcelas' => $parcelas,
        ]);

        return [
            'numero' => $numero,
            'data_venda' => $dataVenda,
            'vendedor' => $vendedor,
            'forma_pagamento' => $formaPagamento,
            'valor_total' => $valorTotal,
            'observacoes' => $observacoes,
            'data_entrega_estimada' => $dataEntregaEstimada,
            'tipo_pedido' => $tipoPedido,
            'parcelas' => $parcelas,
        ];
    }

    private function extrairValor(string $pattern, string $texto, ?string $fallback = null): ?string
    {
        try {
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException("Regex inválido: $pattern");
            }

            if (preg_match($pattern, $texto, $match)) {
                return isset($match[1]) ? trim($match[1]) : $fallback;
            }

            LogService::debug('ExtracaoRegex', 'Valor não encontrado.');

            return $fallback;
        } catch (Throwable $e) {
            LogService::warning('ExtracaoRegex', 'Erro ao extrair valor com regex.');

            return $fallback;
        }
    }

    private function extrairProduto(string $descricao): array
    {
        $descricaoLimpa = preg_replace('/\s+/', ' ', mb_strtoupper($descricao, 'UTF-8'));
        $descricaoLimpa = preg_replace('/[^A-Z0-9\*\:\(\)\/\-\.\, ]+/u', '', $descricaoLimpa);

        $partes = explode('*', $descricaoLimpa, 2);
        $nome = isset($partes[0]) ? trim(preg_replace('/[^A-Z0-9 \/]/u', '', $partes[0])) : 'DESCONHECIDO';
        $nome = preg_replace('/\s+/', ' ', $nome); // evita colagem

        $restante = $partes[1] ?? '';
        $atributos = [
            'cores' => [],
            'tecidos' => [],
            'acabamentos' => [],
            'observacoes' => [],
        ];

        $observacaoExtra = $restante;

        // Mapas de atributos
        $mapas = [
            'cores' => [
                'COR DO FERRO' => 'cor_do_ferro',
                'COR INOX' => 'cor_inox',
                'COR' => 'cor',
            ],
            'tecidos' => [
                'TECIDO' => 'tecido',
                'TEC' => 'tec',
            ],
            'acabamentos' => [
                'PESP' => 'pesp',
                'MÁRMORE' => 'marmore',
                'MARMORE' => 'marmore',
            ],
        ];

        foreach ($mapas as $grupo => $chaves) {
            foreach ($chaves as $chaveOriginal => $chaveFinal) {
                if (preg_match('/' . preg_quote($chaveOriginal, '/') . ':\s*([^:*]+)(?=\s+[A-Z]{3,}|$)/u', $restante, $match)) {
                    $atributos[$grupo][$chaveFinal] = trim($match[1]);
                    $observacaoExtra = str_replace($match[0], '', $observacaoExtra);
                }
            }
        }

        // Medidas
        preg_match('/MED:\s*(.+?)(?=\s+[A-Z]{3,}|$)/u', $restante, $matchMedidas);
        if (!$matchMedidas && preg_match('/(\d{2,3})\s*[xXØ]\s*(\d{2,3})\s*[xXØ]\s*(\d{2,3})/', $descricaoLimpa, $matchFallback)) {
            $matchMedidas[1] = $matchFallback[0];
        }

        $medidas = $this->extrairMedidas($matchMedidas[1] ?? '');
        if (isset($matchMedidas[0])) {
            $observacaoExtra = str_replace($matchMedidas[0], '', $observacaoExtra);
        }

        // Observação entre parênteses
        if (preg_match('/\(([^)]+)\)/u', $descricao, $matchObs)) {
            $atributos['observacoes']['observacao'] = trim($matchObs[1]);
            $observacaoExtra = str_replace($matchObs[0], '', $observacaoExtra);
        }

        // Limpeza final da observação extra
        $extra = trim(preg_replace('/\s+/', ' ', $observacaoExtra));
        if ($extra !== '') {
            $atributos['observacoes']['observacao_extra'] = $extra;
        }

        return [
            'nome' => str_replace(' ', '', $nome), // opcional: pode retornar com espaços
            'atributos' => $atributos,
            'fixos' => [
                'largura' => $medidas[0],
                'profundidade' => $medidas[1],
                'altura' => $medidas[2],
            ],
        ];
    }

    private function extrairMedidas(string $texto): array
    {
        $texto = mb_strtoupper(trim($texto));
        $texto = preg_replace('/[^0-9xXØ]/u', '', $texto);
        $texto = str_replace(['Ø', 'X'], 'x', $texto);

        $partes = explode('x', $texto);
        $partes = array_map('trim', $partes);

        return [
            isset($partes[0]) ? (int) $partes[0] : null,
            isset($partes[1]) ? (int) $partes[1] : null,
            isset($partes[2]) ? (int) $partes[2] : null,
        ];
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
                $variacao = ProdutoVariacao::query()
                    ->where('referencia', $item['ref'] ?? '')
                    ->when(!empty($item['nome']), fn($q) =>
                    $q->whereHas('produto', fn($q2) =>
                    $q2->where('nome', 'like', '%' . $item['nome'] . '%')
                    )
                    )
                    ->first();

                // Se não encontrar, tenta criar
                if (!$variacao && !empty($item['ref']) && !empty($item['nome'])) {
                    if (empty($item['id_categoria'])) {
                        throw new Exception("Item '{$item['descricao']}' está sem categoria definida.");
                    }

                    $produto = Produto::firstOrCreate([
                        'nome' => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ]);

                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'],
                        'nome' => $item['descricao'],
                        'preco' => $item['valor'],
                        'custo' => $item['valor'],
                        'largura' => $item['fixos']['largura'] ?? null,
                        'profundidade' => $item['fixos']['profundidade'] ?? null,
                        'altura' => $item['fixos']['altura'] ?? null,
                    ]);
                }

                PedidoItem::create([
                    'id_pedido' => $pedido->id,
                    'id_variacao' => $variacao?->id,
                    'descricao_manual' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'preco_unitario' => $item['valor'],
                    'subtotal' => $item['quantidade'] * $item['valor'],
                ]);
            }

            logAuditoria('pedido', "Pedido importado via PDF para cliente '$cliente->nome'.", [
                'acao' => 'importacao',
                'nivel' => 'info',
                'cliente' => $cliente->nome,
                'numero_pdf' => $request->input('pedido.numero'),
                'valor_total' => $pedido->valor_total,
                'itens' => $itens,
            ], $pedido);

            return response()->json([
                'message' => 'Pedido importado e salvo com sucesso.',
                'pedido_id' => $pedido->id,
            ]);
        });
    }
}
