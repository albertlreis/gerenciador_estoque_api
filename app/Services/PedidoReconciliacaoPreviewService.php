<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class PedidoReconciliacaoPreviewService
{
    /**
     * Resolve primeiro pelo numero externo para permitir a auditoria operacional
     * com o identificador visivel ao usuario (por exemplo, 20009).
     *
     * @return array<string,mixed>
     */
    public function previewPorIdentificador(int|string $identificador): array
    {
        $identificador = trim((string) $identificador);

        $pedido = Pedido::query()
            ->where('numero_externo', $identificador)
            ->first();

        if (! $pedido && ctype_digit($identificador)) {
            $pedido = Pedido::query()->find((int) $identificador);
        }

        abort_unless($pedido, 404, 'Pedido nao encontrado.');

        return $this->preview($pedido);
    }

    /**
     * Gera somente uma fotografia de leitura. Nenhum dado e persistido.
     *
     * @return array<string,mixed>
     */
    public function preview(Pedido $pedido): array
    {
        $pedido->loadMissing('statusAtual');

        $itens = ProdutoEntregaItem::query()
            ->with(['variacao.produto', 'eventos'])
            ->where('pedido_id', $pedido->id)
            ->orderBy('id')
            ->get();

        $canonicos = $itens
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
            ->values();
        $devolucoes = $itens
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_DEVOLUCAO)
            ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
            ->values();

        $status = (string) ($pedido->statusAtual?->getRawOriginal('status') ?? '');
        $statusEntregaEstoque = $status === PedidoStatus::ENTREGA_ESTOQUE->value;
        $eventosConflitantes = $statusEntregaEstoque
            ? $this->eventosClienteAtivos($canonicos)
            : collect();

        $movimentacoes = $this->movimentacoesRelacionadas($pedido, $canonicos, $eventosConflitantes);
        $estornosExistentes = EstoqueMovimentacao::query()
            ->where('ref_type', 'estorno')
            ->whereIn('ref_id', $movimentacoes->pluck('id')->filter()->all())
            ->get()
            ->keyBy('ref_id');

        $movimentacaoIdsDosEventos = $eventosConflitantes
            ->pluck('estoque_movimentacao_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->flip();
        $movimentosCandidatos = $movimentacoes
            ->filter(fn (EstoqueMovimentacao $movimento) => $this->movimentoCandidatoAEstorno(
                $movimento,
                $statusEntregaEstoque,
                $movimentacaoIdsDosEventos,
                $estornosExistentes
            ))
            ->values();

        $variacaoIds = $canonicos
            ->concat($devolucoes)
            ->pluck('id_variacao')
            ->concat($movimentacoes->pluck('id_variacao'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $saldos = $this->saldosAtuais($variacaoIds);
        $depositos = $this->depositosDoPreview($itens, $movimentacoes, $saldos);
        $reposicoesPreview = $this->reposicoesProjetadas($movimentosCandidatos);

        $movimentosFormatados = $movimentacoes
            ->map(fn (EstoqueMovimentacao $movimento) => $this->formatarMovimento(
                $movimento,
                $depositos,
                $saldos,
                $reposicoesPreview,
                $movimentosCandidatos,
                $estornosExistentes
            ))
            ->values();

        $temContadoresConflitantes = $statusEntregaEstoque && $canonicos->contains(
            fn (ProdutoEntregaItem $item) => (int) $item->quantidade_expedida > 0
                || (int) $item->quantidade_entregue > 0
        );
        $recebimentoAusente = $statusEntregaEstoque && $canonicos->contains(
            fn (ProdutoEntregaItem $item) => (int) $item->quantidade_recebida < (int) $item->quantidade_total
        );
        $divergencia = $eventosConflitantes->isNotEmpty()
            || $temContadoresConflitantes
            || $recebimentoAusente;

        return [
            'dry_run' => true,
            'pedido' => [
                'id' => (int) $pedido->id,
                'numero_externo' => $pedido->numero_externo,
                'tipo' => $pedido->tipo,
                'origem_abastecimento' => $pedido->origem_abastecimento,
            ],
            'status_fonte_verdade' => [
                'codigo' => $status !== '' ? $status : null,
                'rotulo' => $this->rotuloStatus($status, $pedido),
                'origem' => 'pedido_status_historico',
                'registrado_em' => $this->dataIso($pedido->statusAtual?->data_status ?? $pedido->statusAtual?->created_at),
            ],
            'divergencia' => $divergencia,
            'saldo_fisico_informado' => null,
            'exige_conferencia_fisica' => true,
            'itens_canonicos' => $canonicos
                ->map(fn (ProdutoEntregaItem $item) => $this->formatarItem(
                    $item,
                    $statusEntregaEstoque,
                    $eventosConflitantes
                ))
                ->values(),
            'eventos_conflitantes' => $eventosConflitantes
                ->map(fn (ProdutoEntregaEvento $evento) => $this->formatarEvento($evento, $depositos))
                ->values(),
            'movimentos_relacionados' => $movimentosFormatados,
            'saldos_atuais' => $this->formatarSaldos($saldos, $reposicoesPreview, $depositos),
            'preview_estorno' => [
                'movimentos_candidatos' => $movimentosFormatados
                    ->where('candidato_estorno', true)
                    ->values(),
                'saldo_fisico_informado' => null,
                'exige_conferencia_fisica' => true,
                'aplicacao_automatica' => false,
            ],
            'devolucoes' => $devolucoes
                ->map(fn (ProdutoEntregaItem $item) => $this->formatarDevolucao($item))
                ->values(),
        ];
    }

    /** @return Collection<int,ProdutoEntregaEvento> */
    private function eventosClienteAtivos(Collection $itens): Collection
    {
        $eventos = $itens->flatMap(fn (ProdutoEntregaItem $item) => $item->eventos);
        $idsEstornados = $eventos
            ->where('tipo_evento', ProdutoEntregaEvento::ESTORNADO)
            ->map(fn (ProdutoEntregaEvento $evento) => (int) (($evento->metadata_json ?? [])['evento_original_id'] ?? 0))
            ->filter()
            ->flip();

        return $eventos
            ->whereIn('tipo_evento', [
                ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                ProdutoEntregaEvento::ENTREGUE_CLIENTE,
            ])
            ->reject(fn (ProdutoEntregaEvento $evento) => $idsEstornados->has((int) $evento->id))
            ->sortBy('id')
            ->values();
    }

    /** @return EloquentCollection<int,EstoqueMovimentacao> */
    private function movimentacoesRelacionadas(Pedido $pedido, Collection $itens, Collection $eventos): EloquentCollection
    {
        $pedidoItemIds = $itens->pluck('pedido_item_id')->filter()->map(fn ($id) => (int) $id)->values();
        $movimentacaoIds = $eventos->pluck('estoque_movimentacao_id')->filter()->map(fn ($id) => (int) $id)->values();

        return EstoqueMovimentacao::query()
            ->with(['variacao.produto', 'depositoOrigem:id,nome', 'depositoDestino:id,nome'])
            ->where(function ($query) use ($pedido, $pedidoItemIds, $movimentacaoIds) {
                $query->where('pedido_id', $pedido->id);

                if ($pedidoItemIds->isNotEmpty()) {
                    $query->orWhereIn('pedido_item_id', $pedidoItemIds->all());
                }

                if ($movimentacaoIds->isNotEmpty()) {
                    $query->orWhereIn('id', $movimentacaoIds->all());
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function movimentoCandidatoAEstorno(
        EstoqueMovimentacao $movimento,
        bool $statusEntregaEstoque,
        Collection $movimentacaoIdsDosEventos,
        Collection $estornosExistentes
    ): bool {
        if (! $statusEntregaEstoque || $estornosExistentes->has((int) $movimento->id)) {
            return false;
        }

        if (! $movimento->id_deposito_origem || $movimento->id_deposito_destino) {
            return false;
        }

        return $movimentacaoIdsDosEventos->has((int) $movimento->id)
            || in_array($movimento->tipo, [
                EstoqueMovimentacaoTipo::SAIDA->value,
                EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            ], true);
    }

    /** @return EloquentCollection<int,Estoque> */
    private function saldosAtuais(Collection $variacaoIds): EloquentCollection
    {
        if ($variacaoIds->isEmpty()) {
            return new EloquentCollection;
        }

        return Estoque::query()
            ->with(['deposito:id,nome', 'variacao.produto'])
            ->whereIn('id_variacao', $variacaoIds->all())
            ->orderBy('id_variacao')
            ->orderBy('id_deposito')
            ->get();
    }

    /** @return Collection<string,int> */
    private function reposicoesProjetadas(Collection $movimentos): Collection
    {
        return $movimentos
            ->groupBy(fn (EstoqueMovimentacao $movimento) => $this->chaveSaldo(
                (int) $movimento->id_variacao,
                (int) $movimento->id_deposito_origem
            ))
            ->map(fn (Collection $grupo) => (int) $grupo->sum('quantidade'));
    }

    private function depositosDoPreview(Collection $itens, Collection $movimentos, Collection $saldos): Collection
    {
        $ids = $itens->pluck('id_deposito_origem')
            ->concat($itens->pluck('id_deposito_destino'))
            ->concat($movimentos->pluck('id_deposito_origem'))
            ->concat($movimentos->pluck('id_deposito_destino'))
            ->concat($saldos->pluck('id_deposito'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : Deposito::query()->whereIn('id', $ids->all())->pluck('nome', 'id');
    }

    private function formatarItem(
        ProdutoEntregaItem $item,
        bool $statusEntregaEstoque,
        Collection $eventosConflitantes
    ): array {
        return [
            'produto_entrega_item_id' => (int) $item->id,
            'pedido_item_id' => $item->pedido_item_id ? (int) $item->pedido_item_id : null,
            'id_variacao' => (int) $item->id_variacao,
            'referencia' => $item->variacao?->referencia,
            'produto' => $item->variacao?->produto?->nome,
            'variacao' => $item->variacao?->nome,
            'esperado' => (int) $item->quantidade_total,
            'recebido' => (int) $item->quantidade_recebida,
            'reservado' => (int) $item->quantidade_reservada,
            'expedido' => (int) $item->quantidade_expedida,
            'entregue' => (int) $item->quantidade_entregue,
            'deposito_recebimento_id' => $item->id_deposito_destino ? (int) $item->id_deposito_destino : null,
            'deposito_saida_id' => $item->id_deposito_origem ? (int) $item->id_deposito_origem : null,
            'divergente' => $statusEntregaEstoque && (
                $eventosConflitantes->contains(
                    fn (ProdutoEntregaEvento $evento) => (int) $evento->produto_entrega_item_id === (int) $item->id
                )
                || (int) $item->quantidade_expedida > 0
                || (int) $item->quantidade_entregue > 0
                || (int) $item->quantidade_recebida < (int) $item->quantidade_total
            ),
        ];
    }

    private function formatarEvento(ProdutoEntregaEvento $evento, Collection $depositos): array
    {
        return [
            'id' => (int) $evento->id,
            'produto_entrega_item_id' => (int) $evento->produto_entrega_item_id,
            'tipo' => $evento->tipo_evento,
            'quantidade' => (int) $evento->quantidade,
            'ocorrido_em' => $this->dataIso($evento->ocorrido_em ?? $evento->created_at),
            'estoque_movimentacao_id' => $evento->estoque_movimentacao_id ? (int) $evento->estoque_movimentacao_id : null,
            'deposito_origem' => $this->deposito($evento->id_deposito_origem, $depositos),
            'deposito_destino' => $this->deposito($evento->id_deposito_destino, $depositos),
            'ativo' => true,
            'motivo_divergencia' => 'status_entrega_estoque_indica_produto_no_estoque',
        ];
    }

    private function formatarMovimento(
        EstoqueMovimentacao $movimento,
        Collection $depositos,
        Collection $saldos,
        Collection $reposicoesPreview,
        Collection $movimentosCandidatos,
        Collection $estornosExistentes
    ): array {
        $candidato = $movimentosCandidatos->contains('id', $movimento->id);
        $saldoAtualOrigem = $this->saldoAtual($saldos, (int) $movimento->id_variacao, $movimento->id_deposito_origem);
        $chave = $movimento->id_deposito_origem
            ? $this->chaveSaldo((int) $movimento->id_variacao, (int) $movimento->id_deposito_origem)
            : null;

        return [
            'id' => (int) $movimento->id,
            'tipo' => $movimento->tipo,
            'quantidade' => (int) $movimento->quantidade,
            'id_variacao' => (int) $movimento->id_variacao,
            'referencia' => $movimento->variacao?->referencia,
            'produto' => $movimento->variacao?->produto?->nome,
            'pedido_id' => $movimento->pedido_id ? (int) $movimento->pedido_id : null,
            'pedido_item_id' => $movimento->pedido_item_id ? (int) $movimento->pedido_item_id : null,
            'deposito_origem' => $this->deposito($movimento->id_deposito_origem, $depositos),
            'deposito_destino' => $this->deposito($movimento->id_deposito_destino, $depositos),
            'data_movimentacao' => $this->dataIso($movimento->data_movimentacao ?? $movimento->created_at),
            'candidato_estorno' => $candidato,
            'estornado_por_movimentacao_id' => $estornosExistentes->get((int) $movimento->id)?->id,
            'saldo_atual' => $saldoAtualOrigem,
            'saldo_resultante_preview' => $candidato && $saldoAtualOrigem !== null
                ? $saldoAtualOrigem + (int) ($reposicoesPreview->get($chave, 0))
                : $saldoAtualOrigem,
        ];
    }

    private function formatarSaldos(Collection $saldos, Collection $reposicoesPreview, Collection $depositos): Collection
    {
        $formatados = $saldos->map(function (Estoque $saldo) use ($reposicoesPreview, $depositos) {
            $chave = $this->chaveSaldo((int) $saldo->id_variacao, (int) $saldo->id_deposito);
            $reposicao = (int) $reposicoesPreview->get($chave, 0);

            return [
                'id_variacao' => (int) $saldo->id_variacao,
                'referencia' => $saldo->variacao?->referencia,
                'produto' => $saldo->variacao?->produto?->nome,
                'deposito' => $this->deposito($saldo->id_deposito, $depositos),
                'saldo_atual' => (int) $saldo->quantidade,
                'quantidade_estorno_preview' => $reposicao,
                'saldo_resultante_preview' => (int) $saldo->quantidade + $reposicao,
            ];
        });

        $chavesExistentes = $saldos
            ->map(fn (Estoque $saldo) => $this->chaveSaldo((int) $saldo->id_variacao, (int) $saldo->id_deposito))
            ->flip();

        foreach ($reposicoesPreview as $chave => $quantidade) {
            if ($chavesExistentes->has($chave)) {
                continue;
            }

            [$variacaoId, $depositoId] = array_map('intval', explode(':', $chave, 2));
            $formatados->push([
                'id_variacao' => $variacaoId,
                'referencia' => null,
                'produto' => null,
                'deposito' => $this->deposito($depositoId, $depositos),
                'saldo_atual' => 0,
                'quantidade_estorno_preview' => (int) $quantidade,
                'saldo_resultante_preview' => (int) $quantidade,
            ]);
        }

        return $formatados->values();
    }

    private function formatarDevolucao(ProdutoEntregaItem $item): array
    {
        return [
            'produto_entrega_item_id' => (int) $item->id,
            'origem_id' => $item->origem_id ? (int) $item->origem_id : null,
            'devolucao_item_id' => $item->devolucao_item_id ? (int) $item->devolucao_item_id : null,
            'pedido_item_id' => $item->pedido_item_id ? (int) $item->pedido_item_id : null,
            'id_variacao' => (int) $item->id_variacao,
            'referencia' => $item->variacao?->referencia,
            'produto' => $item->variacao?->produto?->nome,
            'variacao' => $item->variacao?->nome,
            'quantidade_total' => (int) $item->quantidade_total,
            'quantidade_recebida' => (int) $item->quantidade_recebida,
            'status' => $item->status,
            'eventos' => $item->eventos->map(fn (ProdutoEntregaEvento $evento) => [
                'id' => (int) $evento->id,
                'tipo' => $evento->tipo_evento,
                'quantidade' => (int) $evento->quantidade,
                'ocorrido_em' => $this->dataIso($evento->ocorrido_em ?? $evento->created_at),
            ])->values(),
        ];
    }

    private function saldoAtual(Collection $saldos, int $variacaoId, mixed $depositoId): ?int
    {
        if (! $depositoId) {
            return null;
        }

        $saldo = $saldos->first(fn (Estoque $item) => (int) $item->id_variacao === $variacaoId
            && (int) $item->id_deposito === (int) $depositoId);

        return $saldo ? (int) $saldo->quantidade : 0;
    }

    private function deposito(mixed $id, Collection $depositos): ?array
    {
        if (! $id) {
            return null;
        }

        return [
            'id' => (int) $id,
            'nome' => $depositos->get((int) $id),
        ];
    }

    private function rotuloStatus(string $status, Pedido $pedido): ?string
    {
        if ($status === '') {
            return null;
        }

        if ($status === PedidoStatus::ENTREGA_ESTOQUE->value) {
            return $pedido->isVenda()
                ? 'Recebido no estoque — aguardando entrega ao cliente'
                : 'Recebido no estoque';
        }

        return PedidoStatus::tryFrom($status)?->label() ?? $status;
    }

    private function chaveSaldo(int $variacaoId, int $depositoId): string
    {
        return "{$variacaoId}:{$depositoId}";
    }

    private function dataIso(mixed $data): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }

        return ($data instanceof CarbonInterface ? $data : Carbon::parse($data))->toISOString();
    }
}
