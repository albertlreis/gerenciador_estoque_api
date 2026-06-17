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
        {--dry-run : Forca simulacao, mesmo com --execute}';

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
        'devolucoes_orfas' => 0,
    ];

    public function handle(EntregaProdutoService $entregas): int
    {
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');

        $this->info($execute
            ? 'Reconciliação de consignações com persistência.'
            : 'Reconciliação de consignações em dry-run.');

        if ($execute) {
            DB::transaction(fn () => $this->reconciliarConsignacoes($entregas, true));
        } else {
            $this->reconciliarConsignacoes($entregas, false);
        }

        $this->contadores['devolucoes_orfas'] = $this->contarDevolucoesOrfas();

        $this->table(
            ['Metrica', 'Total'],
            collect($this->contadores)->map(fn (int $total, string $nome) => [$nome, $total])->values()->all()
        );

        if ($this->contadores['devolucoes_orfas'] > 0) {
            $this->warn("{$this->contadores['devolucoes_orfas']} movimentacoes de devolucao de consignacao sem vinculo foram apenas reportadas.");
        }

        return self::SUCCESS;
    }

    private function reconciliarConsignacoes(EntregaProdutoService $entregas, bool $execute): void
    {
        Consignacao::query()
            ->with(['entregaItem'])
            ->orderBy('id')
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
