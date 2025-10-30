<?php

namespace Database\Seeders;

use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ContaPagarPagamentoSeeder extends Seeder
{
    public function run(): void
    {
        $contas = ContaPagar::all();

        foreach ($contas as $conta) {
            $valorTotal = (float) ($conta->valor_bruto - $conta->desconto + $conta->juros + $conta->multa);

            // Decide se a conta terá pagamentos
            if (fake()->boolean(70)) { // 70% terão pelo menos um pagamento
                $numPagamentos = fake()->boolean(30) ? 2 : 1;
                $soma = 0;

                for ($i = 1; $i <= $numPagamentos; $i++) {
                    $restante = $valorTotal - $soma;
                    $valor = $i === $numPagamentos
                        ? $restante
                        : round($restante * fake()->randomFloat(2, 0.4, 0.7), 2);
                    $soma += $valor;

                    ContaPagarPagamento::create([
                        'conta_pagar_id' => $conta->id,
                        'data_pagamento' => Carbon::parse($conta->data_emissao)->addDays(rand(5, 25)),
                        'valor'          => $valor,
                        'forma_pagamento'=> $conta->forma_pagamento,
                        'observacoes'    => fake()->boolean(25) ? fake()->sentence() : null,
                        'usuario_id'     => 1,
                    ]);
                }

                // Atualiza status conforme total pago
                $pago = $soma;
                $liquido = $valorTotal;

                if ($pago >= $liquido - 0.01) {
                    $conta->update(['status' => 'PAGA']);
                } elseif ($pago > 0) {
                    $conta->update(['status' => 'PARCIAL']);
                } else {
                    $conta->update(['status' => 'ABERTA']);
                }
            }
        }
    }
}
