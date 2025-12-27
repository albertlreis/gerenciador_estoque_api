<?php

namespace Database\Seeders;

use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Services\ContaPagarCommandService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ContaPagarPagamentoSeeder extends Seeder
{
    private const SEED_VERSION = 'v2';

    public function run(): void
    {
        /** @var ContaPagarCommandService $cmd */
        $cmd = app(ContaPagarCommandService::class);

        $contasFinanceiras = ContaFinanceira::query()
            ->where('ativo', 1)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($contasFinanceiras)) {
            $this->command?->warn('Nenhuma conta_financeira ativa encontrada. Pulei pagamentos de contas a pagar.');
            return;
        }

        $max = (int) (env('SEED_CONTAS_PAGAR_MAX', 5000));

        // Pega contas e pagamentos (se existir relação pagamentos())
        $contas = ContaPagar::query()
            ->with(['pagamentos' => function ($q) {
                $q->select(['id', 'conta_pagar_id', 'valor', 'observacoes', 'data_pagamento']);
            }])
            ->orderByDesc('id')
            ->limit($max)
            ->get();

        if ($contas->isEmpty()) {
            $this->command?->warn('Nenhuma conta_pagar encontrada. Pulei pagamentos.');
            return;
        }

        $formasPesos = [
            'PIX'      => 40,
            'BOLETO'   => 25,
            'TED'      => 12,
            'CARTAO'   => 13,
            'DINHEIRO' => 10,
        ];

        foreach ($contas as $conta) {
            // Total “devido” (mesma conta do seu seeder atual)
            $valorTotal = (float) ($conta->valor_bruto - $conta->desconto + $conta->juros + $conta->multa);
            if ($valorTotal <= 0) continue;

            // Se já existir qualquer pagamento dessa seed/version para esta conta, pula (idempotência)
            $seedPrefix = "seed:cp_pgto:" . self::SEED_VERSION . ":conta={$conta->id}:";
            $jaSeedado = $conta->pagamentos
                ? $conta->pagamentos->contains(fn ($p) => is_string($p->observacoes) && str_contains($p->observacoes, $seedPrefix))
                : false;

            if ($jaSeedado) continue;

            // Se já tem pagamentos reais (sem seed), respeita saldo para não “pagar duas vezes”
            $jaPago = $conta->pagamentos ? (float) $conta->pagamentos->sum('valor') : 0.0;
            $saldo  = round(max(0, $valorTotal - $jaPago), 2);
            if ($saldo <= 0) continue;

            $now = now('America/Belem');

            $emissao = $conta->data_emissao ? Carbon::parse($conta->data_emissao, 'America/Belem') : $now->copy()->subDays(random_int(1, 60));
            $venc    = $conta->data_vencimento ? Carbon::parse($conta->data_vencimento, 'America/Belem') : $emissao->copy()->addDays(random_int(10, 30));

            // Chance de pagar: contas vencidas tendem a ter mais “em aberto”
            $diasAtraso = $venc->diffInDays($now, false); // >0 se já venceu
            $chancePagar = $diasAtraso > 0 ? 0.58 : 0.75; // 58% se vencida, 75% se ainda não venceu
            if (!fake()->boolean((int) round($chancePagar * 100))) {
                continue;
            }

            // Cenários realistas:
            // 1) parcial (fica em aberto)
            // 2) total 1x
            // 3) total em 2x
            // 4) total em 3x (raro)
            $roll = random_int(1, 100);
            $modo = match (true) {
                $roll <= 18 => 'parcial',  // 18%
                $roll <= 62 => 'total_1',  // 44%
                $roll <= 92 => 'total_2',  // 30%
                default     => 'total_3',  // 8%
            };

            // Data base do pagamento (antes/no/apos vencimento)
            // - “em dia” é mais comum; atrasado aumenta se já está vencida
            $perfil = $this->perfilDataPagamento($diasAtraso);

            $formaBase = $this->weightedPick($formasPesos);

            // Define número de pagamentos e valores
            $pagamentos = [];

            if ($modo === 'parcial') {
                $p1 = round($saldo * (random_int(25, 70) / 100), 2);
                $pagamentos[] = $p1;
            } elseif ($modo === 'total_1') {
                $pagamentos[] = $saldo;
            } elseif ($modo === 'total_2') {
                $p1 = round($saldo * (random_int(35, 65) / 100), 2);
                $p2 = round($saldo - $p1, 2);
                $pagamentos = [$p1, $p2];
            } else { // total_3
                $p1 = round($saldo * (random_int(20, 40) / 100), 2);
                $p2 = round($saldo * (random_int(20, 40) / 100), 2);
                $p3 = round($saldo - $p1 - $p2, 2);
                // garante não negativo por arredondamento
                if ($p3 < 0) {
                    $p3 = max(0, round($saldo - $p1 - $p2, 2));
                }
                $pagamentos = [$p1, $p2, $p3];
            }

            // Distribui datas no tempo (para 2-3 pagamentos não caírem no mesmo dia)
            $datas = $this->datasPagamentoPara($venc, $perfil, count($pagamentos));

            foreach ($pagamentos as $idx => $valor) {
                if ($valor <= 0) continue;

                $dataPag = $datas[$idx] ?? $datas[array_key_last($datas)];

                // Algumas vezes muda a forma entre parcelas
                $forma = (count($pagamentos) > 1 && fake()->boolean(25))
                    ? $this->weightedPick($formasPesos)
                    : $formaBase;

                // Conta financeira varia (banco/caixa etc.)
                $contaFinanceiraId = $contasFinanceiras[array_rand($contasFinanceiras)];

                $seedKey = $seedPrefix . "p=" . ($idx + 1);

                $obs = $seedKey;
                if (fake()->boolean(25)) {
                    $obs = fake()->sentence() . " | " . $seedKey;
                }

                try {
                    $cmd->registrarPagamento($conta->fresh(), [
                        'data_pagamento' => $dataPag->toDateString(),
                        'valor' => $valor,
                        'forma_pagamento' => $forma,
                        'observacoes' => $obs,
                        'conta_financeira_id' => $contaFinanceiraId,
                    ]);
                } catch (\Throwable $e) {
                    // Não derruba o seeder inteiro por uma conta problemática
                    $this->command?->warn("Falha ao pagar conta_pagar #{$conta->id} ({$seedKey}): {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Define o perfil de data: 'antes', 'no_dia', 'depois'
     */
    private function perfilDataPagamento(int $diasAtraso): string
    {
        // Se já está vencida, aumenta chance de pagar atrasado
        $r = random_int(1, 100);

        if ($diasAtraso > 0) {
            return match (true) {
                $r <= 20 => 'antes',
                $r <= 45 => 'no_dia',
                default  => 'depois',
            };
        }

        return match (true) {
            $r <= 40 => 'antes',
            $r <= 75 => 'no_dia',
            default  => 'depois',
        };
    }

    /**
     * Gera datas coerentes para 1..N pagamentos.
     */
    private function datasPagamentoPara(Carbon $venc, string $perfil, int $n): array
    {
        $venc = $venc->copy()->setTimezone('America/Belem');

        $base = match ($perfil) {
            'antes'  => $venc->copy()->subDays(random_int(1, 12)),
            'no_dia' => $venc->copy(),
            default  => $venc->copy()->addDays(random_int(1, 20)),
        };

        // Evita datas “antes da emissão” demais (mas sem depender de data_emissao)
        $base->setTime(random_int(8, 18), random_int(0, 59), 0);

        if ($n <= 1) return [$base];

        // Espaça em 3-12 dias entre pagamentos
        $datas = [];
        for ($i = 0; $i < $n; $i++) {
            $delta = ($i === 0) ? 0 : random_int(3, 12) * $i;
            $datas[] = $base->copy()->addDays($delta);
        }

        return $datas;
    }

    /**
     * Weighted random pick.
     * @param array<string,int> $weights
     */
    private function weightedPick(array $weights): string
    {
        $sum = array_sum($weights);
        $r = random_int(1, max(1, $sum));
        $acc = 0;

        foreach ($weights as $k => $w) {
            $acc += $w;
            if ($r <= $acc) return $k;
        }

        return array_key_first($weights);
    }
}
