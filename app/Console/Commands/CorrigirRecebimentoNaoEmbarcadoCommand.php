<?php

namespace App\Console\Commands;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Services\EntregaProdutoService;
use App\Services\EstoqueDisponibilidadeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CorrigirRecebimentoNaoEmbarcadoCommand extends Command
{
    private const OBSERVACAO_ENTREGA_ESTOQUE = 'Recebimento integral da fabrica registrado pelo fluxo operacional.';

    private const OBSERVACAO_FINALIZADO = 'Pedido finalizado automaticamente apos recebimento total dos produtos.';

    protected $signature = 'pedidos:corrigir-recebimento-nao-embarcado
        {pedido : ID ou numero externo do pedido}
        {--aplicar : Executa os estornos; sem esta opcao o comando e somente diagnostico}
        {--confirmacao= : Numero externo do pedido, obrigatorio para aplicar}';

    protected $description = 'Diagnostica e estorna auditavelmente recebimentos acima da quantidade embarcada por item.';

    public function handle(
        EntregaProdutoService $entregas,
        EstoqueDisponibilidadeService $disponibilidade
    ): int {
        $identificador = trim((string) $this->argument('pedido'));
        $pedido = Pedido::query()
            ->where(function ($query) use ($identificador) {
                if (ctype_digit($identificador)) {
                    $query->whereKey((int) $identificador);
                }

                $query->orWhere('numero_externo', $identificador);
            })
            ->first();

        if (! $pedido) {
            $this->error("Pedido {$identificador} nao encontrado.");

            return self::FAILURE;
        }

        if (! $pedido->isReposicao()) {
            $this->error('A correcao automatica e restrita a pedidos de reposicao.');

            return self::FAILURE;
        }

        $rotulo = (string) ($pedido->numero_externo ?: $pedido->id);
        $diagnostico = $this->diagnosticar($pedido, $entregas, $disponibilidade);
        $this->exibir($rotulo, $diagnostico);

        if (! $diagnostico['controle_por_item']) {
            $this->error('O pedido nao possui embarque da fabrica registrado por item.');

            return self::FAILURE;
        }

        if ($diagnostico['itens']->isEmpty()) {
            $this->info('Nenhum recebimento acima do embarcado foi encontrado; nenhuma alteracao e necessaria.');

            return self::SUCCESS;
        }

        if ($diagnostico['bloqueios']->isNotEmpty()) {
            $this->error('A correcao foi bloqueada. Resolva os impedimentos exibidos antes de aplicar.');

            return self::FAILURE;
        }

        if (! $this->option('aplicar')) {
            $this->info('Diagnostico concluido em dry-run; nenhum registro foi alterado.');
            $this->line("Para aplicar: php artisan pedidos:corrigir-recebimento-nao-embarcado {$identificador} --aplicar --confirmacao={$rotulo}");

            return self::SUCCESS;
        }

        if (trim((string) $this->option('confirmacao')) !== $rotulo) {
            $this->error("Confirmacao invalida. Informe --confirmacao={$rotulo}.");

            return self::FAILURE;
        }

        try {
            $resultado = DB::transaction(function () use ($pedido, $entregas, $disponibilidade, $rotulo) {
                $pedidoBloqueado = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
                $diagnosticoAtual = $this->diagnosticar($pedidoBloqueado, $entregas, $disponibilidade);
                if ($diagnosticoAtual['bloqueios']->isNotEmpty()) {
                    throw new \RuntimeException($diagnosticoAtual['bloqueios']->implode(' '));
                }

                $eventosEstornados = [];

                foreach ($diagnosticoAtual['itens'] as $item) {
                    foreach ($item['eventos'] as $eventoId) {
                        $evento = ProdutoEntregaEvento::query()->lockForUpdate()->findOrFail($eventoId);
                        $estorno = $entregas->estornarEvento(
                            $evento,
                            null,
                            "Correcao do pedido {$rotulo}: item recebido sem embarque da fabrica."
                        );
                        $eventosEstornados[] = [
                            'evento_original_id' => (int) $evento->id,
                            'evento_estorno_id' => (int) $estorno->id,
                            'movimentacao_original_id' => (int) $evento->estoque_movimentacao_id,
                            'movimentacao_estorno_id' => (int) $estorno->estoque_movimentacao_id,
                        ];
                    }
                }

                $pedidoAtualizado = Pedido::query()->with('entregaItens')->findOrFail($pedido->id);
                $total = (int) $pedidoAtualizado->entregaItens
                    ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
                    ->sum('quantidade_total');
                $recebido = (int) $pedidoAtualizado->entregaItens
                    ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
                    ->sum('quantidade_recebida');

                $statusRemovidos = [];
                if ($recebido < $total) {
                    $statusAutomaticos = $pedidoAtualizado->historicoStatus()
                        ->where(function ($query) {
                            $query->where(function ($status) {
                                $status->where('status', PedidoStatus::FINALIZADO->value)
                                    ->where('observacoes', self::OBSERVACAO_FINALIZADO);
                            })->orWhere(function ($status) {
                                $status->where('status', PedidoStatus::ENTREGA_ESTOQUE->value)
                                    ->where('observacoes', self::OBSERVACAO_ENTREGA_ESTOQUE);
                            });
                        })
                        ->lockForUpdate()
                        ->get();
                    $statusRemovidos = $statusAutomaticos->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $statusAutomaticos->each->delete();
                }

                logAuditoria('pedido_recebimento_correcao', "Recebimento nao embarcado corrigido no Pedido #{$rotulo}.", [
                    'acao' => 'estorno_recebimento_nao_embarcado',
                    'nivel' => 'warn',
                    'pedido_id' => (int) $pedidoAtualizado->id,
                    'eventos' => $eventosEstornados,
                    'status_historico_removidos' => $statusRemovidos,
                    'quantidade_total' => $total,
                    'quantidade_recebida_apos_correcao' => $recebido,
                ], $pedidoAtualizado);

                return compact('eventosEstornados', 'statusRemovidos', 'total', 'recebido');
            });
        } catch (Throwable $exception) {
            $this->error('Falha ao aplicar a correcao: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Correcao aplicada: %d evento(s) estornado(s), recebimento %d/%d.',
            count($resultado['eventosEstornados']),
            $resultado['recebido'],
            $resultado['total']
        ));

        return self::SUCCESS;
    }

    /** @return array{controle_por_item:bool,itens:Collection<int,array<string,mixed>>,bloqueios:Collection<int,string>} */
    private function diagnosticar(
        Pedido $pedido,
        EntregaProdutoService $entregas,
        EstoqueDisponibilidadeService $disponibilidade
    ): array {
        $pedido->load([
            'entregaItens.eventos',
            'entregaItens.variacao.produto',
        ]);
        $controlePorItem = $pedido->historicoStatusItens()
            ->where('status', PedidoStatus::EMBARQUE_FABRICA->value)
            ->exists();
        $itens = collect();
        $bloqueios = collect();

        foreach ($pedido->entregaItens->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO) as $item) {
            $embarcada = $entregas->quantidadeEmbarcadaFabrica($item);
            if ($embarcada === null || (int) $item->quantidade_recebida <= $embarcada) {
                continue;
            }

            $excesso = (int) $item->quantidade_recebida - $embarcada;
            $idsEstornados = $item->eventos
                ->where('tipo_evento', ProdutoEntregaEvento::ESTORNADO)
                ->map(fn (ProdutoEntregaEvento $evento) => (int) data_get($evento->metadata_json, 'evento_original_id', 0))
                ->filter()
                ->flip();
            $eventos = $item->eventos
                ->where('tipo_evento', ProdutoEntregaEvento::RECEBIDO_ESTOQUE)
                ->reject(fn (ProdutoEntregaEvento $evento) => $idsEstornados->has((int) $evento->id))
                ->sortByDesc('id');
            $selecionados = collect();
            $quantidadeSelecionada = 0;

            foreach ($eventos as $evento) {
                if ($quantidadeSelecionada >= $excesso) {
                    break;
                }
                $quantidadeSelecionada += (int) $evento->quantidade;
                $selecionados->push($evento);
            }

            if ($quantidadeSelecionada !== $excesso) {
                $bloqueios->push("Item {$item->id}: o excesso {$excesso} nao corresponde a eventos integrais de recebimento.");
            }

            foreach ($selecionados as $evento) {
                if (! $evento->estoque_movimentacao_id || ! $evento->id_deposito_destino) {
                    $bloqueios->push("Evento {$evento->id}: movimentacao ou deposito de recebimento ausente.");

                    continue;
                }

                $disponivelAtual = $disponibilidade->getDisponivel(
                    (int) $item->id_variacao,
                    (int) $evento->id_deposito_destino
                );
                if ($disponivelAtual < (int) $evento->quantidade) {
                    $bloqueios->push("Evento {$evento->id}: saldo livre insuficiente para estorno ({$disponivelAtual} disponivel).");
                }

                $movimentacao = EstoqueMovimentacao::query()->find($evento->estoque_movimentacao_id);
                $possuiSaidaPosterior = $movimentacao && EstoqueMovimentacao::query()
                    ->where('id', '>', $movimentacao->id)
                    ->where('id_variacao', $item->id_variacao)
                    ->where('id_deposito_origem', $evento->id_deposito_destino)
                    ->where('tipo', '!=', EstoqueMovimentacaoTipo::ESTORNO->value)
                    ->exists();
                if ($possuiSaidaPosterior) {
                    $bloqueios->push("Evento {$evento->id}: existe saida posterior da mesma variacao e deposito.");
                }
            }

            $itens->push([
                'produto_entrega_item_id' => (int) $item->id,
                'pedido_item_id' => (int) $item->pedido_item_id,
                'produto' => $item->variacao?->produto?->nome ?: "Item {$item->id}",
                'embarcada' => $embarcada,
                'recebida' => (int) $item->quantidade_recebida,
                'excesso' => $excesso,
                'eventos' => $selecionados->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ]);
        }

        return [
            'controle_por_item' => $controlePorItem,
            'itens' => $itens,
            'bloqueios' => $bloqueios,
        ];
    }

    /** @param array{itens:Collection<int,array<string,mixed>>,bloqueios:Collection<int,string>} $diagnostico */
    private function exibir(string $pedido, array $diagnostico): void
    {
        $this->line("Pedido: {$pedido}");
        $this->table(
            ['Item operacional', 'Produto', 'Embarcado', 'Recebido', 'Excesso', 'Eventos a estornar'],
            $diagnostico['itens']->map(fn (array $item) => [
                $item['produto_entrega_item_id'],
                $item['produto'],
                $item['embarcada'],
                $item['recebida'],
                $item['excesso'],
                implode(', ', $item['eventos']),
            ])->all()
        );

        foreach ($diagnostico['bloqueios'] as $bloqueio) {
            $this->warn($bloqueio);
        }
    }
}
