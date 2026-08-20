<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoItemStatusHistorico;
use App\Models\PedidoStatusHistorico;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PedidoItemStatusService
{
    public function __construct(private readonly PedidoStatusFluxoService $statusFluxo) {}

    public function itensElegiveis(Pedido $pedido, string $status): Collection
    {
        $this->validarStatusEscopoItens($pedido, $status);

        return $pedido->itens()
            ->with(['variacao.produto', 'entregaItem'])
            ->orderBy('id')
            ->get()
            ->map(fn (PedidoItem $item) => $this->resumoItem($item, $status))
            ->filter(fn (array $item) => $item['quantidade_elegivel'] > 0)
            ->values();
    }

    public function registrar(Pedido $pedido, array $dados, ?int $usuarioId): array
    {
        $status = (string) $dados['status'];
        $this->validarStatusEscopoItens($pedido, $status);

        if ($pedido->historicoStatus()->where('status', $status)->exists()) {
            throw ValidationException::withMessages([
                'status' => ['Este status já foi registrado globalmente para o pedido.'],
            ]);
        }

        $timezone = config('app.timezone', 'America/Belem');
        $agora = Carbon::now($timezone);
        $dataStatus = Carbon::createFromFormat('Y-m-d', $dados['data_status'], $timezone)->startOfDay();
        $dataPedido = $pedido->data_pedido ? Carbon::parse($pedido->data_pedido, $timezone)->startOfDay() : null;

        if ($dataStatus->gt($agora->copy()->startOfDay())) {
            throw ValidationException::withMessages(['data_status' => ['A data do status não pode ser futura.']]);
        }
        if ($dataPedido && $dataStatus->lt($dataPedido)) {
            throw ValidationException::withMessages(['data_status' => ['A data do status não pode ser anterior à criação do pedido.']]);
        }

        $selecionados = collect($dados['itens']);
        if ($selecionados->pluck('pedido_item_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['itens' => ['Cada item deve aparecer apenas uma vez.']]);
        }

        return DB::transaction(function () use ($pedido, $dados, $status, $usuarioId, $dataStatus, $selecionados, $agora) {
            $itens = $pedido->itens()
                ->with(['variacao.produto', 'entregaItem'])
                ->whereIn('id', $selecionados->pluck('pedido_item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($itens->count() !== $selecionados->count()) {
                throw ValidationException::withMessages(['itens' => ['Um ou mais itens não pertencem a este pedido.']]);
            }

            $this->validarCronologiaItens($pedido, $itens->keys(), $status, $dataStatus);

            $grupo = (string) Str::uuid();
            $criados = [];
            foreach ($selecionados as $selecionado) {
                /** @var PedidoItem $item */
                $item = $itens->get((int) $selecionado['pedido_item_id']);
                $resumo = $this->resumoItem($item, $status);
                $quantidade = (int) $selecionado['quantidade'];

                if ($quantidade <= 0 || $quantidade > $resumo['quantidade_elegivel']) {
                    throw ValidationException::withMessages([
                        'itens' => ["A quantidade do item {$item->id} deve estar entre 1 e {$resumo['quantidade_elegivel']}."],
                    ]);
                }

                $criados[] = PedidoItemStatusHistorico::query()->create([
                    'grupo_uuid' => $grupo,
                    'pedido_id' => $pedido->id,
                    'pedido_item_id' => $item->id,
                    'status' => $status,
                    'quantidade' => $quantidade,
                    'quantidade_avancada' => $resumo['quantidade_avancada'] + $quantidade,
                    'data_status' => $dataStatus->copy()->setTimeFrom($agora),
                    'data_prevista' => $dados['data_prevista'] ?? null,
                    'usuario_id' => $usuarioId,
                    'observacoes' => $dados['observacoes'] ?? null,
                ]);
            }

            $marcoGlobal = $this->criarMarcoGlobalSeCompleto($pedido, $status, $dataStatus, $usuarioId);

            return [
                'grupo_uuid' => $grupo,
                'itens' => $criados,
                'marco_global_criado' => $marcoGlobal,
            ];
        });
    }

    private function validarStatusEscopoItens(Pedido $pedido, string $status): void
    {
        if (! $this->statusFluxo->permiteEscopoItens($pedido, $status)) {
            throw ValidationException::withMessages([
                'status' => ['Este status não aceita acompanhamento por item.'],
            ]);
        }
    }

    private function resumoItem(PedidoItem $item, string $status): array
    {
        $total = max(0, (int) $item->quantidade);
        $recebida = min($total, max(0, (int) ($item->entregaItem?->quantidade_recebida ?? 0)));
        $queryStatus = PedidoItemStatusHistorico::query()
            ->where('pedido_item_id', $item->id)
            ->where('status', $status);
        $acompanhada = min($total, (int) (clone $queryStatus)->sum('quantidade'));
        $marcoAcompanhado = min($total, (int) (clone $queryStatus)->max('quantidade_avancada'));
        $avancada = max($recebida, $marcoAcompanhado);

        return [
            'pedido_item_id' => (int) $item->id,
            'id_variacao' => (int) $item->id_variacao,
            'produto' => $item->variacao?->produto?->nome ?: "Item {$item->id}",
            'variacao' => $item->variacao?->nome ?? null,
            'quantidade_total' => $total,
            'quantidade_recebida' => $recebida,
            'quantidade_acompanhada' => $acompanhada,
            'quantidade_avancada' => $avancada,
            'quantidade_elegivel' => max(0, $total - $avancada),
        ];
    }

    private function validarCronologiaItens(Pedido $pedido, Collection $itemIds, string $status, Carbon $data): void
    {
        $ordem = $this->statusFluxo->fluxoDetalhado($pedido)->pluck('codigo')->values()->flip();
        $posicao = $ordem->get($status);

        $eventos = PedidoItemStatusHistorico::query()
            ->whereIn('pedido_item_id', $itemIds)
            ->get(['pedido_item_id', 'status', 'data_status']);

        foreach ($eventos as $evento) {
            $posicaoEvento = $ordem->get((string) $evento->getRawOriginal('status'));
            if ($posicaoEvento === null) {
                continue;
            }

            $dataEvento = Carbon::parse($evento->data_status)->startOfDay();
            if ($posicaoEvento < $posicao && $data->lt($dataEvento)) {
                throw ValidationException::withMessages(['data_status' => ['A data deve ser posterior às etapas anteriores dos itens selecionados.']]);
            }
            if ($posicaoEvento > $posicao && $data->gt($dataEvento)) {
                throw ValidationException::withMessages(['data_status' => ['A data deve ser anterior às etapas posteriores dos itens selecionados.']]);
            }
        }
    }

    private function criarMarcoGlobalSeCompleto(Pedido $pedido, string $status, Carbon $data, ?int $usuarioId): bool
    {
        $itens = $pedido->itens()->with('entregaItem')->get();
        if ($itens->isEmpty() || $itens->contains(fn (PedidoItem $item) => $this->resumoItem($item, $status)['quantidade_elegivel'] > 0)) {
            return false;
        }

        $ordem = $this->statusFluxo->fluxoDetalhado($pedido)->pluck('codigo')->values()->flip();
        $posicaoNova = $ordem->get($status);
        $historico = $pedido->historicoStatus()->get();
        if ($historico->contains(fn ($evento) => ($ordem->get((string) $evento->getRawOriginal('status')) ?? -1) > $posicaoNova)) {
            return false;
        }

        $ultimaData = $historico->max('data_status');
        if ($ultimaData && $data->lt(Carbon::parse($ultimaData)->startOfDay())) {
            return false;
        }

        PedidoStatusHistorico::query()->create([
            'pedido_id' => $pedido->id,
            'status' => $status,
            'data_status' => $data,
            'usuario_id' => $usuarioId,
            'observacoes' => 'Etapa concluída para todos os itens pelo acompanhamento logístico.',
        ]);

        return true;
    }
}
