<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Enums\PedidoStatus;
use App\Http\Resources\ConsignacaoDetalhadaResource;
use App\Http\Resources\ConsignacaoResource;
use App\Models\AcessoUsuario;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\ConsignacaoCompra;
use App\Models\ConsignacaoDevolucao;
use App\Models\Parceiro;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoVariacao;
use App\Services\EstoqueMovimentacaoService;
use App\Services\EntregaProdutoService;
use App\Services\DesfazerConsignacaoService;
use App\Services\PdfImageService;
use App\Support\Pdf\ClienteEnderecoPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ConsignacaoController extends Controller
{

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'data_ini' => 'nullable|date_format:Y-m-d',
            'data_fim' => 'nullable|date_format:Y-m-d',
            'parceiro_id' => 'nullable|integer|exists:parceiros,id',
        ]);

        $query = Consignacao::with([
            'pedido.cliente:id,nome',
            'pedido.usuario:id,nome',
            'pedido.parceiro:id,nome',
            'pedido.statusAtual',
            'produtoVariacao.produto',
            'compras',
            'devolucoes',
        ])
            ->withSum([
                'devolucoes as devolvido_total' => fn ($q) => $q->whereNull('consignacao_devolucoes.cancelada_em'),
            ], 'quantidade');

        if (!AuthHelper::podeVisualizarConsignacoesDeTodos()) {
            $query->whereHas('pedido', function ($q) {
                $q->where('id_usuario', AuthHelper::getUsuarioId());
            });
        }

        if ($request->filled('cliente_id')) {
            $query->whereHas('pedido.cliente', function ($q) use ($request) {
                $q->where('id', $request->cliente_id);
            });
        }

        if ($request->filled('produto')) {
            $produto = $request->produto;
            $query->whereHas('produtoVariacao.produto', function ($q) use ($produto) {
                $q->where('nome', 'like', "%$produto%");
            });
        }

        if ($request->filled('vencimento_proximo')) {
            $query->whereDate('prazo_resposta', '<=', now()->addDays(3));
        }

        if ($request->filled('vendedor_id')) {
            $query->whereHas('pedido', function ($q) use ($request) {
                $q->where('id_usuario', $request->vendedor_id);
            });
        }

        if ($request->filled('parceiro_id')) {
            $query->whereHas('pedido', function ($q) use ($request) {
                $q->where('id_parceiro', $request->parceiro_id);
            });
        }

        if ($request->filled('data_ini')) {
            $query->whereDate('prazo_resposta', '>=', $request->input('data_ini'));
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('prazo_resposta', '<=', $request->input('data_fim'));
        }

        if (in_array($request->status, ['pendente', 'vencido'])) {
            $query->where('status', 'pendente');
        }

        $query->orderBy('prazo_resposta');

        $consignacoes = $query->get();

        $agrupadas = $consignacoes->groupBy('pedido_id')->map(function ($grupo) {
            $primeira = $grupo->first();
            $primeira->todas_consignacoes = $grupo;
            return $primeira;
        });

        if ($request->filled('status')) {
            $statusDesejado = $request->status;
            $hoje = now();

            $agrupadas = $agrupadas->filter(function ($consignacao) use ($statusDesejado, $hoje) {
                $status = 'pendente';
                $temPendente = false;
                $temComprado = false;
                $temDevolvido = false;
                $temParcial = false;

                foreach ($consignacao->todas_consignacoes as $item) {
                    if ($item->status === 'pendente') {
                        if ($item->prazo_resposta && $item->prazo_resposta->lt($hoje)) {
                            $status = 'vencido';
                            break;
                        }
                        $temPendente = true;
                    }
                    if ($item->status === 'comprado') $temComprado = true;
                    if ($item->status === 'devolvido') $temDevolvido = true;
                    if ($item->status === 'parcial') $temParcial = true;
                }

                if ($temPendente) {
                    $status = 'pendente';
                    if ($consignacao->todas_consignacoes->where('status', 'pendente')->pluck('prazo_resposta')->contains(fn($p) => $p && $p->lt($hoje))) {
                        $status = 'vencido';
                    }
                } elseif ($temParcial || ($temComprado && $temDevolvido)) {
                    $status = 'parcial';
                } elseif ($temComprado) {
                    $status = 'comprado';
                } elseif ($temDevolvido) {
                    $status = 'devolvido';
                }

                return $status === $statusDesejado;
            });
        }

        $pagina = $request->get('page', 1);
        $porPagina = $request->get('per_page', 10);
        $paginado = new LengthAwarePaginator(
            $agrupadas->forPage($pagina, $porPagina)->values(),
            $agrupadas->count(),
            $porPagina,
            $pagina
        );

        return ConsignacaoResource::collection($paginado);
    }

    public function porPedido($pedido_id): JsonResponse
    {
        $consignacoes = Consignacao::with([
            'pedido.cliente.enderecos',
            'produtoVariacao.produto.imagemPrincipal',
            'produtoVariacao.atributos',
            'compras.usuario',
            'compras.canceladaPor',
            'devolucoes.usuario',
            'devolucoes.canceladaPor',
            'entregaItem',
            'movimentacoes.usuario',
            'movimentacoes.depositoOrigem',
            'movimentacoes.depositoDestino',
        ])
            ->where('pedido_id', $pedido_id)
            ->get();

        if ($consignacoes->isEmpty()) {
            return response()->json(['erro' => 'Nenhuma consignação encontrada para este pedido.'], 404);
        }

        $pedido = $consignacoes->first()->pedido;

        return response()->json([
            'pedido' => [
                'id' => $pedido->id,
                'numero_externo' => $pedido->numero_externo,
                'cliente' => [
                    'id' => $pedido->cliente?->id,
                    'nome' => $pedido->cliente?->nome ?? '-',
                    'enderecos' => ClienteEnderecoPdf::paraResposta($pedido->cliente),
                ],
                'data_envio' => optional($pedido->data_envio)->format('d/m/Y'),
            ],
            'consignacoes' => ConsignacaoDetalhadaResource::collection($consignacoes)
        ]);
    }

    public function adicionarItensAoPedido(Pedido $pedido, Request $request): JsonResponse
    {
        if (!AuthHelper::hasPermissao('consignacoes.gerenciar')) {
            return response()->json(['message' => 'Sem permissao para gerenciar consignacoes.'], 403);
        }

        $validated = $request->validate([
            'prazo_resposta' => 'required|date_format:Y-m-d',
            'itens' => 'required|array|min:1',
            'itens.*.id_variacao' => 'required|integer|exists:produto_variacoes,id',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.preco_unitario' => 'required|numeric|min:0',
            'itens.*.id_deposito' => 'required|integer|exists:depositos,id',
            'itens.*.observacoes' => 'nullable|string|max:1000',
        ]);

        $usuarioId = AuthHelper::getUsuarioId();

        DB::transaction(function () use ($pedido, $validated, $usuarioId) {
            /** @var Pedido $pedidoAtual */
            $pedidoAtual = Pedido::query()
                ->with(['statusAtual', 'consignacoes'])
                ->lockForUpdate()
                ->findOrFail($pedido->id);

            $statusAtual = $pedidoAtual->statusAtual?->getRawOriginal('status') ?? $pedidoAtual->statusAtual?->status;
            if ($statusAtual === PedidoStatus::CANCELADO->value) {
                abort(422, 'Nao e possivel adicionar produtos a um pedido cancelado.');
            }

            if (!$pedidoAtual->consignacoes->count()) {
                abort(422, 'Pedido sem consignacoes para receber novos produtos.');
            }

            $prazoResposta = (string) $validated['prazo_resposta'];
            $consignacoesCriadas = [];
            $totalAdicionar = 0.0;

            foreach ($validated['itens'] as $item) {
                $quantidade = (int) $item['quantidade'];
                $preco = (float) $item['preco_unitario'];
                $subtotal = round($quantidade * $preco, 2);
                $totalAdicionar += $subtotal;

                /** @var ProdutoVariacao $variacao */
                $variacao = ProdutoVariacao::query()->findOrFail((int) $item['id_variacao']);

                $pedidoItem = PedidoItem::query()->create([
                    'id_pedido' => $pedidoAtual->id,
                    'id_variacao' => $variacao->id,
                    'quantidade' => $quantidade,
                    'preco_unitario' => $preco,
                    'subtotal' => $subtotal,
                    'id_deposito' => (int) $item['id_deposito'],
                    'observacoes' => $item['observacoes'] ?? null,
                ]);

                $consignacao = Consignacao::query()->create([
                    'pedido_id' => $pedidoAtual->id,
                    'pedido_item_id' => $pedidoItem->id,
                    'produto_variacao_id' => $variacao->id,
                    'deposito_id' => (int) $item['id_deposito'],
                    'quantidade' => $quantidade,
                    'data_envio' => now('America/Belem')->toDateString(),
                    'prazo_resposta' => $prazoResposta,
                    'status' => 'pendente',
                ]);

                $consignacoesCriadas[] = $consignacao;
            }

            $pedidoAtual->valor_total = round((float) $pedidoAtual->valor_total + $totalAdicionar, 2);
            $pedidoAtual->save();

            app(EntregaProdutoService::class)->reconciliarPedidoEditado(
                $pedidoAtual,
                $usuarioId ? (int) $usuarioId : null
            );

            $entregas = app(EntregaProdutoService::class);
            foreach ($consignacoesCriadas as $consignacao) {
                $central = $entregas->criarDemandaConsignacao(
                    $consignacao,
                    $usuarioId ? (int) $usuarioId : null
                );

                $entregas->reservarItem(
                    $central,
                    $consignacao->deposito_id,
                    null,
                    $usuarioId ? (int) $usuarioId : null,
                    "Reserva inicial da consignacao #{$consignacao->id}",
                    "consignacao:{$consignacao->id}:reserva-inicial"
                );
            }

            if ($statusAtual !== PedidoStatus::CONSIGNADO->value) {
                PedidoStatusHistorico::query()->create([
                    'pedido_id' => $pedidoAtual->id,
                    'status' => PedidoStatus::CONSIGNADO,
                    'data_status' => now('America/Belem'),
                    'usuario_id' => $usuarioId,
                    'observacoes' => 'Produtos adicionados a consignacao.',
                ]);
            }
        });

        $consignacoes = $this->carregarConsignacoesDetalhadasDoPedido((int) $pedido->id);
        $pedidoAtualizado = Pedido::with('cliente')->findOrFail($pedido->id);

        return response()->json([
            'mensagem' => 'Produtos adicionados a consignacao com sucesso.',
            'pedido' => [
                'id' => $pedidoAtualizado->id,
                'numero_externo' => $pedidoAtualizado->numero_externo,
                'cliente' => $pedidoAtualizado->cliente->nome ?? '-',
                'data_envio' => optional($pedidoAtualizado->data_envio)->format('d/m/Y'),
            ],
            'consignacoes' => ConsignacaoDetalhadaResource::collection($consignacoes),
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $consignacao = Consignacao::with([
            'pedido.cliente',
            'pedido.usuario',
            'produtoVariacao.produto.imagemPrincipal',
            'produtoVariacao.atributos',
            'compras',
            'compras.usuario',
            'compras.canceladaPor',
            'devolucoes',
            'devolucoes.usuario',
            'devolucoes.canceladaPor',
            'entregaItem',
            'movimentacoes.usuario',
            'movimentacoes.depositoOrigem',
            'movimentacoes.depositoDestino',
        ])->findOrFail($id);

        return response()->json(new ConsignacaoDetalhadaResource($consignacao));
    }

    public function desfazer(int $id, DesfazerConsignacaoService $service): JsonResponse
    {
        if (!AuthHelper::hasPermissao('consignacoes.gerenciar')) {
            return response()->json(['message' => 'Sem permissao para gerenciar consignacoes.'], 403);
        }

        $resultado = $service->desfazerItem($id, AuthHelper::getUsuarioId());

        return response()->json([
            'mensagem' => 'Consignacao desfeita com sucesso.',
            ...$resultado,
        ]);
    }

    public function desfazerPedido(Pedido $pedido, DesfazerConsignacaoService $service): JsonResponse
    {
        if (!AuthHelper::hasPermissao('consignacoes.gerenciar')) {
            return response()->json(['message' => 'Sem permissao para gerenciar consignacoes.'], 403);
        }

        $resultado = $service->desfazerPedido($pedido, AuthHelper::getUsuarioId());

        return response()->json([
            'mensagem' => $resultado['consignacoes_desfeitas'] === 1
                ? 'Consignacao desfeita com sucesso.'
                : "{$resultado['consignacoes_desfeitas']} consignacoes desfeitas com sucesso.",
            ...$resultado,
        ]);
    }

    public function atualizarStatus($id, Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:comprado,devolvido',
        ]);

        $consignacao = Consignacao::with(['pedido', 'devolucoes', 'compras'])->findOrFail($id);

        if ($request->status === 'devolvido') {
            return response()->json([
                'erro' => 'Registre a devolucao informando deposito para que o estoque passe pelo fluxo central.',
            ], 422);
        }

        // Se já finalizada, não permitir alteração
        if (in_array($consignacao->status, ['comprado', 'devolvido'])) {
            return response()->json(['erro' => 'Consignação já finalizada.'], 422);
        }

        DB::transaction(function () use ($consignacao, $request) {
            if ($request->status === 'comprado') {
                $restante = $consignacao->quantidadeDisponivelCliente();
                if ($restante <= 0) {
                    abort(422, 'Consignação sem quantidade disponível para venda.');
                }

                $compra = $consignacao->compras()->create([
                    'quantidade' => $restante,
                    'observacoes' => null,
                    'usuario_id' => AuthHelper::getUsuarioId(),
                ]);

                $this->registrarVendaCentralConsignacao($consignacao, $restante, AuthHelper::getUsuarioId(), null, (int) $compra->id);
                $this->recalcularStatusConsignacao($consignacao->fresh(['devolucoes', 'compras']));
                $this->marcarPedidoConsignacaoComoVendido($consignacao->pedido);
            } else {
                $consignacao->status = $request->status;
                $consignacao->data_resposta = now();
                $consignacao->save();
            }
        });

        return response()->json([
            'mensagem' => 'Status atualizado com sucesso.',
            'consignacao' => new ConsignacaoDetalhadaResource($consignacao->fresh(['pedido.cliente', 'produtoVariacao.produto', 'devolucoes', 'compras'])),
        ]);
    }

    public function vencendo(): JsonResponse
    {
        $query = Consignacao::with(['pedido.cliente', 'produtoVariacao.produto'])
            ->where('status', 'pendente')
            ->whereDate('prazo_resposta', '<=', now()->addDays(2))
            ->orderBy('prazo_resposta');

        if (!AuthHelper::hasPermissao('consignacoes.vencendo.todos')) {
            $query->whereHas('pedido', function ($q) {
                $q->where('id_usuario', AuthHelper::getUsuarioId());
            });
        }

        $consignacoes = $query->get();

        return response()->json(ConsignacaoResource::collection($consignacoes));
    }

    public function registrarEnvio($id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantidade' => 'nullable|integer|min:1',
            'observacoes' => 'nullable|string',
        ]);

        $usuarioId = AuthHelper::getUsuarioId();

        $resultado = DB::transaction(function () use ($id, $validated, $usuarioId) {
            $consignacao = Consignacao::with(['devolucoes', 'compras', 'produtoVariacao.produto'])
                ->lockForUpdate()
                ->findOrFail($id);

            $pendente = $consignacao->quantidadePendenteEnvio();
            $quantidade = isset($validated['quantidade']) ? (int) $validated['quantidade'] : $pendente;

            if ($pendente <= 0) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Toda a quantidade desta consignacao ja foi enviada ao cliente.',
                ];
            }

            if ($quantidade > $pendente) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => "Quantidade maior que o pendente de envio ({$pendente}).",
                ];
            }

            $this->registrarEnvioCentralConsignacao(
                $consignacao,
                $quantidade,
                $usuarioId,
                $validated['observacoes'] ?? null
            );

            return ['ok' => true, 'processados' => 1];
        });

        if (!$resultado['ok']) {
            return response()->json([
                'message' => $resultado['message'],
                'erro' => $resultado['message'],
            ], $resultado['status']);
        }

        return response()->json(['mensagem' => 'Envio de consignacao registrado com sucesso.', 'processados' => 1]);
    }

    public function registrarEnviosEmMassa(int $pedidoId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'observacoes' => 'nullable|string',
            'itens' => 'required|array|min:1',
            'itens.*.consignacao_id' => 'required|integer|min:1|distinct',
            'itens.*.quantidade' => 'nullable|integer|min:1',
        ]);

        Pedido::findOrFail($pedidoId);

        $itensPayload = collect($validated['itens'])
            ->mapWithKeys(fn ($item) => [(int) $item['consignacao_id'] => isset($item['quantidade']) ? (int) $item['quantidade'] : null]);
        $consignacaoIds = $itensPayload->keys()->values();
        $usuarioId = AuthHelper::getUsuarioId();
        $observacoes = $validated['observacoes'] ?? null;

        $resultado = DB::transaction(function () use ($pedidoId, $consignacaoIds, $itensPayload, $usuarioId, $observacoes) {
            $consignacoes = Consignacao::with(['devolucoes', 'compras', 'produtoVariacao.produto'])
                ->where('pedido_id', $pedidoId)
                ->whereIn('id', $consignacaoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $invalidos = [];
            $quantidades = [];

            foreach ($consignacaoIds as $consignacaoId) {
                $consignacao = $consignacoes->get($consignacaoId);
                if (!$consignacao) {
                    $invalidos[] = [
                        'id' => $consignacaoId,
                        'motivo' => 'Item nao encontrado neste pedido.',
                    ];
                    continue;
                }

                $pendente = $consignacao->quantidadePendenteEnvio();
                $quantidadeSolicitada = $itensPayload->get($consignacaoId);
                $quantidade = $quantidadeSolicitada !== null ? (int) $quantidadeSolicitada : $pendente;

                if ($pendente <= 0) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => 'Toda a quantidade ja foi enviada ao cliente.',
                    ];
                    continue;
                }

                if ($quantidade > $pendente) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => "Quantidade maior que o pendente de envio ({$pendente}).",
                    ];
                    continue;
                }

                $quantidades[$consignacaoId] = $quantidade;
            }

            if ($invalidos !== []) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Alguns produtos nao podem ter envio registrado. Atualize a tela e tente novamente.',
                    'itens_invalidos' => $invalidos,
                ];
            }

            foreach ($consignacaoIds as $consignacaoId) {
                $this->registrarEnvioCentralConsignacao(
                    $consignacoes->get($consignacaoId),
                    $quantidades[$consignacaoId],
                    $usuarioId,
                    $observacoes
                );
            }

            return [
                'ok' => true,
                'processados' => $consignacaoIds->count(),
            ];
        });

        if (!$resultado['ok']) {
            return response()->json([
                'message' => $resultado['message'],
                'erro' => $resultado['message'],
                'itens_invalidos' => $resultado['itens_invalidos'],
            ], $resultado['status']);
        }

        $processados = (int) $resultado['processados'];

        return response()->json([
            'mensagem' => $processados === 1
                ? 'Envio de consignacao registrado com sucesso.'
                : "{$processados} envios de consignacao registrados com sucesso.",
            'processados' => $processados,
        ]);
    }

    public function registrarDevolucao($id, Request $request): JsonResponse
    {
        $request->validate([
            'quantidade' => 'required|integer|min:1',
            'observacoes' => 'nullable|string',
            'deposito_id' => 'required|exists:depositos,id',
        ]);

        $consignacao = Consignacao::with(['devolucoes', 'compras'])->findOrFail($id);

        if (!in_array($consignacao->status, ['pendente', 'parcial'], true)) {
            return response()->json(['erro' => 'Não é possível registrar devolução para consignação finalizada.'], 422);
        }

        $restante = $consignacao->quantidadeDisponivelCliente();

        if ($request->quantidade > $restante) {
            return response()->json(['erro' => "Quantidade devolvida excede o saldo enviado ao cliente ({$restante})."], 422);
        }

        $usuarioId = AuthHelper::getUsuarioId();

        DB::transaction(function () use ($consignacao, $request, $usuarioId) {
            $devolucao = $consignacao->devolucoes()->create([
                'quantidade' => $request->quantidade,
                'observacoes' => $request->observacoes,
                'usuario_id' => AuthHelper::getUsuarioId(),
                'estoque_movimentacao_id' => null,
                'deposito_id' => $request->deposito_id,
            ]);

            $entregas = app(EntregaProdutoService::class);
            $central = $entregas->criarDemandaConsignacao($consignacao, $usuarioId ? (int) $usuarioId : null);
            $key = "consignacao-devolucao:{$devolucao->id}";
            $entregas->receberItem(
                $central,
                (int) $request->deposito_id,
                (int) $request->quantidade,
                $usuarioId ? (int) $usuarioId : null,
                $request->observacoes,
                $key,
                ProdutoEntregaEvento::RETORNADO_CONSIGNACAO
            );

            $evento = ProdutoEntregaEvento::query()->where('idempotency_key', $key)->first();
            $devolucao->update(['estoque_movimentacao_id' => $evento?->estoque_movimentacao_id]);

            $this->recalcularStatusConsignacao($consignacao->fresh(['devolucoes', 'compras']));
        });

        return response()->json(['mensagem' => 'Devolução registrada com sucesso.']);
    }

    public function registrarDevolucoesEmMassa(int $pedidoId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deposito_id' => 'required|exists:depositos,id',
            'observacoes' => 'nullable|string',
            'itens' => 'required|array|min:1',
            'itens.*.consignacao_id' => 'required|integer|min:1|distinct',
            'itens.*.quantidade' => 'required|integer|min:1',
        ]);

        Pedido::findOrFail($pedidoId);

        $itensPayload = collect($validated['itens'])
            ->mapWithKeys(fn ($item) => [(int) $item['consignacao_id'] => (int) $item['quantidade']]);
        $consignacaoIds = $itensPayload->keys()->values();
        $usuarioId = AuthHelper::getUsuarioId();
        $depositoId = (int) $validated['deposito_id'];
        $observacoes = $validated['observacoes'] ?? null;

        $resultado = DB::transaction(function () use ($pedidoId, $consignacaoIds, $itensPayload, $usuarioId, $depositoId, $observacoes) {
            $consignacoes = Consignacao::with(['devolucoes', 'compras', 'produtoVariacao.produto'])
                ->where('pedido_id', $pedidoId)
                ->whereIn('id', $consignacaoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $invalidos = [];

            foreach ($consignacaoIds as $consignacaoId) {
                $consignacao = $consignacoes->get($consignacaoId);
                if (!$consignacao) {
                    $invalidos[] = [
                        'id' => $consignacaoId,
                        'motivo' => 'Item nao encontrado neste pedido.',
                    ];
                    continue;
                }

                $quantidade = (int) $itensPayload->get($consignacaoId);
                $restante = $consignacao->quantidadeDisponivelCliente();

                if (!in_array($consignacao->status, ['pendente', 'parcial'], true)) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => 'Item ja finalizado.',
                    ];
                    continue;
                }

                if ($restante <= 0) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => 'Sem quantidade enviada ao cliente disponivel para devolucao.',
                    ];
                    continue;
                }

                if ($quantidade > $restante) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => "Quantidade maior que o disponivel ({$restante}).",
                    ];
                }
            }

            if ($invalidos !== []) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Alguns produtos nao podem ser devolvidos. Atualize a tela e tente novamente.',
                    'itens_invalidos' => $invalidos,
                ];
            }

            foreach ($consignacaoIds as $consignacaoId) {
                /** @var Consignacao $consignacao */
                $consignacao = $consignacoes->get($consignacaoId);
                $quantidade = (int) $itensPayload->get($consignacaoId);
                $devolucao = $consignacao->devolucoes()->create([
                    'quantidade' => $quantidade,
                    'observacoes' => $observacoes,
                    'usuario_id' => $usuarioId,
                    'estoque_movimentacao_id' => null,
                    'deposito_id' => $depositoId,
                ]);

                $entregas = app(EntregaProdutoService::class);
                $central = $entregas->criarDemandaConsignacao($consignacao, $usuarioId ? (int) $usuarioId : null);
                $key = "consignacao-devolucao:{$devolucao->id}";
                $entregas->receberItem(
                    $central,
                    $depositoId,
                    $quantidade,
                    $usuarioId ? (int) $usuarioId : null,
                    $observacoes,
                    $key,
                    ProdutoEntregaEvento::RETORNADO_CONSIGNACAO
                );

                $evento = ProdutoEntregaEvento::query()->where('idempotency_key', $key)->first();
                $devolucao->update(['estoque_movimentacao_id' => $evento?->estoque_movimentacao_id]);

                $this->recalcularStatusConsignacao($consignacao->fresh(['devolucoes', 'compras']));
            }

            return [
                'ok' => true,
                'processados' => $consignacaoIds->count(),
            ];
        });

        if (!$resultado['ok']) {
            return response()->json([
                'message' => $resultado['message'],
                'erro' => $resultado['message'],
                'itens_invalidos' => $resultado['itens_invalidos'],
            ], $resultado['status']);
        }

        $processados = (int) $resultado['processados'];

        return response()->json([
            'mensagem' => $processados === 1
                ? 'Devolucao registrada com sucesso.'
                : "{$processados} devolucoes registradas com sucesso.",
            'processados' => $processados,
        ]);
    }

    public function confirmarComprasEmMassa(int $pedidoId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'observacoes' => 'nullable|string',
            'itens' => 'required_without:consignacao_ids|array|min:1',
            'itens.*.consignacao_id' => 'required_with:itens|integer|min:1|distinct',
            'itens.*.quantidade' => 'required_with:itens|integer|min:1',
            'consignacao_ids' => 'required_without:itens|array|min:1',
            'consignacao_ids.*' => 'required_with:consignacao_ids|integer|min:1|distinct',
        ]);

        $pedido = Pedido::findOrFail($pedidoId);
        $itensPayload = collect($validated['itens'] ?? [])
            ->mapWithKeys(fn ($item) => [(int) $item['consignacao_id'] => (int) $item['quantidade']]);
        $idsLegados = collect($validated['consignacao_ids'] ?? [])
            ->map(fn ($id) => (int) $id);
        $consignacaoIds = $itensPayload->keys()
            ->merge($idsLegados)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();
        $usuarioId = AuthHelper::getUsuarioId();
        $observacoes = $validated['observacoes'] ?? null;

        $resultado = DB::transaction(function () use ($pedido, $pedidoId, $consignacaoIds, $itensPayload, $usuarioId, $observacoes) {
            $consignacoes = Consignacao::with(['devolucoes', 'compras', 'produtoVariacao.produto'])
                ->where('pedido_id', $pedidoId)
                ->whereIn('id', $consignacaoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $invalidos = [];
            $quantidades = [];

            foreach ($consignacaoIds as $consignacaoId) {
                $consignacao = $consignacoes->get($consignacaoId);
                if (!$consignacao) {
                    $invalidos[] = [
                        'id' => $consignacaoId,
                        'motivo' => 'Item nao encontrado neste pedido.',
                    ];
                    continue;
                }

                if (in_array($consignacao->status, ['comprado', 'devolvido'], true)) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => 'Item ja finalizado.',
                    ];
                    continue;
                }

                $restante = $consignacao->quantidadeDisponivelCliente();
                $quantidade = $itensPayload->has($consignacaoId)
                    ? (int) $itensPayload->get($consignacaoId)
                    : $restante;

                if ($restante <= 0) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => 'Sem quantidade enviada ao cliente disponivel para venda.',
                    ];
                    continue;
                }

                if ($quantidade > $restante) {
                    $invalidos[] = [
                        'id' => $consignacao->id,
                        'nome' => $this->nomeConsignacao($consignacao),
                        'motivo' => "Quantidade maior que o disponivel ({$restante}).",
                    ];
                    continue;
                }

                $quantidades[$consignacaoId] = $quantidade;
            }

            if ($invalidos !== []) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Alguns produtos nao podem ter venda confirmada. Atualize a tela e tente novamente.',
                    'itens_invalidos' => $invalidos,
                ];
            }

            foreach ($consignacaoIds as $consignacaoId) {
                /** @var Consignacao $consignacao */
                $consignacao = $consignacoes->get($consignacaoId);
                $compra = $consignacao->compras()->create([
                    'quantidade' => $quantidades[$consignacaoId],
                    'observacoes' => $observacoes,
                    'usuario_id' => $usuarioId,
                ]);

                $this->registrarVendaCentralConsignacao($consignacao, $quantidades[$consignacaoId], $usuarioId, $observacoes, (int) $compra->id);
                $this->recalcularStatusConsignacao($consignacao->fresh(['devolucoes', 'compras']));
            }

            $this->marcarPedidoConsignacaoComoVendido($pedido);

            return [
                'ok' => true,
                'processados' => $consignacaoIds->count(),
            ];
        });

        if (!$resultado['ok']) {
            return response()->json([
                'message' => $resultado['message'],
                'erro' => $resultado['message'],
                'itens_invalidos' => $resultado['itens_invalidos'],
            ], $resultado['status']);
        }

        $processados = (int) $resultado['processados'];

        return response()->json([
            'mensagem' => $processados === 1
                ? 'Venda confirmada com sucesso.'
                : "{$processados} vendas confirmadas com sucesso.",
            'processados' => $processados,
        ]);
    }

    public function cancelarDevolucao(int $consignacaoId, int $devolucaoId, Request $request): JsonResponse
    {
        $request->validate([
            'motivo' => 'nullable|string',
        ]);

        $usuarioId = AuthHelper::getUsuarioId();
        $consignacao = Consignacao::with('devolucoes')->findOrFail($consignacaoId);
        $devolucao = ConsignacaoDevolucao::query()
            ->where('consignacao_id', $consignacao->id)
            ->findOrFail($devolucaoId);

        if ($devolucao->cancelada_em) {
            return response()->json(['erro' => 'Devolucao ja cancelada.'], 422);
        }

        if (!$devolucao->estoque_movimentacao_id) {
            return response()->json([
                'erro' => 'Esta devolucao nao possui movimentacao vinculada. Faca a correcao manual do estoque antes de cancelar.',
            ], 422);
        }

        DB::transaction(function () use ($consignacao, $devolucao, $request, $usuarioId) {
            $observacao = "Cancelamento da devolucao #{$devolucao->id} da consignacao #{$consignacao->id}";

            if (!$this->estornarEventoCentralPorMovimentacao((int) $devolucao->estoque_movimentacao_id, $usuarioId, $observacao)) {
                app(EstoqueMovimentacaoService::class)->estornarMovimentacao(
                    (int) $devolucao->estoque_movimentacao_id,
                    $usuarioId ? (int) $usuarioId : null,
                    $observacao
                );
            }

            $devolucao->update([
                'cancelada_em' => now(),
                'cancelada_por' => $usuarioId,
                'motivo_cancelamento' => $request->motivo,
            ]);

            $this->recalcularStatusConsignacao($consignacao->fresh(['devolucoes', 'compras']));
        });

        return response()->json(['mensagem' => 'Devolucao cancelada com sucesso.']);
    }

    public function cancelarVenda(int $id, Request $request): JsonResponse
    {
        if (!AuthHelper::hasPerfil('Administrador')) {
            return response()->json(['erro' => 'Apenas administradores podem cancelar venda de consignacao.'], 403);
        }

        $request->validate([
            'motivo' => 'nullable|string',
        ]);

        $consignacao = Consignacao::with(['devolucoes', 'compras'])->findOrFail($id);
        if ($consignacao->quantidadeComprada() <= 0) {
            return response()->json(['erro' => 'Apenas itens com venda registrada podem ter a venda cancelada.'], 422);
        }

        DB::transaction(function () use ($consignacao, $request) {
            $usuarioId = AuthHelper::getUsuarioId();
            $this->estornarVendaCentralConsignacao($consignacao, $usuarioId ? (int) $usuarioId : null, $request->motivo);

            $comprasCanceladas = ConsignacaoCompra::query()
                ->where('consignacao_id', $consignacao->id)
                ->whereNull('cancelada_em')
                ->update([
                    'cancelada_em' => now(),
                    'cancelada_por' => AuthHelper::getUsuarioId(),
                    'motivo_cancelamento' => $request->motivo,
                    'updated_at' => now(),
                ]);

            if ($comprasCanceladas === 0 && $consignacao->status === 'comprado') {
                $consignacao->status = 'pendente';
                $consignacao->data_resposta = null;
                $consignacao->save();
                return;
            }

            $this->recalcularStatusConsignacao($consignacao->fresh(['devolucoes', 'compras']));
        });

        logAuditoria('consignacao_cancelamento_venda', "Venda da consignacao #{$consignacao->id} cancelada.", [
            'acao' => 'cancelar_venda_item',
            'consignacao_id' => $consignacao->id,
            'pedido_id' => $consignacao->pedido_id,
            'motivo' => $request->motivo,
        ], $consignacao);

        return response()->json([
            'mensagem' => 'Venda da consignacao cancelada com sucesso.',
            'consignacao' => new ConsignacaoDetalhadaResource($consignacao->fresh([
                'pedido.cliente',
                'produtoVariacao.produto',
                'compras.usuario',
                'compras.canceladaPor',
                'devolucoes.usuario',
                'devolucoes.canceladaPor',
            ])),
        ]);
    }

    /**
     * Gera o PDF do roteiro de consignação.
     *
     * Inclui: parceiro, imagem do produto, e localização de estoque.
     */
    public function gerarPdf(int $id, Request $request): Response
    {
        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'cliente.enderecos',
            'usuario',
            'parceiro',
            'statusAtual',
            'consignacoes.deposito',
            'consignacoes.produtoVariacao.imagem',
            'consignacoes.produtoVariacao.produto.imagemPrincipal',
            'consignacoes.produtoVariacao.produto',
            'consignacoes.produtoVariacao.atributos',
            'consignacoes.produtoVariacao.estoquesComLocalizacao',
        ])->findOrFail($id);
        $enderecoEntrega = ClienteEnderecoPdf::resolverParaPedido(
            $pedido,
            $request->query('cliente_endereco_id')
        );

        $consignacaoIds = collect((array) $request->query('consignacao_ids', []))
            ->merge((array) $request->query('consignacoes', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($consignacaoIds->isNotEmpty()) {
            $pedido->setRelation(
                'consignacoes',
                $pedido->consignacoes->whereIn('id', $consignacaoIds)->values()
            );
        }

        $pdfImageService = app(PdfImageService::class);
        $pedido->consignacoes->each(function ($consignacao) use ($pdfImageService) {
            $consignacao->setAttribute(
                'pdf_imagem_data_uri',
                $pdfImageService->fromProdutoVariacaoOrPlaceholder($consignacao->produtoVariacao)
            );
        });

        $grupos = $pedido->consignacoes->groupBy(fn($item) => $item->deposito->nome ?? 'Sem depósito');
        $tipoRoteiro = $this->normalizarTipoRoteiro($request->query('tipo_roteiro'));
        $isDevolucao = $tipoRoteiro
            ? $tipoRoteiro === 'devolucao'
            : $this->isRoteiroDeDevolucao($pedido);
        $tituloRoteiro = $isDevolucao ? 'Roteiro de devolução' : 'Roteiro de consignação';
        $filename = $isDevolucao
            ? "roteiro-de-devolucao-{$id}.pdf"
            : "roteiro-de-consignacao-{$id}.pdf";

        logAuditoria('consignacao_pdf', 'Geração de PDF de roteiro de consignação', [
            'acao' => 'gerar_pdf',
            'pedido_id' => $id,
            'documento' => $tituloRoteiro,
        ]);

        // Permitir imagens locais/externas
        Pdf::setOptions(['isRemoteEnabled' => true]);

        $pdf = Pdf::loadView('exports.roteiro-consignacao', [
            'pedido'     => $pedido,
            'grupos'     => $grupos,
            'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
            'tituloRoteiro' => $tituloRoteiro,
            'enderecoEntrega' => $enderecoEntrega,
        ])->setPaper('a4');

        return $pdf->download($filename);
    }

    private function isRoteiroDeDevolucao(Pedido $pedido): bool
    {
        $statusAtual = $pedido->statusAtual?->getRawOriginal('status') ?? $pedido->statusAtual?->status;
        if ($statusAtual === 'devolucao_consignacao') {
            return true;
        }

        if ($pedido->consignacoes->isEmpty()) {
            return false;
        }

        return $pedido->consignacoes->every(function ($item) {
            $status = strtolower((string) ($item->status ?? ''));
            return in_array($status, ['devolvido', 'comprado', 'finalizado'], true);
        });
    }

    private function carregarConsignacoesDetalhadasDoPedido(int $pedidoId)
    {
        return Consignacao::with([
            'pedido.cliente',
            'produtoVariacao.produto.imagemPrincipal',
            'produtoVariacao.atributos',
            'compras.usuario',
            'compras.canceladaPor',
            'devolucoes.usuario',
            'devolucoes.canceladaPor',
            'movimentacoes.usuario',
            'movimentacoes.depositoOrigem',
            'movimentacoes.depositoDestino',
        ])
            ->where('pedido_id', $pedidoId)
            ->get();
    }

    private function registrarEnvioCentralConsignacao(Consignacao $consignacao, int $quantidade, ?int $usuarioId, ?string $observacoes): void
    {
        if ($quantidade <= 0) {
            return;
        }

        $entregas = app(EntregaProdutoService::class);
        $central = $entregas->criarDemandaConsignacao($consignacao, $usuarioId);
        $marcador = $consignacao->quantidadeEnviada();

        $entregas->expedirItem(
            $central,
            $consignacao->deposito_id,
            $quantidade,
            $usuarioId,
            $observacoes ?: "Envio de consignacao #{$consignacao->id}",
            ProdutoEntregaEvento::ENVIADO_CONSIGNACAO,
            "consignacao:{$consignacao->id}:envio:{$marcador}"
        );
    }

    private function registrarVendaCentralConsignacao(Consignacao $consignacao, int $quantidade, ?int $usuarioId, ?string $observacoes, ?int $marcadorEvento = null): void
    {
        if ($quantidade <= 0) {
            return;
        }

        $entregas = app(EntregaProdutoService::class);
        $central = $entregas->criarDemandaConsignacao($consignacao, $usuarioId);
        $marcador = $marcadorEvento ?? $consignacao->quantidadeComprada();

        $entregas->entregarItem(
            $central,
            $quantidade,
            $usuarioId,
            $observacoes ?: "Venda de consignacao confirmada #{$consignacao->id}",
            "consignacao:{$consignacao->id}:venda:{$marcador}:entrega"
        );
    }

    private function estornarEventoCentralPorMovimentacao(int $movimentacaoId, ?int $usuarioId, string $observacao): bool
    {
        $evento = ProdutoEntregaEvento::query()
            ->where('estoque_movimentacao_id', $movimentacaoId)
            ->where('tipo_evento', ProdutoEntregaEvento::RETORNADO_CONSIGNACAO)
            ->first();

        if (!$evento) {
            return false;
        }

        app(EntregaProdutoService::class)->estornarEvento($evento, $usuarioId, $observacao);

        return true;
    }

    private function estornarVendaCentralConsignacao(Consignacao $consignacao, ?int $usuarioId, ?string $motivo): void
    {
        $eventos = ProdutoEntregaEvento::query()
            ->whereHas('item', function ($query) use ($consignacao) {
                $query->where('tipo_origem', 'consignacao')
                    ->where('consignacao_id', $consignacao->id);
            })
            ->whereIn('tipo_evento', [
                ProdutoEntregaEvento::ENTREGUE_CLIENTE,
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($eventos as $evento) {
            app(EntregaProdutoService::class)->estornarEvento(
                $evento,
                $usuarioId,
                $motivo ?: "Cancelamento da venda da consignacao #{$consignacao->id}"
            );
        }
    }

    private function recalcularStatusConsignacao(Consignacao $consignacao): void
    {
        $devolvido = $consignacao->quantidadeDevolvida();
        $comprado = $consignacao->quantidadeComprada();
        $total = (int) $consignacao->quantidade;
        $respondido = $devolvido + $comprado;

        if ($respondido >= $total && $comprado > 0 && $devolvido > 0) {
            $consignacao->status = 'parcial';
            $consignacao->data_resposta = $consignacao->data_resposta ?: now();
        } elseif ($comprado >= $total) {
            $consignacao->status = 'comprado';
            $consignacao->data_resposta = $consignacao->data_resposta ?: now();
        } elseif ($devolvido >= $total) {
            $consignacao->status = 'devolvido';
            $consignacao->data_resposta = $consignacao->data_resposta ?: now();
        } elseif (in_array($consignacao->status, ['comprado', 'devolvido', 'parcial'], true)) {
            $consignacao->status = 'pendente';
            $consignacao->data_resposta = null;
        }

        $consignacao->save();
    }

    private function nomeConsignacao(Consignacao $consignacao): string
    {
        return $consignacao->produtoVariacao?->nome_completo
            ?? $consignacao->produtoVariacao?->produto?->nome
            ?? "Consignacao #{$consignacao->id}";
    }

    private function marcarPedidoConsignacaoComoVendido(?Pedido $pedido): void
    {
        if (!$pedido) {
            return;
        }

        $jaFinalizado = $pedido->historicoStatus()
            ->where('status', PedidoStatus::FINALIZADO->value)
            ->exists();

        if ($jaFinalizado) {
            return;
        }

        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO,
            'data_status' => now('America/Belem'),
            'usuario_id' => AuthHelper::getUsuarioId(),
            'observacoes' => 'Venda de consignacao registrada. Pedido original convertido para venda.',
        ]);
    }

    private function normalizarTipoRoteiro(mixed $tipo): ?string
    {
        if ($tipo === null || $tipo === '') {
            return null;
        }

        $tipo = strtolower((string) $tipo);
        abort_unless(in_array($tipo, ['consignacao', 'devolucao'], true), 422, 'Tipo de roteiro invalido.');

        return $tipo;
    }


    public function clientes(): JsonResponse
    {
        $clientes = Cliente::whereHas('pedidos.consignacoes')
            ->select('id', 'nome')
            ->distinct()
            ->orderBy('nome')
            ->get();

        return response()->json($clientes);
    }

    public function vendedores(): JsonResponse
    {
        $vendedores = AcessoUsuario::whereHas('pedidos.consignacoes')
            ->select('id', 'nome')
            ->distinct()
            ->orderBy('nome')
            ->get();

        return response()->json($vendedores);
    }

    public function parceiros(): JsonResponse
    {
        $pedidosQuery = Pedido::query()
            ->whereHas('consignacoes')
            ->whereNotNull('id_parceiro');

        if (!AuthHelper::podeVisualizarConsignacoesDeTodos()) {
            $pedidosQuery->where('id_usuario', AuthHelper::getUsuarioId());
        }

        $parceiroIds = $pedidosQuery
            ->distinct()
            ->pluck('id_parceiro')
            ->filter()
            ->values();

        $parceiros = Parceiro::query()
            ->whereIn('id', $parceiroIds)
            ->select('id', 'nome')
            ->distinct()
            ->orderBy('nome')
            ->get();

        return response()->json($parceiros);
    }

}
