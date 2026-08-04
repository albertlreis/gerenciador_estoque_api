<?php

namespace App\Console\Commands;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\Consignacao;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Services\EntregaProdutoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReconciliarConsignacoesEstoqueCommand extends Command
{
    protected $signature = 'consignacoes:reconciliar-estoque
        {--execute : Persiste as correcoes seguras}
        {--dry-run : Forca simulacao, mesmo com --execute}
        {--consignacao=* : Limita a reconciliacao aos IDs de consignacao informados}';

    protected $description = 'Reconcilia demandas, reservas e movimentacoes centrais de consignacoes sem heuristicas agressivas.';

    /** @var array<string,int> */
    private array $contadores = [
        'consignacoes_analisadas' => 0,
        'demandas_criadas' => 0,
        'envios_alinhados' => 0,
        'reservas_adotadas' => 0,
        'reservas_criadas' => 0,
        'sem_saldo_para_reserva' => 0,
        'reservas_canceladas' => 0,
        'reservas_duplicadas_detectadas' => 0,
        'reservas_duplicadas_corrigidas' => 0,
        'reservas_duplicadas_ambiguas' => 0,
        'devolucoes_orfas' => 0,
    ];

    /** @var array<int,array{consignacao_id:int,pedido_item_id:int,status:string,detalhe:string}> */
    private array $duplicidades = [];

    public function handle(EntregaProdutoService $entregas): int
    {
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $consignacaoIds = $this->consignacaoIds();

        if ($consignacaoIds === null) {
            return self::FAILURE;
        }

        if ($consignacaoIds !== [] && Consignacao::query()->whereIn('id', $consignacaoIds)->count() !== count($consignacaoIds)) {
            $this->error('Uma ou mais consignacoes informadas nao existem. Nenhuma correcao foi executada.');

            return self::FAILURE;
        }

        $this->info($execute
            ? 'Reconciliação de consignações com persistência.'
            : 'Reconciliação de consignações em dry-run.');

        if ($execute) {
            DB::transaction(fn () => $this->reconciliarConsignacoes($entregas, true, $consignacaoIds));
        } else {
            $this->reconciliarConsignacoes($entregas, false, $consignacaoIds);
        }

        $this->contadores['devolucoes_orfas'] = $this->contarDevolucoesOrfas();

        $this->table(
            ['Metrica', 'Total'],
            collect($this->contadores)->map(fn (int $total, string $nome) => [$nome, $total])->values()->all()
        );

        if ($this->duplicidades !== []) {
            $this->table(
                ['Consignacao', 'Pedido item', 'Status', 'Detalhe'],
                collect($this->duplicidades)
                    ->map(fn (array $item) => [
                        $item['consignacao_id'],
                        $item['pedido_item_id'],
                        $item['status'],
                        $item['detalhe'],
                    ])
                    ->all()
            );
        }

        if ($this->contadores['devolucoes_orfas'] > 0) {
            $this->warn("{$this->contadores['devolucoes_orfas']} movimentacoes de devolucao de consignacao sem vinculo foram apenas reportadas.");
        }

        return self::SUCCESS;
    }

    /** @param array<int,int> $consignacaoIds */
    private function reconciliarConsignacoes(EntregaProdutoService $entregas, bool $execute, array $consignacaoIds): void
    {
        $query = Consignacao::query()
            ->with(['entregaItem'])
            ->when($consignacaoIds !== [], fn (Builder $query) => $query->whereIn('id', $consignacaoIds))
            ->orderBy('id');

        $query
            ->chunkById(100, function ($consignacoes) use ($entregas, $execute) {
                foreach ($consignacoes as $consignacao) {
                    $this->contadores['consignacoes_analisadas']++;

                    $entrega = $consignacao->entregaItem;
                    if (! $entrega) {
                        $this->contadores['demandas_criadas']++;
                        if ($execute) {
                            $entrega = $entregas->criarDemandaConsignacao($consignacao, null);
                        }
                    }

                    $enviado = $this->quantidadeMovimentada($consignacao->id, EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value);

                    if ($consignacao->status === 'pendente') {
                        $this->reconciliarReservaDuplicada($consignacao, $entrega, $execute);

                        if ($enviado > 0) {
                            $this->alinharEnvioPendente($entrega, $enviado, $execute);

                            continue;
                        }

                        $this->garantirReservaPendente($consignacao, $entrega, $entregas, $execute);

                        continue;
                    }

                    if (in_array($consignacao->status, ['comprado', 'devolvido', 'parcial'], true)) {
                        $this->cancelarReservasRemanescentes($consignacao, $entrega, $execute);
                    }
                }
            });
    }

