<?php

namespace App\Console\Commands;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Services\EntregaProdutoService;
use App\Services\EstoqueAjusteService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReconciliarTapetesSaldoFantasmaCommand extends Command
{
    private const PEDIDO_REPOSICAO = '10665';

    private const REFERENCIA_ORGANICO = '10.9884-4';

    private const CONFIRMACAO = '10665:10.9884-4';

    private const RECEBIMENTOS = [
        '11.10445-5' => 15,
        '11.10054-4' => 24,
    ];

    protected $signature = 'estoque:reconciliar-tapetes-saldo-fantasma
        {--aplicar : Persiste as correcoes; sem esta opcao o comando apenas simula}
        {--confirmacao= : Confirmacao explicita obrigatoria para aplicar}';

    protected $description = 'Reconcilia os saldos fantasmas dos tapetes do pedido 10665 e da referencia 10.9884-4.';

    public function handle(EntregaProdutoService $entregas, EstoqueAjusteService $ajustes): int
    {
        try {
            $diagnostico = $this->diagnosticar(false);
            $this->exibir($diagnostico);

            if ($diagnostico['ja_corrigido']) {
                $this->info('Os tres saldos ja estao reconciliados; nenhuma alteracao e necessaria.');

                return self::SUCCESS;
            }

            if ($diagnostico['bloqueios']->isNotEmpty()) {
                $this->error('A reconciliacao foi bloqueada. Nenhum dado foi alterado.');

                return self::FAILURE;
            }

            if (! $this->option('aplicar')) {
                $this->info('Diagnostico concluido em dry-run; nenhum registro foi alterado.');
                $this->line('Para aplicar: php artisan estoque:reconciliar-tapetes-saldo-fantasma --aplicar --confirmacao='.self::CONFIRMACAO);

                return self::SUCCESS;
            }

            if (trim((string) $this->option('confirmacao')) !== self::CONFIRMACAO) {
                $this->error('Confirmacao invalida. Informe --confirmacao='.self::CONFIRMACAO.'.');

                return self::FAILURE;
            }

            $resultado = DB::transaction(function () use ($entregas, $ajustes) {
                $diagnosticoAtual = $this->diagnosticar(true);
                if ($diagnosticoAtual['ja_corrigido']) {
                    return ['ja_corrigido' => true];
                }
                if ($diagnosticoAtual['bloqueios']->isNotEmpty()) {
                    throw new RuntimeException($diagnosticoAtual['bloqueios']->implode(' '));
                }

                /** @var Pedido $pedido */
                $pedido = $diagnosticoAtual['pedido'];
                $historicoAntes = $this->snapshotHistoricosRelacionados(
                    $pedido,
                    $this->variacoesAlvo($diagnosticoAtual)
                );
                $loteId = (string) Str::uuid();
                $eventos = [];

                foreach ($diagnosticoAtual['recebimentos'] as $recebimento) {
                    /** @var ProdutoEntregaEvento $eventoOriginal */
                    $eventoOriginal = ProdutoEntregaEvento::query()
                        ->lockForUpdate()
                        ->findOrFail($recebimento['evento_id']);
                    $eventoEstorno = $entregas->estornarEvento(
                        $eventoOriginal,
                        null,
                        "Reconciliacao {$loteId}: quantidade da NF-e em M2 tratada historicamente como unidades."
                    );
                    $item = $entregas->receberItem(
                        $recebimento['entrega_item_id'],
                        $recebimento['deposito_id'],
                        1,
                        null,
                        "Reconciliacao {$loteId}: recebimento correto de uma unidade; a quantidade da NF-e representa area em M2.",
                        "reconciliacao-tapetes:{$recebimento['entrega_item_id']}:recebimento-unitario",
                        ProdutoEntregaEvento::RECEBIDO_ESTOQUE,
                        null,
                        now(),
                        true
                    );
                    $eventoCorreto = $item->eventos()
                        ->where('idempotency_key', "reconciliacao-tapetes:{$recebimento['entrega_item_id']}:recebimento-unitario")
                        ->firstOrFail();
                    $eventos[] = [
                        'referencia' => $recebimento['referencia'],
                        'evento_original_id' => (int) $eventoOriginal->id,
                        'evento_estorno_id' => (int) $eventoEstorno->id,
                        'evento_corrigido_id' => (int) $eventoCorreto->id,
                        'movimentacao_original_id' => (int) $eventoOriginal->estoque_movimentacao_id,
                        'movimentacao_estorno_id' => (int) $eventoEstorno->estoque_movimentacao_id,
                        'movimentacao_corrigida_id' => (int) $eventoCorreto->estoque_movimentacao_id,
                    ];
                }

                $organico = $diagnosticoAtual['organico'];
                $ajuste = $ajustes->ajustarSaldoFinal(
                    $organico['variacao_id'],
                    $organico['deposito_id'],
                    0,
                    null,
                    "Reconciliacao {$loteId}: remocao do credito duplicado gerado no cancelamento apos a devolucao da consignacao.",
                    $loteId,
                    'correcao_saldo_fantasma',
                    $organico['movimentacao_credito_id']
                );

                $historicoDepois = $this->snapshotHistoricosRelacionados(
                    $pedido->fresh(),
                    $this->variacoesAlvo($diagnosticoAtual)
                );
                if ($historicoAntes !== $historicoDepois) {
                    throw new RuntimeException('O historico de status de um pedido relacionado foi alterado durante a reconciliacao.');
                }

                $validacao = $this->diagnosticar(true);
                if (! $validacao['ja_corrigido'] || $validacao['bloqueios']->isNotEmpty()) {
                    throw new RuntimeException('A validacao final da reconciliacao nao atingiu os saldos esperados.');
                }

                logAuditoria('estoque_reconciliacao', 'Saldos fantasmas de tapetes reconciliados.', [
                    'acao' => 'reconciliar_saldos_fantasmas',
                    'nivel' => 'warn',
                    'lote_id' => $loteId,
                    'pedido_id' => (int) $pedido->id,
                    'pedido_numero_externo' => self::PEDIDO_REPOSICAO,
                    'eventos' => $eventos,
                    'reservas_preservadas' => $diagnosticoAtual['recebimentos']
                        ->mapWithKeys(fn (array $recebimento) => [
                            $recebimento['referencia'] => [
                                'quantidade' => $recebimento['reservado_ativo'],
                                'saldo_fisico_final' => $recebimento['saldo_final'],
                                'saldo_disponivel_final' => $recebimento['disponivel_final'],
                            ],
                        ])
                        ->all(),
                    'ajuste_organico' => [
                        'referencia' => self::REFERENCIA_ORGANICO,
                        'variacao_id' => $organico['variacao_id'],
                        'movimentacao_credito_duplicado_id' => $organico['movimentacao_credito_id'],
                        'movimentacao_corretiva_id' => (int) $ajuste['movimentacao']->id,
                        'saldo_anterior' => $ajuste['saldo_anterior'],
                        'saldo_final' => $ajuste['saldo_final'],
                    ],
                    'status_pedidos_alterados' => false,
                ], $pedido);

                return [
                    'ja_corrigido' => false,
                    'lote_id' => $loteId,
                    'eventos' => $eventos,
                    'movimentacao_organico_id' => (int) $ajuste['movimentacao']->id,
                ];
            });
        } catch (Throwable $exception) {
            $this->error('Falha ao reconciliar: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($resultado['ja_corrigido']) {
            $this->info('Os tres saldos ja estao reconciliados; nenhuma alteracao e necessaria.');

            return self::SUCCESS;
        }

        $this->info('Reconciliacao aplicada com sucesso. Lote: '.$resultado['lote_id'].'.');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function diagnosticar(bool $bloquear): array
    {
        $bloqueios = collect();
        $pedidoQuery = Pedido::query()->where('numero_externo', self::PEDIDO_REPOSICAO);
        if ($bloquear) {
            $pedidoQuery->lockForUpdate();
        }
        $pedido = $pedidoQuery->first();
        if (! $pedido) {
            return $this->diagnosticoBloqueado('Pedido de reposicao 10665 nao encontrado.');
        }
        if (! $pedido->isReposicao()) {
            $bloqueios->push('O pedido 10665 nao e mais uma reposicao.');
        }
        $statusAtual = $pedido->statusAtual?->getRawOriginal('status');
        if ($statusAtual !== PedidoStatus::FINALIZADO->value) {
            $bloqueios->push('O pedido 10665 nao esta no estado historico esperado (finalizado).');
        }

        $recebimentos = collect();
        $recebimentosCorrigidos = true;
        foreach (self::RECEBIMENTOS as $referencia => $quantidadeOriginal) {
            $itens = $pedido->itens()
                ->whereHas('variacao', fn ($query) => $query->where('referencia', $referencia))
                ->with(['variacao', 'entregaItem.eventos'])
                ->get();
            if ($itens->count() !== 1 || ! $itens->first()->entregaItem) {
                $bloqueios->push("A referencia {$referencia} nao possui um unico item operacional no pedido 10665.");
                $recebimentosCorrigidos = false;

                continue;
            }

            $pedidoItem = $itens->first();
            $entrega = $pedidoItem->entregaItem;
            $estoqueQuery = Estoque::query()
                ->where('id_variacao', $pedidoItem->id_variacao)
                ->where('id_deposito', $entrega->id_deposito_destino);
            if ($bloquear) {
                $estoqueQuery->lockForUpdate();
            }
            $estoque = $estoqueQuery->first();
            $saldo = (int) ($estoque?->quantidade ?? 0);
            $recebido = (int) $entrega->quantidade_recebida;
            $reservas = $this->diagnosticarReservasAtivas(
                (int) $pedidoItem->id_variacao,
                (int) $entrega->id_deposito_destino,
                1,
                $bloquear,
                $bloqueios,
                $referencia
            );

            if ($saldo === 1 && $recebido === 1 && $this->possuiRecebimentoCorretivo($entrega)) {
                $recebimentos->push([
                    'referencia' => $referencia,
                    'entrega_item_id' => (int) $entrega->id,
                    'variacao_id' => (int) $pedidoItem->id_variacao,
                    'deposito_id' => (int) $entrega->id_deposito_destino,
                    'saldo_atual' => 1,
                    'saldo_final' => 1,
                    'recebido_atual' => 1,
                    'recebido_final' => 1,
                    'reservado_ativo' => $reservas['reservado_ativo'],
                    'disponivel_final' => $reservas['disponivel_final'],
                    'evento_id' => null,
                ]);

                continue;
            }

            $recebimentosCorrigidos = false;
            if ((int) $pedidoItem->quantidade !== 1 || (int) $entrega->quantidade_total !== 1) {
                $bloqueios->push("A referencia {$referencia} nao possui quantidade total unitaria.");
            }
            if ($saldo !== $quantidadeOriginal || $recebido !== $quantidadeOriginal) {
                $bloqueios->push("A referencia {$referencia} mudou: saldo {$saldo}, recebido {$recebido}; esperado {$quantidadeOriginal} antes da correcao.");
            }
            $eventosAtivos = $this->eventosRecebimentoAtivos($entrega);
            if ($eventosAtivos->count() !== 1 || (int) $eventosAtivos->first()?->quantidade !== $quantidadeOriginal) {
                $bloqueios->push("A referencia {$referencia} nao possui um unico evento ativo de recebimento de {$quantidadeOriginal}.");
            }
            $evento = $eventosAtivos->first();
            $movimentacao = $evento?->estoque_movimentacao_id
                ? EstoqueMovimentacao::query()->find($evento->estoque_movimentacao_id)
                : null;
            if (
                ! $movimentacao
                || (int) $movimentacao->quantidade !== $quantidadeOriginal
                || (int) $movimentacao->id_deposito_destino !== (int) $entrega->id_deposito_destino
            ) {
                $bloqueios->push("A movimentacao de recebimento da referencia {$referencia} nao corresponde ao saldo esperado.");
            }

            $recebimentos->push([
                'referencia' => $referencia,
                'entrega_item_id' => (int) $entrega->id,
                'variacao_id' => (int) $pedidoItem->id_variacao,
                'deposito_id' => (int) $entrega->id_deposito_destino,
                'saldo_atual' => $saldo,
                'saldo_final' => 1,
                'recebido_atual' => $recebido,
                'recebido_final' => 1,
                'reservado_ativo' => $reservas['reservado_ativo'],
                'disponivel_final' => $reservas['disponivel_final'],
                'evento_id' => $evento?->id ? (int) $evento->id : null,
            ]);
        }

        $organico = $this->diagnosticarOrganico($bloquear, $bloqueios);
        $organicoCorrigido = ($organico['saldo_atual'] ?? null) === 0
            && (bool) ($organico['possui_correcao'] ?? false);
        $recebimentosIniciais = $recebimentos->count() === count(self::RECEBIMENTOS)
            && $recebimentos->every(fn (array $item) => $item['evento_id'] !== null);
        $organicoInicial = ($organico['saldo_atual'] ?? null) === 1
            && ! (bool) ($organico['possui_correcao'] ?? false);
        if (! ($recebimentosCorrigidos && $organicoCorrigido) && ! ($recebimentosIniciais && $organicoInicial)) {
            $bloqueios->push('O conjunto esta parcialmente corrigido ou fora do estado inicial esperado; revisao manual obrigatoria.');
        }

        return [
            'pedido' => $pedido,
            'recebimentos' => $recebimentos,
            'organico' => $organico,
            'bloqueios' => $bloqueios,
            'ja_corrigido' => $recebimentosCorrigidos && $organicoCorrigido && $bloqueios->isEmpty(),
        ];
    }

    /** @return array<string,mixed> */
    private function diagnosticarOrganico(bool $bloquear, Collection $bloqueios): array
    {
        $variacoes = DB::table('produto_variacoes')
            ->where('referencia', self::REFERENCIA_ORGANICO)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $candidatos = collect();

        foreach ($variacoes as $variacaoId) {
            $creditos = EstoqueMovimentacao::query()
                ->where('id_variacao', $variacaoId)
                ->where('tipo', EstoqueMovimentacaoTipo::ESTORNO->value)
                ->where('ref_type', 'estorno')
                ->whereNotNull('id_deposito_destino')
                ->get();
            foreach ($creditos as $credito) {
                $original = EstoqueMovimentacao::query()->find($credito->ref_id);
                if (! $original || $original->tipo !== EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value) {
                    continue;
                }
                $devolvida = DB::table('consignacao_devolucoes')
                    ->where('consignacao_id', $original->ref_id)
                    ->whereNull('cancelada_em')
                    ->where('quantidade', '>', 0)
                    ->exists();
                if ($devolvida) {
                    $candidatos->push(compact('variacaoId', 'credito', 'original'));
                }
            }
        }

        if ($candidatos->count() !== 1) {
            $bloqueios->push('Nao foi encontrado um unico credito duplicado de consignacao para a referencia 10.9884-4.');

            return [];
        }

        $candidato = $candidatos->first();
        $credito = $candidato['credito'];
        $estoqueQuery = Estoque::query()
            ->where('id_variacao', $candidato['variacaoId'])
            ->where('id_deposito', $credito->id_deposito_destino);
        if ($bloquear) {
            $estoqueQuery->lockForUpdate();
        }
        $estoque = $estoqueQuery->first();
        $saldo = (int) ($estoque?->quantidade ?? 0);
        $possuiCorrecao = EstoqueMovimentacao::query()
            ->where('ref_type', 'correcao_saldo_fantasma')
            ->where('ref_id', $credito->id)
            ->exists();

        if (! $possuiCorrecao) {
            if ($saldo !== 1) {
                $bloqueios->push("A referencia 10.9884-4 mudou: saldo {$saldo}; esperado 1 antes da correcao.");
            }
        } elseif ($saldo !== 0) {
            $bloqueios->push('A referencia 10.9884-4 possui movimento corretivo, mas o saldo nao esta zerado.');
        }
        $this->diagnosticarReservasAtivas(
            (int) $candidato['variacaoId'],
            (int) $credito->id_deposito_destino,
            0,
            $bloquear,
            $bloqueios,
            self::REFERENCIA_ORGANICO
        );

        return [
            'referencia' => self::REFERENCIA_ORGANICO,
            'variacao_id' => (int) $candidato['variacaoId'],
            'deposito_id' => (int) $credito->id_deposito_destino,
            'saldo_atual' => $saldo,
            'saldo_final' => 0,
            'movimentacao_credito_id' => (int) $credito->id,
            'possui_correcao' => $possuiCorrecao,
        ];
    }

    /** @return array{reservado_ativo:int,disponivel_final:int} */
    private function diagnosticarReservasAtivas(
        int $variacaoId,
        int $depositoId,
        int $saldoFinal,
        bool $bloquear,
        Collection $bloqueios,
        string $referencia
    ): array {
        $query = EstoqueReserva::query()
            ->where('id_variacao', $variacaoId)
            ->where('status', 'ativa')
            ->whereColumn('quantidade_consumida', '<', 'quantidade')
            ->where(function ($query) {
                $query->whereNull('data_expira')->orWhere('data_expira', '>', now());
            });
        if ($bloquear) {
            $query->lockForUpdate();
        }

        $reservas = $query->get([
            'id',
            'id_deposito',
            'pedido_id',
            'quantidade',
            'quantidade_consumida',
        ]);
        if ($reservas->contains(fn (EstoqueReserva $reserva) => $reserva->id_deposito === null)) {
            $bloqueios->push("A referencia {$referencia} possui reserva ativa sem deposito definido.");
        }

        $reservadoNoDeposito = (int) $reservas
            ->where('id_deposito', $depositoId)
            ->sum(fn (EstoqueReserva $reserva) => max(
                0,
                (int) $reserva->quantidade - (int) $reserva->quantidade_consumida
            ));
        if ($reservadoNoDeposito > $saldoFinal) {
            $bloqueios->push(
                "A referencia {$referencia} ficaria com saldo {$saldoFinal}, abaixo das {$reservadoNoDeposito} unidades reservadas no deposito {$depositoId}."
            );
        }

        return [
            'reservado_ativo' => $reservadoNoDeposito,
            'disponivel_final' => max(0, $saldoFinal - $reservadoNoDeposito),
        ];
    }

    private function eventosRecebimentoAtivos(ProdutoEntregaItem $item): Collection
    {
        $eventos = $item->eventos()->orderBy('id')->get();
        $estornados = $eventos
            ->where('tipo_evento', ProdutoEntregaEvento::ESTORNADO)
            ->map(fn (ProdutoEntregaEvento $evento) => (int) data_get($evento->metadata_json, 'evento_original_id', 0))
            ->filter()
            ->flip();

        return $eventos
            ->where('tipo_evento', ProdutoEntregaEvento::RECEBIDO_ESTOQUE)
            ->reject(fn (ProdutoEntregaEvento $evento) => $estornados->has((int) $evento->id))
            ->values();
    }

    private function possuiRecebimentoCorretivo(ProdutoEntregaItem $item): bool
    {
        return $this->eventosRecebimentoAtivos($item)->contains(
            fn (ProdutoEntregaEvento $evento) => str_starts_with((string) $evento->idempotency_key, 'reconciliacao-tapetes:')
                && (int) $evento->quantidade === 1
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshotHistoricosRelacionados(Pedido $pedido, Collection $variacaoIds): array
    {
        $pedidoIds = EstoqueMovimentacao::query()
            ->whereIn('id_variacao', $variacaoIds)
            ->whereNotNull('pedido_id')
            ->pluck('pedido_id')
            ->merge(
                EstoqueReserva::query()
                    ->whereIn('id_variacao', $variacaoIds)
                    ->whereNotNull('pedido_id')
                    ->pluck('pedido_id')
            )
            ->merge(
                DB::table('pedido_itens')
                    ->whereIn('id_variacao', $variacaoIds)
                    ->pluck('id_pedido')
            )
            ->push($pedido->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return DB::table('pedido_status_historico')
            ->whereIn('pedido_id', $pedidoIds)
            ->orderBy('id')
            ->get(['id', 'status', 'data_status', 'usuario_id', 'observacoes'])
            ->map(fn ($status) => [
                'id' => (int) $status->id,
                'status' => (string) $status->status,
                'data_status' => $status->data_status ? (string) $status->data_status : null,
                'usuario_id' => $status->usuario_id ? (int) $status->usuario_id : null,
                'observacoes' => $status->observacoes,
            ])
            ->all();
    }

    /** @param array<string,mixed> $diagnostico */
    private function variacoesAlvo(array $diagnostico): Collection
    {
        return $diagnostico['recebimentos']
            ->pluck('variacao_id')
            ->push($diagnostico['organico']['variacao_id'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** @return array<string,mixed> */
    private function diagnosticoBloqueado(string $mensagem): array
    {
        return [
            'pedido' => null,
            'recebimentos' => collect(),
            'organico' => [],
            'bloqueios' => collect([$mensagem]),
            'ja_corrigido' => false,
        ];
    }

    /** @param array<string,mixed> $diagnostico */
    private function exibir(array $diagnostico): void
    {
        $linhas = $diagnostico['recebimentos']->map(fn (array $item) => [
            $item['referencia'],
            $item['variacao_id'],
            $item['saldo_atual'],
            $item['saldo_final'],
            $item['recebido_atual'].'/1',
            $item['recebido_final'].'/1',
            $item['reservado_ativo'],
            $item['disponivel_final'],
        ]);
        if ($diagnostico['organico'] !== []) {
            $organico = $diagnostico['organico'];
            $linhas->push([
                $organico['referencia'],
                $organico['variacao_id'],
                $organico['saldo_atual'],
                $organico['saldo_final'],
                '-',
                '-',
                0,
                $organico['saldo_final'],
            ]);
        }

        $this->table(
            ['Referencia', 'Variacao', 'Saldo atual', 'Saldo final', 'Recebido atual', 'Recebido final', 'Reservado', 'Disponivel final'],
            $linhas->all()
        );
        foreach ($diagnostico['bloqueios'] as $bloqueio) {
            $this->warn($bloqueio);
        }
    }
}
