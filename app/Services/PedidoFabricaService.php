<?php

namespace App\Services;

use App\Http\Requests\PedidoFabricaEntregaParcialRequest;
use App\Http\Requests\PedidoFabricaStoreRequest;
use App\Http\Requests\PedidoFabricaUpdateRequest;
use App\Http\Requests\PedidoFabricaUpdateStatusRequest;
use App\Models\PedidoFabrica;
use App\Models\PedidoFabricaEntrega;
use App\Models\PedidoFabricaItem;
use App\Models\PedidoFabricaStatusHistorico;
use Illuminate\Support\Facades\DB;

/**
 * Camada de orquestração para Pedidos de Fábrica.
 */
class PedidoFabricaService
{
    /**
     * Cria um pedido com itens.
     *
     * @param \App\Http\Requests\PedidoFabricaStoreRequest $request
     * @return \App\Models\PedidoFabrica
     */
    public function criar(PedidoFabricaStoreRequest $request): PedidoFabrica
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $request) {
            /** @var PedidoFabrica $pedido */
            $pedido = PedidoFabrica::create([
                'status' => 'pendente',
                'data_previsao_entrega' => $data['data_previsao_entrega'] ?? null,
                'observacoes' => $data['observacoes'] ?? null,
            ]);

            foreach ($data['itens'] as $item) {
                $pedido->itens()->create([
                    'produto_variacao_id' => $item['produto_variacao_id'],
                    'quantidade'          => $item['quantidade'],
                    'deposito_id'         => $item['deposito_id'] ?? null,
                    'pedido_venda_id'     => $item['pedido_venda_id'] ?? null,
                    'observacoes'         => $item['observacoes'] ?? null,
                ]);
            }

            $this->registrarHistorico($pedido, 'pendente', $request->user()?->id, 'Criação do pedido.');

            return $pedido->load(['itens.variacao.produto', 'historicos']);
        });
    }

    /**
     * Atualiza pedido e substitui itens.
     */
    public function atualizar(PedidoFabricaUpdateRequest $request, int $id): PedidoFabrica
    {
        $data = $request->validated();

        return DB::transaction(function () use ($id, $data) {
            /** @var PedidoFabrica $pedido */
            $pedido = PedidoFabrica::findOrFail($id);

            $pedido->update([
                'data_previsao_entrega' => $data['data_previsao_entrega'] ?? null,
                'observacoes'           => $data['observacoes'] ?? null,
            ]);

            // substitui itens
            $pedido->itens()->delete();

            foreach ($data['itens'] as $item) {
                $pedido->itens()->create([
                    'produto_variacao_id' => $item['produto_variacao_id'],
                    'quantidade'          => $item['quantidade'],
                    'deposito_id'         => $item['deposito_id'] ?? null,
                    'pedido_venda_id'     => $item['pedido_venda_id'] ?? null,
                    'observacoes'         => $item['observacoes'] ?? null,
                ]);
            }

            // manter status atual; não cria histórico aqui
            return $pedido->load(['itens.variacao.produto', 'historicos']);
        });
    }

    /**
     * Atualiza status manualmente.
     * - Se "entregue": registra entrega total de todos os itens e cria histórico de entregas.
     * - Se "parcial": apenas muda status; a UI já abrirá o diálogo de entrega.
     */
    public function atualizarStatus(PedidoFabricaUpdateStatusRequest $request, int $id): PedidoFabrica
    {
        $data = $request->validated();
        $usuarioId = $request->user()?->id;

        return DB::transaction(function () use ($id, $data, $usuarioId) {
            $pedido = PedidoFabrica::with('itens')->lockForUpdate()->findOrFail($id);

            if ($data['status'] === 'entregue') {
                // entrega total para cada item
                foreach ($pedido->itens as $item) {
                    $faltante = max(0, $item->quantidade - $item->quantidade_entregue);
                    if ($faltante > 0) {
                        $this->registrarEntrega($pedido, $item, $faltante, $usuarioId, $item->deposito_id, 'Entrega total (status entregue).');
                    }
                }
                // status entregue
                $pedido->status = 'entregue';
                $pedido->save();
                $this->registrarHistorico($pedido, 'entregue', $usuarioId, $data['observacao'] ?? null);
            } else {
                // outros status (pendente, enviado, parcial, cancelado)
                $pedido->status = $data['status'];
                $pedido->save();
                $this->registrarHistorico($pedido, $data['status'], $usuarioId, $data['observacao'] ?? null);
            }

            return $pedido->load(['itens.variacao.produto', 'historicos', 'entregas']);
        });
    }

    /**
     * Registra entrega (parcial/total) para um item.
     * - Incrementa quantidade_entregue.
     * - Gera registro em pedido_fabrica_entregas.
     * - Recalcula e sincroniza status (parcial/entregue).
     */
    public function registrarEntregaParcial(PedidoFabricaEntregaParcialRequest $request, int $itemId): PedidoFabrica
    {
        $data = $request->validated();
        $usuarioId = $request->user()?->id;

        return DB::transaction(function () use ($itemId, $data, $usuarioId) {
            /** @var PedidoFabricaItem $item */
            $item = PedidoFabricaItem::lockForUpdate()->findOrFail($itemId);
            $pedido = $item->pedidoFabrica()->lockForUpdate()->firstOrFail();

            $this->registrarEntrega(
                $pedido,
                $item,
                (int) $data['quantidade'],
                $usuarioId,
                $data['deposito_id'] ?? $item->deposito_id,
                $data['observacao'] ?? null
            );

            // sincroniza status com base nas quantidades
            $this->syncStatusPorItens($pedido, $usuarioId, 'Entrega registrada.');

            return $pedido->load(['itens.variacao.produto', 'historicos', 'entregas']);
        });
    }

    /** Registra uma entrega (parcial/total) para um item, com depósito. */
    protected function registrarEntrega(
        PedidoFabrica $pedido,
        PedidoFabricaItem $item,
        int $quantidade,
        ?int $usuarioId,
        ?int $depositoId = null,
        ?string $observacao = null
    ): void {
        // Atualiza quantidade_entregue do item
        $novoEntregue = min($item->quantidade, $item->quantidade_entregue + $quantidade);
        $delta = $novoEntregue - $item->quantidade_entregue;
        if ($delta <= 0) return;

        $item->update(['quantidade_entregue' => $novoEntregue]);

        // Cria registro de entrega
        PedidoFabricaEntrega::create([
            'pedido_fabrica_id'     => $pedido->id,
            'pedido_fabrica_item_id'=> $item->id,
            'deposito_id'           => $depositoId ?: $item->deposito_id,
            'quantidade'            => $delta,
            'usuario_id'            => $usuarioId,
            'observacao'            => $observacao,
        ]);
    }

    /** Recalcula status do pedido após entregas. */
    protected function syncStatusPorItens(PedidoFabrica $pedido, ?int $usuarioId = null, ?string $obs = null): void
    {
        $novo = $pedido->recomputarStatusPorItens();
        if ($novo !== $pedido->status) {
            $pedido->status = $novo;
            $pedido->save();
            $this->registrarHistorico($pedido, $novo, $usuarioId, $obs);
        }
    }

    /**
     * @param PedidoFabrica $pedido
     * @param string $status
     * @param int|null $usuarioId
     * @param string|null $observacao
     */
    protected function registrarHistorico(PedidoFabrica $pedido, string $status, ?int $usuarioId, ?string $observacao = null): void
    {
        PedidoFabricaStatusHistorico::create([
            'pedido_fabrica_id' => $pedido->id,
            'status'            => $status,
            'usuario_id'        => $usuarioId,
            'observacao'        => $observacao,
        ]);
    }
}