    private function reconciliarReservaDuplicada(
        Consignacao $consignacao,
        ?ProdutoEntregaItem $entregaConsignacao,
        bool $execute
    ): void {
        if (! $entregaConsignacao || ! $consignacao->pedido_item_id) {
            return;
        }

        $entregaPedido = ProdutoEntregaItem::query()
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('pedido_id', $consignacao->pedido_id)
            ->where('pedido_item_id', $consignacao->pedido_item_id)
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where('status', '!=', ProdutoEntregaItem::STATUS_CANCELADO)
            ->when($execute, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $entregaPedido) {
            return;
        }

        if ($execute) {
            $entregaConsignacao = ProdutoEntregaItem::query()
                ->lockForUpdate()
                ->findOrFail($entregaConsignacao->id);
        }

        $reservasPedido = $this->reservasAbertasVinculadasEntrega($entregaPedido, $consignacao, $execute);
        $reservasConsignacao = $this->reservasAbertasVinculadasEntrega($entregaConsignacao, $consignacao, $execute);

        if ($reservasPedido->isEmpty() || $reservasConsignacao->isEmpty()) {
            return;
        }

        $this->contadores['reservas_duplicadas_detectadas']++;

        $idsCompartilhados = $reservasPedido->pluck('id')->intersect($reservasConsignacao->pluck('id'));
        $quantidadePedido = (int) $reservasPedido->sum(fn (EstoqueReserva $reserva) => $this->quantidadeAberta($reserva));
        $quantidadeConsignacao = (int) $reservasConsignacao->sum(fn (EstoqueReserva $reserva) => $this->quantidadeAberta($reserva));
        $quantidadeEsperada = (int) $consignacao->quantidade;
        $estadoSemMovimento = (int) $entregaPedido->quantidade_recebida === 0
            && (int) $entregaPedido->quantidade_expedida === 0
            && (int) $entregaPedido->quantidade_entregue === 0
            && (int) $entregaConsignacao->quantidade_recebida === 0
            && (int) $entregaConsignacao->quantidade_expedida === 0
            && (int) $entregaConsignacao->quantidade_entregue === 0
            && $this->quantidadeMovimentada((int) $consignacao->id, EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value) === 0;
        $espelhoExato = $idsCompartilhados->isEmpty()
            && $quantidadePedido === $quantidadeEsperada
            && $quantidadeConsignacao === $quantidadeEsperada
            && (int) $entregaPedido->quantidade_total === $quantidadeEsperada
            && (int) $entregaPedido->quantidade_reservada === $quantidadePedido
            && (int) $entregaConsignacao->quantidade_total === $quantidadeEsperada
            && (int) $entregaConsignacao->quantidade_reservada === $quantidadeConsignacao
            && $reservasPedido->every(fn (EstoqueReserva $reserva) => (int) $reserva->quantidade_consumida === 0)
            && $reservasConsignacao->every(fn (EstoqueReserva $reserva) => (int) $reserva->quantidade_consumida === 0)
            && $estadoSemMovimento;

        if (! $espelhoExato) {
            $this->contadores['reservas_duplicadas_ambiguas']++;
            $this->duplicidades[] = [
                'consignacao_id' => (int) $consignacao->id,
                'pedido_item_id' => (int) $consignacao->pedido_item_id,
                'status' => 'ambigua',
                'detalhe' => "pedido={$quantidadePedido}; consignacao={$quantidadeConsignacao}; esperado={$quantidadeEsperada}",
            ];

            return;
        }

        $this->duplicidades[] = [
            'consignacao_id' => (int) $consignacao->id,
            'pedido_item_id' => (int) $consignacao->pedido_item_id,
            'status' => $execute ? 'corrigida' : 'detectada',
            'detalhe' => "cancelar reserva do pedido={$quantidadePedido}; preservar consignacao={$quantidadeConsignacao}",
        ];

        if (! $execute) {
            return;
        }

        foreach ($reservasPedido as $reserva) {
            $quantidade = $this->quantidadeAberta($reserva);
            $reserva->forceFill([
                'status' => 'cancelada',
                'motivo' => 'reconciliacao_reserva_duplicada_consignacao',
            ])->save();

            ProdutoEntregaEvento::query()->firstOrCreate(
                ['idempotency_key' => "consignacao:{$consignacao->id}:cancelar-reserva-duplicada:{$reserva->id}"],
                [
                    'produto_entrega_item_id' => $entregaPedido->id,
                    'tipo_evento' => ProdutoEntregaEvento::RESERVA_CANCELADA,
                    'quantidade' => $quantidade,
                    'id_deposito_origem' => $reserva->id_deposito,
                    'estoque_reserva_id' => $reserva->id,
                    'observacao' => 'Reserva duplicada da demanda comum cancelada; reserva canonica da consignacao preservada.',
                    'metadata_json' => [
                        'consignacao_id' => (int) $consignacao->id,
                        'motivo' => 'reserva_espelhada_pedido_consignacao',
                    ],
                ]
            );
        }

        $entregaPedido->forceFill([
            'quantidade_reservada' => 0,
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
            'em_revisao' => false,
            'bloqueio_motivo' => null,
        ])->save();

        $this->contadores['reservas_duplicadas_corrigidas']++;
    }

