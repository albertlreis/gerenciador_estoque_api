<?php

namespace App\Http\Controllers;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Services\Comunicacao\ComunicacaoApiClient;
use App\Services\EntregaProdutoService;
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

    public function __construct(private readonly PedidoStatusFluxoService $statusFluxo)
    {
    }

    public function fluxoStatus(Pedido $pedido): JsonResponse
    {
        return response()->json($this->statusFluxo->codigosFluxo($pedido));
    }

    public function opcoes(Pedido $pedido): JsonResponse
    {
        return response()->json($this->statusFluxo->opcoesDisponiveis($pedido));
    }

    public function historico(Pedido $pedido): JsonResponse
    {
        $usuario = auth()->user();

        $historico = $pedido->historicoStatus()
            ->with('usuario')
            ->get();

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
            ];
        });

        $statusRegistrados = $historico
            ->map(fn ($h) => (string) $h->getRawOriginal('status'))
            ->unique();

        $previsoesFuturas = collect($previsoes)
            ->filter(fn ($data, $status) => $data && !$statusRegistrados->contains($status))
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
                    'origem_previsao' => isset($previsoesManuais[$status]) ? 'manual' : 'automatica',
                ];
            });

        $ordenado = $historicoFormatado
            ->merge($previsoesFuturas)
            ->sortByDesc(fn ($item) => $ordemMap[$item['status']] ?? -1)
            ->values();

        $primeiroRealIndex = $ordenado->search(fn ($item) => !$item['ehPrevisao']);

        $resultadoFinal = $ordenado->map(function ($item, $index) use ($usuario, $primeiroRealIndex) {
            $isUltimo = $index === $primeiroRealIndex;
            $statusCritico = in_array($item['status'], self::STATUS_CRITICOS, true);
            $podeRemoverCritico = $usuario?->can('remover-status-critico') ?? false;

            return [
                ...$item,
                'isUltimo' => $isUltimo,
                'ultimoReal' => $isUltimo,
                'podeRemover' => $isUltimo && (!$statusCritico || $podeRemoverCritico),
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

    public function atualizarStatus(Request $request, Pedido $pedido, ComunicacaoApiClient $comms): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['required', 'string', 'max:50'],
            'observacoes' => ['nullable', 'string'],
            'data_status' => ['nullable', 'date_format:Y-m-d'],
            'data_prevista' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $novoStatus = $dados['status'];
        $statusPermitidos = $this->statusFluxo->codigosFluxo($pedido);

        if (!in_array($novoStatus, $statusPermitidos, true)) {
            return response()->json(['message' => 'Status inválido para esse pedido.'], 422);
        }

        $exigePrevisao = $this->statusFluxo->exigePrevisaoManual($pedido, $novoStatus);

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

        if (!empty($dados['data_status'])) {
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

        if ($bloqueio = $this->validarStatusOperacionalCentral($pedido, $novoStatus)) {
            return $bloqueio;
        }

        $previsaoSalva = null;

        DB::transaction(function () use ($pedido, $novoStatus, $dados, $exigePrevisao, $dataStatusEfetiva, &$previsaoSalva) {
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
        });

        try {
            $comms->enviarStatusPedido($pedido->fresh(['cliente']), $novoStatus);
        } catch (\Throwable $e) {
            logger()->warning('[Comunicacao] Falha ao enviar status de pedido', [
                'pedido_id' => $pedido->id,
                'status' => $novoStatus,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'data_status' => $dataStatusEfetiva->toDateString(),
            'data_prevista' => $previsaoSalva?->data_prevista?->toDateString(),
        ]);
    }

    public function cancelarStatus(PedidoStatusHistorico $statusHistorico): JsonResponse
    {
        $pedido = $statusHistorico->pedido;

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

        $statusExpedicao = [PedidoStatus::ENVIO_CLIENTE->value];
        $statusEntrega = [PedidoStatus::ENTREGA_CLIENTE->value, PedidoStatus::FINALIZADO->value];

        if (!in_array($novoStatus, [...$statusExpedicao, ...$statusEntrega], true)) {
            return null;
        }

        $pedido->loadMissing('entregaItens');
        $resumo = app(EntregaProdutoService::class)->resumoPedido($pedido);
        $total = (int) ($resumo['quantidade_total'] ?? 0);

        if ($total <= 0) {
            return response()->json([
                'message' => 'Pedido ainda nao possui demanda no fluxo central de entrega.',
            ], 422);
        }

        if (in_array($novoStatus, $statusExpedicao, true) && (int) $resumo['quantidade_expedida'] < $total) {
            return response()->json([
                'message' => 'Registre a expedicao pelo fluxo central antes de marcar envio ao cliente.',
            ], 422);
        }

        if (in_array($novoStatus, $statusEntrega, true) && (int) $resumo['quantidade_entregue'] < $total) {
            return response()->json([
                'message' => 'Registre a entrega pelo fluxo central antes de marcar entrega ou finalizacao.',
            ], 422);
        }

        return null;
    }
}
