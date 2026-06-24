<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\LancamentoFinanceiro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FinanceiroRelatorioService
{
    private const TIPOS = [
        'fluxo-caixa-diario' => 'Fluxo de caixa diário',
        'fluxo-caixa-mensal' => 'Fluxo de caixa mensal',
        'dre-gerencial' => 'DRE gerencial',
        'posicao-contas' => 'Posição de contas',
        'analise-pagamentos' => 'Análise de pagamentos',
        'analise-recebimentos' => 'Análise de recebimentos',
        'lancamentos-caixa' => 'Lançamentos no caixa',
    ];

    public function gerar(string $tipo, array $filtros): array
    {
        $this->validarTipo($tipo);
        $f = $this->normalizarFiltros($filtros);

        $dados = match ($tipo) {
            'fluxo-caixa-diario' => $this->fluxoCaixa($f, 'diario'),
            'fluxo-caixa-mensal' => $this->fluxoCaixa($f, 'mensal'),
            'dre-gerencial' => $this->dreGerencial($f),
            'posicao-contas' => $this->posicaoContas($f),
            'analise-pagamentos' => $this->analisePagamentos($f),
            'analise-recebimentos' => $this->analiseRecebimentos($f),
            'lancamentos-caixa' => $this->lancamentosCaixa($f),
        };

        return [
            'tipo' => $tipo,
            'titulo' => self::TIPOS[$tipo],
            'periodo' => [
                'inicio' => $f['inicio']->toDateString(),
                'fim' => $f['fim']->toDateString(),
                'inicio_label' => $f['inicio']->format('d/m/Y'),
                'fim_label' => $f['fim']->format('d/m/Y'),
            ],
            'formato' => $f['formato'],
            'gerado_em' => now()->format('Y-m-d H:i:s'),
            ...$dados,
        ];
    }

    public function tipos(): array
    {
        return self::TIPOS;
    }

    private function fluxoCaixa(array $f, string $granularidade): array
    {
        $buckets = $this->buckets($f['inicio'], $f['fim'], $granularidade);

        $realizados = $this->lancamentosConfirmadosPeriodo($f)
            ->get()
            ->groupBy(fn (LancamentoFinanceiro $l) => $this->bucketKey($l->data_movimento, $granularidade));

        $receberPrevisto = $this->contasReceberAbertasPeriodo($f)
            ->get()
            ->groupBy(fn (ContaReceber $c) => $this->bucketKey($c->data_vencimento, $granularidade));

        $pagarPrevisto = $this->contasPagarAbertasPeriodo($f)
            ->get()
            ->groupBy(fn (ContaPagar $c) => $this->bucketKey($c->data_vencimento, $granularidade));

        $saldo = $this->saldoInicialConsolidado($f);
        $linhasCronologicas = [];

        foreach ($buckets as $key => $bucket) {
            $movimentos = $realizados->get($key, collect());
            $prevReceber = $receberPrevisto->get($key, collect());
            $prevPagar = $pagarPrevisto->get($key, collect());

            $entradasRealizadas = $movimentos
                ->map(fn (LancamentoFinanceiro $l) => $this->signedValue($l))
                ->filter(fn (float $v) => $v > 0)
                ->sum();
            $saidasRealizadas = abs($movimentos
                ->map(fn (LancamentoFinanceiro $l) => $this->signedValue($l))
                ->filter(fn (float $v) => $v < 0)
                ->sum());
            $entradasPrevistas = $prevReceber->sum(fn (ContaReceber $c) => (float) $c->saldo_aberto);
            $saidasPrevistas = $prevPagar->sum(fn (ContaPagar $c) => (float) $c->saldo_aberto);
            $saldoInicial = $saldo;
            $saldoPeriodo = $entradasRealizadas - $saidasRealizadas;
            $saldo += $saldoPeriodo;
            $saldoPrevisto = $entradasPrevistas - $saidasPrevistas;

            $linhasCronologicas[] = [
                'periodo' => $bucket['label'],
                'data_inicio' => $bucket['inicio']->toDateString(),
                'data_fim' => $bucket['fim']->toDateString(),
                'saldo_inicial' => $this->money($saldoInicial),
                'entradas_realizadas' => $this->money($entradasRealizadas),
                'saidas_realizadas' => $this->money($saidasRealizadas),
                'saldo_periodo' => $this->money($saldoPeriodo),
                'saldo_final' => $this->money($saldo),
                'entradas_previstas' => $this->money($entradasPrevistas),
                'saidas_previstas' => $this->money($saidasPrevistas),
                'saldo_previsto' => $this->money($saldoPrevisto),
                'saldo_final_previsto' => $this->money($saldo + $saldoPrevisto),
            ];
        }

        $totais = collect($linhasCronologicas);
        $linhas = array_reverse($linhasCronologicas);

        return [
            'kpis' => [
                'saldo_inicial' => $this->money($linhasCronologicas[0]['saldo_inicial'] ?? 0),
                'entradas_realizadas' => $this->money($totais->sum('entradas_realizadas')),
                'saidas_realizadas' => $this->money($totais->sum('saidas_realizadas')),
                'entradas_previstas' => $this->money($totais->sum('entradas_previstas')),
                'saidas_previstas' => $this->money($totais->sum('saidas_previstas')),
                'saldo_final' => $this->money($saldo),
            ],
            'resumo' => [
                'linhas' => count($linhas),
                'base' => 'realizado_previsto',
            ],
            'colunas' => $this->colunasFluxo(),
            'linhas' => $linhas,
            'grupos' => [],
        ];
    }

    private function dreGerencial(array $f): array
    {
        $linhasBase = LancamentoFinanceiro::query()
            ->with(['categoria.pai', 'centroCusto'])
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->whereIn('tipo', [LancamentoTipo::RECEITA->value, LancamentoTipo::DESPESA->value])
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']))
            ->where(function ($q) use ($f) {
                $q->whereBetween('competencia', [$f['inicio']->toDateString(), $f['fim']->toDateString()])
                    ->orWhere(function ($w) use ($f) {
                        $w->whereNull('competencia')
                            ->whereBetween('data_movimento', [$f['inicio'], $f['fim']]);
                    });
            })
            ->get()
            ->filter(function (LancamentoFinanceiro $l) use ($f) {
                $base = $l->competencia ?: $l->data_movimento;
                if (!$base) {
                    return false;
                }

                $data = Carbon::parse($base)->startOfDay();
                return $data->betweenIncluded($f['inicio']->copy()->startOfDay(), $f['fim']->copy()->endOfDay());
            });

        $porCategoria = $linhasBase
            ->groupBy(function (LancamentoFinanceiro $l) {
                $tipo = $this->tipoValue($l);
                $categoria = $l->categoria?->nome ?: ($tipo === LancamentoTipo::RECEITA->value ? 'Receitas operacionais' : 'Despesas operacionais');
                return "{$tipo}|{$categoria}";
            })
            ->map(function (Collection $items, string $key) {
                [$tipo, $categoria] = explode('|', $key, 2);
                $valor = $items->sum(fn (LancamentoFinanceiro $l) => (float) $l->valor);

                return [
                    'grupo' => $tipo === LancamentoTipo::RECEITA->value ? 'Receitas' : 'Despesas',
                    'categoria' => $categoria,
                    'centro_custo' => '-',
                    'receitas' => $this->money($tipo === LancamentoTipo::RECEITA->value ? $valor : 0),
                    'despesas' => $this->money($tipo === LancamentoTipo::DESPESA->value ? $valor : 0),
                    'resultado' => $this->money($tipo === LancamentoTipo::RECEITA->value ? $valor : -$valor),
                ];
            })
            ->sortBy([['grupo', 'desc'], ['categoria', 'asc']])
            ->values();

        $receitas = $linhasBase
            ->filter(fn (LancamentoFinanceiro $l) => $this->tipoValue($l) === LancamentoTipo::RECEITA->value)
            ->sum(fn (LancamentoFinanceiro $l) => (float) $l->valor);
        $despesas = $linhasBase
            ->filter(fn (LancamentoFinanceiro $l) => $this->tipoValue($l) === LancamentoTipo::DESPESA->value)
            ->sum(fn (LancamentoFinanceiro $l) => (float) $l->valor);

        $linhas = $porCategoria->push([
            'grupo' => 'Resultado',
            'categoria' => 'Resultado operacional',
            'centro_custo' => '-',
            'receitas' => $this->money($receitas),
            'despesas' => $this->money($despesas),
            'resultado' => $this->money($receitas - $despesas),
            'subtotal' => true,
        ])->values()->all();

        return [
            'kpis' => [
                'receitas' => $this->money($receitas),
                'despesas' => $this->money($despesas),
                'resultado' => $this->money($receitas - $despesas),
                'margem_percentual' => $receitas > 0 ? round((($receitas - $despesas) / $receitas) * 100, 2) : 0,
            ],
            'resumo' => [
                'regime' => 'competência',
                'linhas' => count($linhas),
            ],
            'colunas' => [
                ['field' => 'grupo', 'header' => 'Grupo'],
                ['field' => 'categoria', 'header' => 'Categoria'],
                ['field' => 'receitas', 'header' => 'Receitas'],
                ['field' => 'despesas', 'header' => 'Despesas'],
                ['field' => 'resultado', 'header' => 'Resultado'],
            ],
            'linhas' => $linhas,
            'grupos' => [],
        ];
    }

    private function posicaoContas(array $f): array
    {
        $tipoPessoa = $f['tipo_pessoa'] ?: 'ambos';
        $linhas = collect();

        if (in_array($tipoPessoa, ['ambos', 'pagar'], true)) {
            $linhas = $linhas->merge($this->posicaoContasPagar($f));
        }

        if (in_array($tipoPessoa, ['ambos', 'receber'], true)) {
            $linhas = $linhas->merge($this->posicaoContasReceber($f));
        }

        $hoje = now()->startOfDay();
        $normalizadas = $linhas->map(function (array $linha) use ($hoje) {
            $aberto = (float) $linha['saldo_aberto'];
            $vencido = (float) $linha['saldo_vencido'];
            $aVencer = max(0, $aberto - $vencido);

            return [
                ...$linha,
                'saldo_a_vencer' => $this->money($aVencer),
            ];
        })->sortBy([['tipo', 'asc'], ['pessoa', 'asc']])->values();

        return [
            'kpis' => [
                'emitido' => $this->money($normalizadas->sum('emitido')),
                'pago_recebido' => $this->money($normalizadas->sum('pago_recebido')),
                'saldo_aberto' => $this->money($normalizadas->sum('saldo_aberto')),
                'saldo_vencido' => $this->money($normalizadas->sum('saldo_vencido')),
                'saldo_a_vencer' => $this->money($normalizadas->sum('saldo_a_vencer')),
            ],
            'resumo' => [
                'tipo_pessoa' => $tipoPessoa,
                'linhas' => $normalizadas->count(),
            ],
            'colunas' => [
                ['field' => 'tipo', 'header' => 'Tipo'],
                ['field' => 'pessoa', 'header' => 'Cliente/Fornecedor'],
                ['field' => 'quantidade_titulos', 'header' => 'Títulos'],
                ['field' => 'emitido', 'header' => 'Emitido'],
                ['field' => 'pago_recebido', 'header' => 'Pago/Recebido'],
                ['field' => 'saldo_aberto', 'header' => 'Em aberto'],
                ['field' => 'saldo_vencido', 'header' => 'Vencido'],
                ['field' => 'saldo_a_vencer', 'header' => 'A vencer'],
            ],
            'linhas' => $normalizadas->all(),
            'grupos' => [],
        ];
    }

    private function analisePagamentos(array $f): array
    {
        $pagamentos = ContaPagarPagamento::query()
            ->with(['conta.fornecedor', 'conta.categoria', 'conta.centroCusto'])
            ->whereBetween('data_pagamento', [$f['inicio']->toDateString(), $f['fim']->toDateString()])
            ->when($f['conta_ids'], fn ($q) => $q->whereIn('conta_financeira_id', $f['conta_ids']))
            ->when($f['categoria_id'], fn ($q) => $q->whereHas('conta', fn ($w) => $w->where('categoria_id', $f['categoria_id'])))
            ->when($f['centro_custo_id'], fn ($q) => $q->whereHas('conta', fn ($w) => $w->where('centro_custo_id', $f['centro_custo_id'])))
            ->when($f['pessoa_id'], fn ($q) => $q->whereHas('conta', fn ($w) => $w->where('fornecedor_id', $f['pessoa_id'])))
            ->get();

        return $this->analisePagamentosOuRecebimentos(
            pagamentos: $pagamentos,
            tituloTotal: 'total_pago',
            pessoaCallback: fn (ContaPagarPagamento $p) => $p->conta?->fornecedor?->nome ?: 'Sem fornecedor',
            categoriaCallback: fn (ContaPagarPagamento $p) => $p->conta?->categoria?->nome ?: 'Sem categoria'
        );
    }

    private function analiseRecebimentos(array $f): array
    {
        $pagamentos = ContaReceberPagamento::query()
            ->with(['conta.cliente', 'conta.pedido.cliente', 'conta.categoria', 'conta.centroCusto'])
            ->whereBetween('data_pagamento', [$f['inicio']->toDateString(), $f['fim']->toDateString()])
            ->when($f['conta_ids'], fn ($q) => $q->whereIn('conta_financeira_id', $f['conta_ids']))
            ->when($f['categoria_id'], fn ($q) => $q->whereHas('conta', fn ($w) => $w->where('categoria_id', $f['categoria_id'])))
            ->when($f['centro_custo_id'], fn ($q) => $q->whereHas('conta', fn ($w) => $w->where('centro_custo_id', $f['centro_custo_id'])))
            ->when($f['pessoa_id'], function ($q) use ($f) {
                $q->whereHas('conta', fn ($w) => $w
                    ->where(function ($cliente) use ($f) {
                        $cliente->where('cliente_id', $f['pessoa_id'])
                            ->orWhereHas('pedido', fn ($p) => $p->where('id_cliente', $f['pessoa_id']));
                    }));
            })
            ->get();

        return $this->analisePagamentosOuRecebimentos(
            pagamentos: $pagamentos,
            tituloTotal: 'total_recebido',
            pessoaCallback: fn (ContaReceberPagamento $p) => $p->conta?->cliente?->nome
                ?: $p->conta?->pedido?->cliente?->nome
                ?: 'Sem cliente',
            categoriaCallback: fn (ContaReceberPagamento $p) => $p->conta?->categoria?->nome ?: 'Sem categoria'
        );
    }

    private function lancamentosCaixa(array $f): array
    {
        $status = $f['status'] ?: LancamentoStatus::CONFIRMADO->value;
        $lancamentos = LancamentoFinanceiro::query()
            ->with(['conta', 'categoria', 'centroCusto'])
            ->whereBetween('data_movimento', [$f['inicio'], $f['fim']])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($f['conta_ids'], fn ($q) => $q->whereIn('conta_id', $f['conta_ids']))
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']))
            ->orderByDesc('data_movimento')
            ->orderByDesc('id')
            ->get();

        $linhas = $lancamentos->map(function (LancamentoFinanceiro $l) {
            $valor = $this->signedValue($l);

            return [
                'data' => optional($l->data_movimento)->format('d/m/Y'),
                'descricao' => $l->descricao,
                'conta' => $l->conta?->nome ?: '-',
                'categoria' => $l->categoria?->nome ?: '-',
                'centro_custo' => $l->centroCusto?->nome ?: '-',
                'tipo' => $this->tipoValue($l),
                'status' => $l->status?->value ?? $l->status,
                'valor' => $this->money($valor),
            ];
        })->values();

        $entradas = $linhas->sum(fn (array $l) => max(0, (float) $l['valor']));
        $saidas = abs($linhas->sum(fn (array $l) => min(0, (float) $l['valor'])));

        return [
            'kpis' => [
                'entradas' => $this->money($entradas),
                'saidas' => $this->money($saidas),
                'saldo' => $this->money($entradas - $saidas),
                'quantidade' => $linhas->count(),
            ],
            'resumo' => [
                'status' => $status,
                'linhas' => $linhas->count(),
            ],
            'colunas' => [
                ['field' => 'data', 'header' => 'Data'],
                ['field' => 'descricao', 'header' => 'Descrição'],
                ['field' => 'conta', 'header' => 'Conta'],
                ['field' => 'categoria', 'header' => 'Categoria'],
                ['field' => 'tipo', 'header' => 'Tipo'],
                ['field' => 'status', 'header' => 'Status'],
                ['field' => 'valor', 'header' => 'Valor'],
            ],
            'linhas' => $linhas->all(),
            'grupos' => [],
        ];
    }

    private function analisePagamentosOuRecebimentos(Collection $pagamentos, string $tituloTotal, callable $pessoaCallback, callable $categoriaCallback): array
    {
        $linhas = $pagamentos
            ->groupBy(fn ($p) => ($p->forma_pagamento ?: 'Sem forma') . '|' . $categoriaCallback($p))
            ->map(function (Collection $items, string $key) {
                [$forma, $categoria] = explode('|', $key, 2);

                return [
                    'forma' => $forma,
                    'categoria' => $categoria,
                    'quantidade' => $items->count(),
                    'valor' => $this->money($items->sum(fn ($p) => (float) $p->valor)),
                ];
            })
            ->sortByDesc('valor')
            ->values();

        $porForma = $pagamentos
            ->groupBy(fn ($p) => $p->forma_pagamento ?: 'Sem forma')
            ->map(fn (Collection $items, string $forma) => [
                'forma' => $forma,
                'valor' => $this->money($items->sum(fn ($p) => (float) $p->valor)),
                'quantidade' => $items->count(),
            ])
            ->sortByDesc('valor')
            ->values();

        $ranking = $pagamentos
            ->groupBy(fn ($p) => $pessoaCallback($p))
            ->map(fn (Collection $items, string $pessoa) => [
                'pessoa' => $pessoa,
                'valor' => $this->money($items->sum(fn ($p) => (float) $p->valor)),
                'quantidade' => $items->count(),
            ])
            ->sortByDesc('valor')
            ->take(10)
            ->values();

        $total = $pagamentos->sum(fn ($p) => (float) $p->valor);

        return [
            'kpis' => [
                $tituloTotal => $this->money($total),
                'quantidade' => $pagamentos->count(),
                'ticket_medio' => $pagamentos->count() > 0 ? $this->money($total / $pagamentos->count()) : 0.0,
            ],
            'resumo' => [
                'linhas' => $linhas->count(),
            ],
            'colunas' => [
                ['field' => 'forma', 'header' => 'Forma'],
                ['field' => 'categoria', 'header' => 'Categoria'],
                ['field' => 'quantidade', 'header' => 'Qtd.'],
                ['field' => 'valor', 'header' => 'Valor'],
            ],
            'linhas' => $linhas->all(),
            'grupos' => [
                'por_forma' => $porForma->all(),
                'ranking_pessoas' => $ranking->all(),
            ],
        ];
    }

    private function posicaoContasPagar(array $f): Collection
    {
        return ContaPagar::query()
            ->with(['fornecedor', 'pagamentos'])
            ->whereDate('data_vencimento', '>=', $f['inicio']->toDateString())
            ->whereDate('data_vencimento', '<=', $f['fim']->toDateString())
            ->where('status', '!=', ContaStatus::CANCELADA->value)
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']))
            ->when($f['pessoa_id'], fn ($q) => $q->where('fornecedor_id', $f['pessoa_id']))
            ->get()
            ->groupBy(fn (ContaPagar $c) => $c->fornecedor?->nome ?: 'Sem fornecedor')
            ->map(fn (Collection $items, string $pessoa) => $this->posicaoPessoaLinha('Pagar', $pessoa, $items))
            ->values();
    }

    private function posicaoContasReceber(array $f): Collection
    {
        return ContaReceber::query()
            ->with(['cliente', 'pedido.cliente', 'pagamentos'])
            ->whereDate('data_vencimento', '>=', $f['inicio']->toDateString())
            ->whereDate('data_vencimento', '<=', $f['fim']->toDateString())
            ->where('status', '!=', ContaStatus::CANCELADA->value)
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']))
            ->when($f['pessoa_id'], function ($q) use ($f) {
                $q->where(function ($cliente) use ($f) {
                    $cliente->where('cliente_id', $f['pessoa_id'])
                        ->orWhereHas('pedido', fn ($p) => $p->where('id_cliente', $f['pessoa_id']));
                });
            })
            ->get()
            ->groupBy(fn (ContaReceber $c) => $c->cliente?->nome ?: $c->pedido?->cliente?->nome ?: 'Sem cliente')
            ->map(fn (Collection $items, string $pessoa) => $this->posicaoPessoaLinha('Receber', $pessoa, $items))
            ->values();
    }

    private function posicaoPessoaLinha(string $tipo, string $pessoa, Collection $items): array
    {
        $emitido = $items->sum(fn ($c) => (float) $c->valor_liquido);
        $pagoRecebido = $items->sum(fn ($c) => (float) ($tipo === 'Pagar' ? $c->valor_pago : $c->valor_recebido));
        $aberto = $items->sum(fn ($c) => (float) $c->saldo_aberto);
        $vencido = $items
            ->filter(fn ($c) => $this->isTituloAberto($c) && $c->data_vencimento && $c->data_vencimento->lt(now()->startOfDay()))
            ->sum(fn ($c) => (float) $c->saldo_aberto);

        return [
            'tipo' => $tipo,
            'pessoa' => $pessoa,
            'quantidade_titulos' => $items->count(),
            'emitido' => $this->money($emitido),
            'pago_recebido' => $this->money($pagoRecebido),
            'saldo_aberto' => $this->money($aberto),
            'saldo_vencido' => $this->money($vencido),
        ];
    }

    private function lancamentosConfirmadosPeriodo(array $f)
    {
        return LancamentoFinanceiro::query()
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->whereBetween('data_movimento', [$f['inicio'], $f['fim']])
            ->when($f['conta_ids'], fn ($q) => $q->whereIn('conta_id', $f['conta_ids']))
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']));
    }

    private function contasReceberAbertasPeriodo(array $f)
    {
        return ContaReceber::query()
            ->whereDate('data_vencimento', '>=', $f['inicio']->toDateString())
            ->whereDate('data_vencimento', '<=', $f['fim']->toDateString())
            ->whereIn('status', [ContaStatus::ABERTA->value, ContaStatus::PARCIAL->value])
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']));
    }

    private function contasPagarAbertasPeriodo(array $f)
    {
        return ContaPagar::query()
            ->whereDate('data_vencimento', '>=', $f['inicio']->toDateString())
            ->whereDate('data_vencimento', '<=', $f['fim']->toDateString())
            ->whereIn('status', [ContaStatus::ABERTA->value, ContaStatus::PARCIAL->value])
            ->when($f['categoria_id'], fn ($q) => $q->where('categoria_id', $f['categoria_id']))
            ->when($f['centro_custo_id'], fn ($q) => $q->where('centro_custo_id', $f['centro_custo_id']));
    }

    private function saldoInicialConsolidado(array $f): float
    {
        $contas = ContaFinanceira::query()
            ->when($f['conta_ids'], fn ($q) => $q->whereIn('id', $f['conta_ids']))
            ->get();

        return $contas->sum(fn (ContaFinanceira $conta) => $this->saldoContaNoInicioPeriodo($conta, $f['inicio']));
    }

    private function saldoContaNoInicioPeriodo(ContaFinanceira $conta, Carbon $inicio): float
    {
        $inicio = $inicio->copy()->startOfDay();

        if ($conta->saldo_atual !== null && $conta->saldo_atual_em !== null) {
            $saldoAtualEm = Carbon::parse($conta->saldo_atual_em);
            $saldoAtual = (float) $conta->saldo_atual;

            if ($saldoAtualEm->gt($inicio)) {
                return $saldoAtual - $this->movimentosConfirmadosAssinados($conta, $inicio, $saldoAtualEm, true, true);
            }

            if ($saldoAtualEm->lt($inicio)) {
                return $saldoAtual + $this->movimentosConfirmadosAssinados($conta, $saldoAtualEm, $inicio, false, false);
            }

            return $saldoAtual;
        }

        $dataSaldoInicial = $conta->data_saldo_inicial
            ? Carbon::parse($conta->data_saldo_inicial)->startOfDay()
            : Carbon::parse('1900-01-01')->startOfDay();

        $movimentos = $this->movimentosConfirmadosAssinados($conta, $dataSaldoInicial, $inicio, true, false);

        return (float) $conta->saldo_inicial + $movimentos;
    }

    private function movimentosConfirmadosAssinados(
        ContaFinanceira $conta,
        Carbon $inicio,
        Carbon $fim,
        bool $incluirInicio,
        bool $incluirFim
    ): float {
        if ($inicio->gt($fim)) {
            return 0.0;
        }

        if ($inicio->eq($fim) && (!$incluirInicio || !$incluirFim)) {
            return 0.0;
        }

        return (float) LancamentoFinanceiro::query()
            ->where('conta_id', $conta->id)
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->where('data_movimento', $incluirInicio ? '>=' : '>', $inicio)
            ->where('data_movimento', $incluirFim ? '<=' : '<', $fim)
            ->get()
            ->sum(fn (LancamentoFinanceiro $l) => $this->signedValue($l));
    }

    private function colunasFluxo(): array
    {
        return [
            ['field' => 'periodo', 'header' => 'Período'],
            ['field' => 'saldo_inicial', 'header' => 'Saldo inicial'],
            ['field' => 'entradas_realizadas', 'header' => 'Entradas realizadas'],
            ['field' => 'saidas_realizadas', 'header' => 'Saídas realizadas'],
            ['field' => 'saldo_periodo', 'header' => 'Saldo período'],
            ['field' => 'saldo_final', 'header' => 'Saldo final'],
            ['field' => 'entradas_previstas', 'header' => 'Entradas previstas'],
            ['field' => 'saidas_previstas', 'header' => 'Saídas previstas'],
            ['field' => 'saldo_final_previsto', 'header' => 'Saldo final previsto'],
        ];
    }

    private function buckets(Carbon $inicio, Carbon $fim, string $granularidade): array
    {
        $out = [];
        $cursor = $granularidade === 'mensal' ? $inicio->copy()->startOfMonth() : $inicio->copy()->startOfDay();
        $limite = $fim->copy();

        while ($cursor->lte($limite)) {
            if ($granularidade === 'mensal') {
                $bucketInicio = $cursor->copy()->startOfMonth();
                $bucketFim = $cursor->copy()->endOfMonth()->min($fim);
                $key = $cursor->format('Y-m');
                $label = $cursor->format('m/Y');
                $cursor->addMonthNoOverflow();
            } else {
                $bucketInicio = $cursor->copy()->startOfDay();
                $bucketFim = $cursor->copy()->endOfDay();
                $key = $cursor->format('Y-m-d');
                $label = $cursor->format('d/m/Y');
                $cursor->addDay();
            }

            $out[$key] = [
                'inicio' => $bucketInicio,
                'fim' => $bucketFim,
                'label' => $label,
            ];
        }

        return $out;
    }

    private function bucketKey(mixed $date, string $granularidade): string
    {
        $data = Carbon::parse($date);

        return $granularidade === 'mensal' ? $data->format('Y-m') : $data->format('Y-m-d');
    }

    private function signedValue(LancamentoFinanceiro $l): float
    {
        $tipo = $this->tipoValue($l);
        $valor = (float) $l->valor;

        if ($tipo === LancamentoTipo::DESPESA->value) {
            return -$valor;
        }

        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string) $l->descricao), 'recebida') ? $valor : -$valor;
        }

        return $valor;
    }

    private function tipoValue(LancamentoFinanceiro $l): string
    {
        return $l->tipo?->value ?? (string) $l->tipo;
    }

    private function isTituloAberto(ContaPagar|ContaReceber $conta): bool
    {
        $status = $conta->status?->value ?? $conta->status;

        return in_array($status, [ContaStatus::ABERTA->value, ContaStatus::PARCIAL->value], true);
    }

    private function validarTipo(string $tipo): void
    {
        if (!array_key_exists($tipo, self::TIPOS)) {
            throw ValidationException::withMessages([
                'tipo' => 'Tipo de relatorio financeiro invalido.',
            ]);
        }
    }

    private function normalizarFiltros(array $filtros): array
    {
        $inicio = Carbon::parse($filtros['data_inicio'])->startOfDay();
        $fim = Carbon::parse($filtros['data_fim'])->endOfDay();
        $contaIds = collect($filtros['conta_ids'] ?? [])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'inicio' => $inicio,
            'fim' => $fim,
            'conta_ids' => $contaIds,
            'categoria_id' => !empty($filtros['categoria_id']) ? (int) $filtros['categoria_id'] : null,
            'centro_custo_id' => !empty($filtros['centro_custo_id']) ? (int) $filtros['centro_custo_id'] : null,
            'pessoa_id' => !empty($filtros['pessoa_id']) ? (int) $filtros['pessoa_id'] : null,
            'tipo_pessoa' => $filtros['tipo_pessoa'] ?? null,
            'status' => $filtros['status'] ?? null,
            'formato' => $filtros['formato'] ?? 'padrao',
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
