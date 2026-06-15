<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Models\Consignacao;
use App\Models\ConsignacaoCompra;
use App\Models\ConsignacaoDevolucao;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DesfazerConsignacaoService
{
    public function __construct(
        private readonly EntregaProdutoService $entregas,
        private readonly EstoqueMovimentacaoService $movimentacoes
    ) {
    }

    public function desfazerItem(int|Consignacao $consignacao, ?int $usuarioId = null): array
    {
        return DB::transaction(function () use ($consignacao, $usuarioId) {
            $consignacao = $this->carregarConsignacao($consignacao);
            $pedido = Pedido::query()->lockForUpdate()->findOrFail((int) $consignacao->pedido_id);

            $resultado = $this->desfazerConsignacao($consignacao, $pedido, $usuarioId);
            $pedidoCancelado = $this->atualizarStatusPedido($pedido, $usuarioId);

            return [
                ...$resultado,
                'pedido_cancelado' => $pedidoCancelado,
            ];
        });
    }

    public function desfazerPedido(int|Pedido $pedido, ?int $usuarioId = null): array
    {
        return DB::transaction(function () use ($pedido, $usuarioId) {
            $pedido = $pedido instanceof Pedido
                ? Pedido::query()->lockForUpdate()->findOrFail($pedido->id)
                : Pedido::query()->lockForUpdate()->findOrFail($pedido);

            $consignacoes = Consignacao::query()
                ->with([
                    'compras',
                    'devolucoes',
                    'entregaItem.eventos',
                    'pedidoItem.entregaItem',
                    'produtoVariacao.produto',
                ])
                ->where('pedido_id', $pedido->id)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($consignacoes->isEmpty()) {
                throw ValidationException::withMessages([
                    'consignacao' => ['Pedido sem consignacoes para desfazer.'],
                ]);
            }

            $totais = [
                'pedido_id' => (int) $pedido->id,
                'consignacoes_desfeitas' => 0,
                'itens_removidos' => 0,
            ];

            foreach ($consignacoes as $consignacao) {
                $resultado = $this->desfazerConsignacao($consignacao, $pedido, $usuarioId);
                $totais['consignacoes_desfeitas'] += (int) $resultado['consignacoes_desfeitas'];
                $totais['itens_removidos'] += (int) $resultado['itens_removidos'];
            }

            $totais['pedido_cancelado'] = $this->atualizarStatusPedido($pedido, $usuarioId);

            return $totais;
        });
    }

    private function carregarConsignacao(int|Consignacao $consignacao): Consignacao
    {
        $id = $consignacao instanceof Consignacao ? $consignacao->id : $consignacao;

        return Consignacao::query()
            ->with([
                'compras',
                'devolucoes',
                'entregaItem.eventos',
                'pedidoItem.entregaItem',
                'produtoVariacao.produto',
            ])
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function desfazerConsignacao(Consignacao $consignacao, Pedido $pedido, ?int $usuarioId): array
    {
        $isAdmin = AuthHelper::hasPerfil('Administrador');
        $temHistoricoComercial = $this->temHistoricoComercialAtivo($consignacao);

        if ($temHistoricoComercial && !$isAdmin) {
            abort(403, 'Apenas administradores podem desfazer consignacoes com venda ou devolucao registrada.');
        }

        $pedidoItem = $this->resolverPedidoItem($consignacao);

        if ($temHistoricoComercial) {
            $this->cancelarHistoricoComercial($consignacao, $usuarioId);
        }

        $this->estornarEventosOperacionais($consignacao, $usuarioId);
        $this->cancelarEntregaConsignacao($consignacao, $usuarioId);
        $this->cancelarEntregaPedidoItem($pedidoItem, $usuarioId);
        $this->desvincularReservasCanceladas($pedidoItem);

        $subtotalRemovido = (float) $pedidoItem->subtotal;
        $consignacao->delete();
        $pedidoItem->delete();

        $pedido->valor_total = max(0, round((float) $pedido->valor_total - $subtotalRemovido, 2));
        $pedido->save();

        logAuditoria('consignacao_desfeita', "Consignacao #{$consignacao->id} desfeita.", [
            'acao' => 'desfazer_consignacao',
            'consignacao_id' => $consignacao->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'historico_comercial' => $temHistoricoComercial,
        ], $pedido);

        return [
            'pedido_id' => (int) $pedido->id,
            'consignacoes_desfeitas' => 1,
            'itens_removidos' => 1,
        ];
    }

    private function resolverPedidoItem(Consignacao $consignacao): PedidoItem
    {
        if ($consignacao->pedido_item_id) {
            $pedidoItem = PedidoItem::query()->lockForUpdate()->find($consignacao->pedido_item_id);
            if ($pedidoItem) {
                return $pedidoItem;
            }
        }

        $entrega = $consignacao->relationLoaded('entregaItem')
            ? $consignacao->entregaItem
            : $consignacao->entregaItem()->first();

        if ($entrega?->pedido_item_id) {
            $pedidoItem = PedidoItem::query()->lockForUpdate()->find($entrega->pedido_item_id);
            if ($pedidoItem) {
                return $pedidoItem;
            }
        }

        $candidatos = PedidoItem::query()
            ->where('id_pedido', $consignacao->pedido_id)
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where('id_deposito', $consignacao->deposito_id)
            ->where('quantidade', $consignacao->quantidade)
            ->lockForUpdate()
            ->get();

        if ($candidatos->count() !== 1) {
            throw ValidationException::withMessages([
                'pedido_item_id' => [
                    'Nao foi possivel identificar com seguranca o item do pedido desta consignacao.',
                ],
            ]);
        }

        return $candidatos->first();
    }

    private function temHistoricoComercialAtivo(Consignacao $consignacao): bool
    {
        return $consignacao->quantidadeComprada() > 0 || $consignacao->quantidadeDevolvida() > 0;
    }

    private function cancelarHistoricoComercial(Consignacao $consignacao, ?int $usuarioId): void
    {
        $observacao = "Desfazer criacao da consignacao #{$consignacao->id}";

        foreach ($consignacao->devolucoes()->whereNull('cancelada_em')->get() as $devolucao) {
            if ($devolucao->estoque_movimentacao_id) {
                $this->estornarEventoPorMovimentacao((int) $devolucao->estoque_movimentacao_id, $usuarioId, $observacao);
            }

            $devolucao->update([
                'cancelada_em' => now(),
                'cancelada_por' => $usuarioId,
                'motivo_cancelamento' => $observacao,
            ]);
        }

        ConsignacaoCompra::query()
            ->where('consignacao_id', $consignacao->id)
            ->whereNull('cancelada_em')
            ->update([
                'cancelada_em' => now(),
                'cancelada_por' => $usuarioId,
                'motivo_cancelamento' => $observacao,
                'updated_at' => now(),
            ]);
    }

    private function estornarEventosOperacionais(Consignacao $consignacao, ?int $usuarioId): void
    {
        $eventos = ProdutoEntregaEvento::query()
            ->whereHas('item', function ($query) use ($consignacao) {
                $query->where('tipo_origem', ProdutoEntregaItem::ORIGEM_CONSIGNACAO)
                    ->where('consignacao_id', $consignacao->id);
            })
            ->whereIn('tipo_evento', [
                ProdutoEntregaEvento::ENTREGUE_CLIENTE,
                ProdutoEntregaEvento::RETORNADO_CONSIGNACAO,
                ProdutoEntregaEvento::ENVIADO_CONSIGNACAO,
                ProdutoEntregaEvento::RESERVA_CRIADA,
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($eventos as $evento) {
            $this->estornarEvento($evento, $usuarioId, "Desfazer criacao da consignacao #{$consignacao->id}");
        }
    }

    private function estornarEventoPorMovimentacao(int $movimentacaoId, ?int $usuarioId, string $observacao): void
    {
        $evento = ProdutoEntregaEvento::query()
            ->where('estoque_movimentacao_id', $movimentacaoId)
            ->first();

        if ($evento) {
            $this->estornarEvento($evento, $usuarioId, $observacao);
            return;
        }

        $jaEstornada = DB::table('estoque_movimentacoes')
            ->where('ref_type', 'estorno')
            ->where('ref_id', $movimentacaoId)
            ->exists();

        if (!$jaEstornada) {
            $this->movimentacoes->estornarMovimentacao($movimentacaoId, $usuarioId, $observacao);
        }
    }

    private function estornarEvento(ProdutoEntregaEvento $evento, ?int $usuarioId, string $observacao): void
    {
        try {
            $this->entregas->estornarEvento($evento, $usuarioId, $observacao);
        } catch (InvalidArgumentException $e) {
            if (!str_contains(Str::ascii($e->getMessage()), 'ja estornada')) {
                throw $e;
            }
        }
    }

    private function cancelarEntregaConsignacao(Consignacao $consignacao, ?int $usuarioId): void
    {
        $entrega = $consignacao->relationLoaded('entregaItem')
            ? $consignacao->entregaItem
            : $consignacao->entregaItem()->first();

        if ($entrega) {
            $this->entregas->cancelarItem($entrega, $usuarioId, "Consignacao #{$consignacao->id} desfeita.");
        }
    }

    private function cancelarEntregaPedidoItem(PedidoItem $pedidoItem, ?int $usuarioId): void
    {
        $entrega = $pedidoItem->relationLoaded('entregaItem')
            ? $pedidoItem->entregaItem
            : $pedidoItem->entregaItem()->first();

        if ($entrega) {
            $this->entregas->cancelarItem($entrega, $usuarioId, "Item #{$pedidoItem->id} removido ao desfazer consignacao.");
        }
    }

    private function desvincularReservasCanceladas(PedidoItem $pedidoItem): void
    {
        DB::table('estoque_reservas')
            ->where('pedido_item_id', $pedidoItem->id)
            ->where('status', 'cancelada')
            ->update([
                'pedido_item_id' => null,
                'updated_at' => now(),
            ]);
    }

    private function atualizarStatusPedido(Pedido $pedido, ?int $usuarioId): bool
    {
        $pedido->refresh();
        $itensRestantes = $pedido->itens()->count();
        $consignacoesRestantes = $pedido->consignacoes()->count();

        if ($itensRestantes === 0) {
            $this->registrarStatusSeMudou($pedido, PedidoStatus::CANCELADO, $usuarioId, 'Pedido cancelado ao desfazer consignacao.');
            return true;
        }

        if ($consignacoesRestantes === 0) {
            $this->registrarStatusSeMudou($pedido, PedidoStatus::PEDIDO_CRIADO, $usuarioId, 'Consignacao desfeita; pedido retornou ao status inicial.');
            return false;
        }

        $this->registrarStatusSeMudou($pedido, PedidoStatus::CONSIGNADO, $usuarioId, 'Consignacao desfeita; pedido ainda possui itens consignados.');
        return false;
    }

    private function registrarStatusSeMudou(Pedido $pedido, PedidoStatus $status, ?int $usuarioId, string $observacao): void
    {
        $atual = $pedido->statusAtual?->status;
        $atualValor = $atual instanceof PedidoStatus ? $atual->value : $atual;

        if ($atualValor === $status->value) {
            return;
        }

        PedidoStatusHistorico::query()->create([
            'pedido_id' => $pedido->id,
            'status' => $status,
            'data_status' => now('America/Belem'),
            'usuario_id' => $usuarioId,
            'observacoes' => $observacao,
        ]);
    }
}