    private function reservasAbertasVinculadasEntrega(
        ProdutoEntregaItem $entrega,
        Consignacao $consignacao,
        bool $lock
    ) {
        return EstoqueReserva::query()
            ->where('estoque_reservas.id_variacao', $consignacao->produto_variacao_id)
            ->where('estoque_reservas.id_deposito', $consignacao->deposito_id)
            ->where('estoque_reservas.pedido_id', $consignacao->pedido_id)
            ->where('estoque_reservas.pedido_item_id', $consignacao->pedido_item_id)
            ->where('estoque_reservas.status', 'ativa')
            ->where(function ($query) {
                $query->whereNull('estoque_reservas.data_expira')
                    ->orWhere('estoque_reservas.data_expira', '>', now());
            })
            ->whereRaw('estoque_reservas.quantidade > estoque_reservas.quantidade_consumida')
            ->whereExists(function ($query) use ($entrega) {
                $query->selectRaw('1')
                    ->from('produto_entrega_eventos as evento_reserva')
                    ->whereColumn('evento_reserva.estoque_reserva_id', 'estoque_reservas.id')
                    ->where('evento_reserva.produto_entrega_item_id', $entrega->id)
                    ->where('evento_reserva.tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA);
            })
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('estoque_reservas.id')
            ->get();
    }

    private function quantidadeAberta(EstoqueReserva $reserva): int
    {
        return max(0, (int) $reserva->quantidade - (int) $reserva->quantidade_consumida);
    }

    /** @return array<int,int>|null */
    private function consignacaoIds(): ?array
    {
        $opcoes = collect((array) $this->option('consignacao'));
        $invalidas = $opcoes->filter(fn ($id) => ! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id <= 0);

        if ($invalidas->isNotEmpty()) {
            $this->error('Informe apenas IDs numericos e positivos em --consignacao.');

            return null;
        }

        return $opcoes->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function alinharEnvioPendente(?ProdutoEntregaItem $entrega, int $enviado, bool $execute): void
    {
        if (! $entrega || (int) $entrega->quantidade_expedida >= $enviado) {
            return;
        }

        $this->contadores['envios_alinhados']++;

        if (! $execute) {
            return;
        }

        $entrega->quantidade_expedida = min((int) $entrega->quantidade_total, $enviado);
        $entrega->status = ProdutoEntregaItem::STATUS_RESERVADO;
        $entrega->bloqueio_motivo = null;
        $entrega->em_revisao = false;
        $entrega->save();
    }

    private function garantirReservaPendente(
        Consignacao $consignacao,
        ?ProdutoEntregaItem $entrega,
        EntregaProdutoService $entregas,
        bool $execute
    ): void {
        if (! $entrega && $execute) {
            return;
        }

        $pendenteReserva = $entrega
            ? max(0, (int) $entrega->quantidade_total - (int) $entrega->quantidade_reservada - (int) $entrega->quantidade_expedida)
            : max(0, (int) $consignacao->quantidade);
        if ($pendenteReserva <= 0) {
            return;
        }

        $reservasAbertas = $this->reservasAbertasDaConsignacao($consignacao)->get();
        $quantidadeAberta = (int) $reservasAbertas->sum(fn (EstoqueReserva $reserva) => max(0, (int) $reserva->quantidade - (int) $reserva->quantidade_consumida));

        if ($quantidadeAberta > 0) {
            $adotar = min($pendenteReserva, $quantidadeAberta);
            $this->contadores['reservas_adotadas']++;

            if ($execute) {
                $this->adotarReservas($entrega, $reservasAbertas, $adotar);
            }

            return;
        }

        if ($this->disponivelParaReserva($consignacao) >= $pendenteReserva) {
            $this->contadores['reservas_criadas']++;

            if ($execute) {
                $entregas->reservarItem(
                    $entrega,
                    (int) $consignacao->deposito_id,
                    $pendenteReserva,
                    null,
                    "Reconciliação de reserva da consignacao #{$consignacao->id}",
                    "consignacao:{$consignacao->id}:reconciliacao-reserva"
                );
            }

            return;
        }

        $this->contadores['sem_saldo_para_reserva']++;

        if ($execute) {
            $entrega->status = ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
            $entrega->em_revisao = false;
            $entrega->bloqueio_motivo = 'Reconciliação: estoque insuficiente para reservar a consignação pendente.';
            $entrega->save();
        }
    }

    private function adotarReservas(ProdutoEntregaItem $entrega, $reservas, int $quantidadeAdotar): void
    {
        $restante = $quantidadeAdotar;

        foreach ($reservas as $reserva) {
            if ($restante <= 0) {
                break;
            }

            $aberta = max(0, (int) $reserva->quantidade - (int) $reserva->quantidade_consumida);
            $quantidade = min($restante, $aberta);
            if ($quantidade <= 0) {
                continue;
            }

            ProdutoEntregaEvento::query()->firstOrCreate(
                ['idempotency_key' => "consignacao:{$entrega->consignacao_id}:adotar-reserva:{$reserva->id}"],
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'tipo_evento' => ProdutoEntregaEvento::RESERVA_CRIADA,
                    'quantidade' => $quantidade,
                    'id_deposito_origem' => $reserva->id_deposito,
                    'estoque_reserva_id' => $reserva->id,
                    'observacao' => 'Reserva existente adotada pela reconciliação de consignação.',
                ]
            );

            $restante -= $quantidade;
        }

        $entrega->quantidade_reservada = min((int) $entrega->quantidade_total, (int) $entrega->quantidade_reservada + $quantidadeAdotar);
        $entrega->status = (int) $entrega->quantidade_reservada >= (int) $entrega->quantidade_total
            ? ProdutoEntregaItem::STATUS_RESERVADO
            : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
        $entrega->bloqueio_motivo = null;
        $entrega->em_revisao = false;
        $entrega->save();
    }

    private function cancelarReservasRemanescentes(Consignacao $consignacao, ?ProdutoEntregaItem $entrega, bool $execute): void
    {
        $reservas = $this->reservasAbertasDaConsignacao($consignacao)->get();
        $aberta = (int) $reservas->sum(fn (EstoqueReserva $reserva) => max(0, (int) $reserva->quantidade - (int) $reserva->quantidade_consumida));
        if ($aberta <= 0) {
            return;
        }

        $this->contadores['reservas_canceladas'] += $reservas->count();

        if (! $execute) {
            return;
        }

        EstoqueReserva::query()
            ->whereIn('id', $reservas->pluck('id'))
            ->update([
                'status' => 'cancelada',
                'motivo' => 'reconciliacao_consignacao_finalizada',
                'updated_at' => now(),
            ]);

        if ($entrega) {
            $entrega->quantidade_reservada = max(0, (int) $entrega->quantidade_reservada - $aberta);
            $entrega->save();
        }
    }

    private function reservasAbertasDaConsignacao(Consignacao $consignacao): Builder
    {
        $query = EstoqueReserva::query()
            ->where('pedido_id', $consignacao->pedido_id)
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where(function ($query) use ($consignacao) {
                $query->where('id_deposito', $consignacao->deposito_id)
                    ->orWhereNull('id_deposito');
            })
            ->where('status', 'ativa')
            ->where(function ($query) {
                $query->whereNull('data_expira')
                    ->orWhere('data_expira', '>', now());
            })
            ->whereRaw('quantidade > quantidade_consumida');

        if ($consignacao->pedido_item_id) {
            $query->where(function ($query) use ($consignacao) {
                $query->where('pedido_item_id', $consignacao->pedido_item_id)
                    ->orWhereNull('pedido_item_id');
            });
        }

        return $query;
    }

    private function quantidadeMovimentada(int $consignacaoId, string $tipo): int
    {
        return (int) EstoqueMovimentacao::query()
            ->where('tipo', $tipo)
            ->where('ref_type', 'consignacao')
            ->where('ref_id', $consignacaoId)
            ->sum('quantidade');
    }

    private function disponivelParaReserva(Consignacao $consignacao): int
    {
        $saldo = (int) DB::table('estoque')
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where('id_deposito', $consignacao->deposito_id)
            ->sum('quantidade');

        $reservado = (int) EstoqueReserva::query()
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where('id_deposito', $consignacao->deposito_id)
            ->where('status', 'ativa')
            ->where(function ($query) {
                $query->whereNull('data_expira')
                    ->orWhere('data_expira', '>', now());
            })
            ->sum(DB::raw('GREATEST(0, quantidade - quantidade_consumida)'));

        return max(0, $saldo - $reservado);
    }

    private function contarDevolucoesOrfas(): int
    {
        return EstoqueMovimentacao::query()
            ->where('tipo', EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value)
            ->where(function ($query) {
                $query->whereNull('ref_type')
                    ->orWhereNull('ref_id')
                    ->orWhere('ref_type', '<>', 'consignacao')
                    ->orWhereNotExists(function ($subquery) {
                        $subquery->selectRaw('1')
                            ->from('consignacoes')
                            ->whereColumn('consignacoes.id', 'estoque_movimentacoes.ref_id')
                            ->where('estoque_movimentacoes.ref_type', 'consignacao');
                    });
            })
            ->count();
    }
}
