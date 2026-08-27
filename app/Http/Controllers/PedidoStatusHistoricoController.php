<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Services\Comunicacao\ComunicacaoOutboxService;
use App\Services\EntregaProdutoService;
use App\Services\PedidoItemStatusService;
use App\Services\PedidoStatusFluxoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PedidoStatusHistoricoController extends Controller
{
    private const STATUS_CRITICOS = [
        'entrega_cliente',
        'finalizado',
    ];

    public function __construct(
        private readonly PedidoStatusFluxoService $statusFluxo,
        private readonly PedidoItemStatusService $itemStatusService,
    ) {}

    public function fluxoStatus(Pedido $pedido): JsonResponse
    {
        return response()->json($this->statusFluxo->codigosFluxo($pedido));
    }

    public function opcoes(Pedido $pedido): JsonResponse
    {
        return response()->json($this->statusFluxo->opcoesDisponiveis($pedido));
    }

    public function itensStatus(Request $request, Pedido $pedido): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['required', 'string', Rule::in($this->statusFluxo->codigosFluxo($pedido))],
        ]);

        return response()->json($this->itemStatusService->itensElegiveis($pedido, $dados['status']));
    }

    public function registrarStatusItens(Request $request, Pedido $pedido): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['required', 'string', Rule::in($this->statusFluxo->codigosFluxo($pedido))],
            'data_status' => ['required', 'date_format:Y-m-d'],
            'data_prevista' => ['nullable', 'date_format:Y-m-d'],
            'observacoes' => ['nullable', 'string'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.pedido_item_id' => ['required', 'integer', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
        ]);

        if ($this->statusFluxo->exigePrevisaoManual($pedido, $dados['status']) && empty($dados['data_prevista'])) {
            return response()->json([
                'message' => 'Informe a previsão para este status.',
                'errors' => ['data_prevista' => ['Informe a previsão para este status.']],
            ], 422);
        }

        $resultado = $this->itemStatusService->registrar($pedido, $dados, auth()->id());

        logAuditoria('pedido_item_status', "Status '{$dados['status']}' registrado por item no Pedido #{$pedido->id}.", [
            'acao' => 'criacao',
            'status_novo' => $dados['status'],
            'data_status' => $dados['data_status'],
            'grupo_uuid' => $resultado['grupo_uuid'],
            'itens' => collect($dados['itens'])->map(fn ($item) => [
                'pedido_item_id' => (int) $item['pedido_item_id'],
                'quantidade' => (int) $item['quantidade'],
            ])->values()->all(),
            'marco_global_criado' => $resultado['marco_global_criado'],
        ], $pedido);

        return response()->json([
            'message' => 'Status dos itens atualizado com sucesso.',
            'grupo_uuid' => $resultado['grupo_uuid'],
            'marco_global_criado' => $resultado['marco_global_criado'],
        ], 201);
    }

    public function historico(Pedido $pedido): JsonResponse
    {
        $usuario = auth()->user();

        $historico = $pedido->historicoStatus()
            ->with('usuario')
            ->get();

        $historicoItens = $pedido->historicoStatusItens()
            ->with(['usuario', 'pedidoItem.variacao.produto'])
            ->get()
            ->groupBy('grupo_uuid');

        $previsoesManuais = $pedido->statusPrevisoes()
            ->get()
            ->mapWithKeys(fn ($item) => [
                (string) $item->getRawOriginal('status') => $item->data_prevista?->toDateString(),
            ])
            ->toArray();

        $fluxo = $this->statusFluxo->fluxoDetalhado($pedido, false);
        $ordemMap = $fluxo->pluck('codigo')->values()->flip()->all();

        $datas = $historico->mapWithKeys(fn ($item) => [
            (string) $item->getRawOriginal('status') => $item->data_status,
        ])->toArray();

        $previsoes = $this->statusFluxo->previsoesPorTipo(
            $this->statusFluxo->tipoFluxo($pedido),
            $datas,
            $previsoesManuais
        );

        $historicoFormatado = $historico->map(function ($item) {
            $status = (string) $item->getRawOriginal('status');
            $meta = $this->statusFluxo->statusMeta($status);

            return [
                'id' => $item->id,
                'status' => $status,
                'label' => $meta['label'],
                'icone' => $meta['icone'],
                'cor' => $meta['cor'],
                'severidade' => $meta['severidade'],
                'data_status' => $item->data_status,
                'observacoes' => $item->observacoes,
                'usuario' => $item->usuario?->nome,
                'ehPrevisao' => false,
                'ehItem' => false,
            ];
        });

        $historicoItensFormatado = $historicoItens->map(function ($itens) {
            $primeiro = $itens->first();
            $status = (string) $primeiro->getRawOriginal('status');
            $meta = $this->statusFluxo->statusMeta($status);

            return [
                'id' => null,
                'grupo_uuid' => $primeiro->grupo_uuid,
                'status' => $status,
                'label' => $meta['label'],
                'icone' => $meta['icone'],
                'cor' => $meta['cor'],
                'severidade' => $meta['severidade'],
                'data_status' => $primeiro->data_status,
                'data_prevista' => $primeiro->data_prevista?->toDateString(),
                'observacoes' => $primeiro->observacoes,
                'usuario' => $primeiro->usuario?->nome,
                'ehPrevisao' => false,
                'ehItem' => true,
                'itens' => $itens->map(fn ($item) => [
                    'pedido_item_id' => (int) $item->pedido_item_id,
                    'produto' => $item->pedidoItem?->variacao?->produto?->nome ?: "Item {$item->pedido_item_id}",
                    'quantidade' => (int) $item->quantidade,
                ])->values(),
            ];
        })->values();

        $statusRegistrados = $historico
            ->map(fn ($h) => (string) $h->getRawOriginal('status'))
            ->unique();

        $previsoesFuturas = collect($previsoes)
            ->filter(fn ($data, $status) => $data && ! $statusRegistrados->contains($status))
            ->map(function ($data, $status) use ($previsoesManuais) {
                $meta = $this->statusFluxo->statusMeta((string) $status);

                return [
                    'id' => null,
                    'status' => (string) $status,
                    'label' => $meta['label'],
                    'icone' => $meta['icone'],
                    'cor' => $meta['cor'],
                    'severidade' => $meta['severidade'],
                    'data_status' => $data,
                    'observacoes' => isset($previsoesManuais[$status]) ? 'Previsao manual' : 'Previsão automática',
                    'usuario' => null,
                    'ehPrevisao' => true,
                    'ehItem' => false,
                    'origem_previsao' => isset($previsoesManuais[$status]) ? 'manual' : 'automatica',
                ];
            });

        $ordenado = $historicoFormatado
            ->merge($historicoItensFormatado)
            ->merge($previsoesFuturas)
            ->sortByDesc(fn ($item) => $ordemMap[$item['status']] ?? -1)
            ->values();

        $primeiroRealIndex = $ordenado->search(fn ($item) => ! $item['ehPrevisao'] && ! $item['ehItem']);

        $resultadoFinal = $ordenado->map(function ($item, $index) use ($usuario, $primeiroRealIndex) {
            $isUltimo = ! $item['ehItem'] && $index === $primeiroRealIndex;
            $statusCritico = in_array($item['status'], self::STATUS_CRITICOS, true);
            $podeRemoverCritico = $usuario?->can('remover-status-critico') ?? false;

            return [
                ...$item,
                'isUltimo' => $isUltimo,
                'ultimoReal' => $isUltimo,
                'podeRemover' => $isUltimo && (! $statusCritico || $podeRemoverCritico),
            ];
        });

        return response()->json($resultadoFinal);
    }

    public function previsoes(Pedido $pedido): JsonResponse
    {
        $historico = $pedido->historicoStatus()->get();
        $datas = $historico->mapWithKeys(fn ($item) => [
            (string) $item->getRawOriginal('status') => $item->data_status,
        ])->toArray();

        $previsoesManuais = $pedido->statusPrevisoes()
            ->get()
            ->mapWithKeys(fn ($item) => [
                (string) $item->getRawOriginal('status') => $item,
            ]);

        $previsoesCalculadas = $this->statusFluxo->previsoesPorTipo(
            $this->statusFluxo->tipoFluxo($pedido),
            $datas
        );

        $registrados = $historico
            ->map(fn ($item) => (string) $item->getRawOriginal('status'))
            ->unique();

        $items = $this->statusFluxo->fluxoDetalhado($pedido, false)
            ->reject(fn (array $status) => $registrados->contains($status['codigo']))
            ->map(function (array $status) use ($previsoesManuais, $previsoesCalculadas) {
                $manual = $previsoesManuais->get($status['codigo']);
                $dataManual = $manual?->data_prevista?->toDateString();
                $calculada = $previsoesCalculadas[$status['codigo']] ?? null;
                $dataCalculada = $calculada ? Carbon::parse($calculada)->toDateString() : null;

                return [
                    'status' => $status['codigo'],
                    'label' => $status['label'],
                    'data_prevista' => $dataManual ?? $dataCalculada,
                    'data_calculada' => $dataCalculada,
                    'manual' => $dataManual !== null,
                    'exige_previsao_manual' => (bool) $status['exige_previsao_manual'],
                ];
            })
            ->values();

        return response()->json($items);
    }

    public function salvarPrevisoes(Request $request, Pedido $pedido): JsonResponse
    {
        $statusPermitidos = $this->statusFluxo->codigosFluxo($pedido, false);

        $dados = $request->validate([
            'previsoes' => ['required', 'array'],
            'previsoes.*.status' => ['required', 'string', Rule::in($statusPermitidos)],
            'previsoes.*.data_prevista' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $usuarioId = auth()->id();
        $salvas = [];

        foreach ($dados['previsoes'] as $previsao) {
            $status = $previsao['status'];
            $dataPrevista = $previsao['data_prevista'] ?? null;

            if ($dataPrevista === null || $dataPrevista === '') {
                $pedido->statusPrevisoes()->where('status', $status)->delete();

                continue;
            }

            $salvas[] = $pedido->statusPrevisoes()->updateOrCreate(
                ['status' => $status],
                [
                    'data_prevista' => $dataPrevista,
                    'usuario_id' => $usuarioId,
                ]
            );
        }

        logAuditoria('pedido_status_previsao', "Previsoes de status atualizadas no Pedido #{$pedido->id}.", [
            'acao' => 'atualizar_previsoes',
            'pedido_id' => $pedido->id,
            'total' => count($salvas),
        ], $pedido);

        return response()->json([
            'message' => 'Previsoes atualizadas com sucesso.',
            'data' => $salvas,
        ]);
    }

    public function atualizarStatus(Request $request, Pedido $pedido, ComunicacaoOutboxService $comms): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['required', 'string', 'max:50'],
            'observacoes' => ['nullable', 'string'],
            'data_status' => ['nullable', 'date_format:Y-m-d'],
            'data_prevista' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $novoStatus = $dados['status'];
        $statusPermitidos = $this->statusFluxo->codigosFluxo($pedido);

        if (! in_array($novoStatus, $statusPermitidos, true)) {
            return response()->json(['message' => 'Status inválido para esse pedido.'], 422);
        }

        if ($bloqueio = $this->validarStatusOperacionalCentral($pedido, $novoStatus)) {
            return $bloqueio;
        }

        $exigePrevisao = $novoStatus !== PedidoStatus::ENTREGA_ESTOQUE->value
            && $this->statusFluxo->exigePrevisaoManual($pedido, $novoStatus);

        if ($exigePrevisao && empty($dados['data_prevista'])) {
            return response()->json([
                'message' => 'Informe a previsão para este status.',
                'errors' => [
                    'data_prevista' => ['Informe a previsão para este status.'],
                ],
            ], 422);
        }

        if ($pedido->historicoStatus()->where('status', $novoStatus)->exists()) {
            return response()->json(['message' => 'Este status já foi registrado para o pedido.'], 422);
        }

        $posNovo = array_search($novoStatus, $statusPermitidos, true);
        if ($posNovo === false) {
            return response()->json(['message' => 'Status inválido para esse pedido.'], 422);
        }

        $timezone = config('app.timezone', 'America/Belem');
        $agora = Carbon::now($timezone);
        $dataStatusEfetiva = $agora->copy();

        if (! empty($dados['data_status'])) {
            $dataStatusDia = Carbon::createFromFormat('Y-m-d', $dados['data_status'], $timezone)->startOfDay();

            if ($dataStatusDia->gt($agora->copy()->startOfDay())) {
                return response()->json([
                    'message' => 'A data do status não pode ser futura.',
                    'errors' => [
                        'data_status' => ['A data do status não pode ser futura.'],
                    ],
                ], 422);
            }

            $dataStatusEfetiva = $dataStatusDia->copy()->setTime(
                (int) $agora->format('H'),
                (int) $agora->format('i'),
                (int) $agora->format('s')
            );
        }

        $ultimoStatus = $pedido->historicoStatus()->latest('data_status')->latest('id')->first();
        if ($ultimoStatus) {
            $ultimaDataStatus = $ultimoStatus->data_status
                ? Carbon::parse($ultimoStatus->data_status, $timezone)->startOfDay()
                : null;

            if ($ultimaDataStatus && $dataStatusEfetiva->copy()->startOfDay()->lt($ultimaDataStatus)) {
                return response()->json([
                    'message' => 'A data do status não pode ser anterior ao último status registrado.',
                    'errors' => [
                        'data_status' => ['A data do status não pode ser anterior ao último status registrado.'],
                    ],
                ], 422);
            }

            $posAtual = array_search((string) $ultimoStatus->getRawOriginal('status'), $statusPermitidos, true);
            if ($posAtual !== false && $posNovo < $posAtual) {
                return response()->json(['message' => 'Não é permitido regredir o status.'], 422);
            }
        }

        $previsaoSalva = null;

        DB::transaction(function () use ($pedido, $novoStatus, $dados, $exigePrevisao, $dataStatusEfetiva, &$previsaoSalva, $comms) {
            $pedido->historicoStatus()->create([
                'status' => $novoStatus,
                'observacoes' => $dados['observacoes'] ?? null,
                'data_status' => $dataStatusEfetiva,
                'usuario_id' => auth()->id(),
            ]);

            if ($exigePrevisao) {
                $previsaoSalva = $pedido->statusPrevisoes()->updateOrCreate(
                    ['status' => $novoStatus],
                    [
                        'data_prevista' => $dados['data_prevista'],
                        'usuario_id' => auth()->id(),
                    ]
                );
            }

            logAuditoria('pedido_status', "Status atualizado para '$novoStatus' no Pedido #$pedido->id.", [
                'acao' => 'atualizacao',
                'nivel' => 'info',
                'status_novo' => $novoStatus,
                'data_status' => $dataStatusEfetiva->toDateTimeString(),
                'data_prevista' => $exigePrevisao ? $dados['data_prevista'] : null,
            ], $pedido);

            $comms->registrarPedidoStatus($pedido->fresh(['cliente.consentimentosComunicacao']), $novoStatus);
        });

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'data_status' => $dataStatusEfetiva->toDateString(),
            'data_prevista' => $previsaoSalva?->data_prevista?->toDateString(),
        ]);
    }

    public function cancelarStatus(Pedido $pedido, PedidoStatusHistorico $statusHistorico): JsonResponse
    {
        abort_unless((int) $statusHistorico->pedido_id === (int) $pedido->id, 404);

        $statusCancelado = $statusHistorico->getRawOriginal('status');
        $dataStatus = $statusHistorico->data_status;

        $statusHistorico->delete();

        logAuditoria('pedido_status', "Status '$statusCancelado' removido do Pedido #$pedido->id.", [
            'acao' => 'cancelamento',
            'nivel' => 'warn',
            'status_cancelado' => $statusCancelado,
            'data_status' => $dataStatus,
        ], $pedido);

        return response()->json(['message' => 'Status removido com sucesso.']);
    }

    private function validarStatusOperacionalCentral(Pedido $pedido, string $novoStatus): ?JsonResponse
    {
        if ($novoStatus === PedidoStatus::CANCELADO->value) {
            return response()->json([
                'message' => 'Use o cancelamento do pedido para cancelar reservas e movimentacoes no fluxo central.',
            ], 422);
        }

        $statusRecebimento = [PedidoStatus::ENTREGA_ESTOQUE->value];
        $statusExpedicao = [PedidoStatus::ENVIO_CLIENTE->value];
        $statusEntrega = [PedidoStatus::ENTREGA_CLIENTE->value, PedidoStatus::FINALIZADO->value];

        if (! in_array($novoStatus, [...$statusRecebimento, ...$statusExpedicao, ...$statusEntrega], true)) {
            return null;
        }

        if (
            in_array($novoStatus, $statusRecebimento, true)
            && ! $pedido->isReposicao()
            && $pedido->origem_abastecimento !== Pedido::ORIGEM_ABASTECIMENTO_FABRICA
        ) {
            return response()->json([
                'code' => 'RECEBIMENTO_NAO_APLICAVEL',
                'message' => 'Este pedido e atendido pelo estoque atual e nao possui recebimento de fabrica.',
            ], 422);
        }

        $entregaService = app(EntregaProdutoService::class);
        $pedido->loadMissing('entregaItens');

        if (
            $pedido->entregaItens->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)->isEmpty()
        ) {
            $entregaService->criarDemandaPedido($pedido, auth()->id(), false);
            $pedido->load('entregaItens');
        }

        $itens = $pedido->entregaItens
            ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->reject(fn ($item) => $item->status === \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
            ->values();
        $itens->loadMissing('variacao.produto');
        $resumo = $entregaService->resumoPedido($pedido);
        $total = (int) ($resumo['quantidade_total'] ?? 0);

        if ($total <= 0) {
            return response()->json([
                'message' => 'Pedido ainda nao possui demanda no fluxo central de entrega.',
            ], 422);
        }

        if (in_array($novoStatus, $statusRecebimento, true) && (int) $resumo['quantidade_recebida'] < $total) {
            $pendencias = $itens
                ->filter(fn ($item) => (int) $item->quantidade_recebida < (int) $item->quantidade_total)
                ->map(function ($item) {
                    $esperado = (int) $item->quantidade_total;
                    $recebido = min($esperado, (int) $item->quantidade_recebida);

                    return [
                        'produto_entrega_item_id' => (int) $item->id,
                        'pedido_item_id' => $item->pedido_item_id ? (int) $item->pedido_item_id : null,
                        'id_variacao' => (int) $item->id_variacao,
                        'produto' => $item->variacao?->produto?->nome,
                        'esperado' => $esperado,
                        'recebido' => $recebido,
                        'faltante' => max(0, $esperado - $recebido),
                        'id_deposito_destino' => $item->id_deposito_destino,
                        'deposito_sugerido_id' => $item->id_deposito_destino,
                    ];
                })
                ->values();

            return response()->json([
                'code' => 'RECEBIMENTO_ITENS_PENDENTE',
                'message' => 'Registre o recebimento de todos os itens antes de marcar o pedido como recebido no estoque.',
                'status_solicitado' => $novoStatus,
                'resumo' => [
                    'esperado' => $total,
                    'recebido' => (int) $resumo['quantidade_recebida'],
                    'faltante' => max(0, $total - (int) $resumo['quantidade_recebida']),
                ],
                'pendencias' => $pendencias,
                'itens' => $pendencias,
            ], 409);
        }

        if (in_array($novoStatus, $statusExpedicao, true) && (int) $resumo['quantidade_expedida'] < $total) {
            return response()->json([
                'message' => 'Registre a expedicao pelo fluxo central antes de marcar envio ao cliente.',
            ], 422);
        }

        if (in_array($novoStatus, $statusEntrega, true) && (int) $resumo['quantidade_entregue'] < $total) {
            return response()->json([
                'code' => 'ENTREGA_CLIENTE_ITENS_PENDENTE',
                'message' => 'Registre a entrega de todos os itens antes de marcar a entrega ao cliente.',
                'status_solicitado' => $novoStatus,
                'resumo' => [
                    'total' => $total,
                    'entregue' => (int) $resumo['quantidade_entregue'],
                    'faltante' => max(0, $total - (int) $resumo['quantidade_entregue']),
                ],
                'itens' => $itens
                    ->filter(fn ($item) => (int) $item->quantidade_entregue < (int) $item->quantidade_total)
                    ->map(fn ($item) => [
                        'produto_entrega_item_id' => (int) $item->id,
                        'pedido_item_id' => $item->pedido_item_id ? (int) $item->pedido_item_id : null,
                        'id_variacao' => (int) $item->id_variacao,
                        'produto' => $item->variacao?->produto?->nome,
                        'total' => (int) $item->quantidade_total,
                        'entregue' => min((int) $item->quantidade_total, (int) $item->quantidade_entregue),
                        'faltante' => max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue),
                    ])->values(),
            ], 409);
        }

        return null;
    }
}
