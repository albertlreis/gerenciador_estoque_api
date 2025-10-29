<?php

namespace Database\Factories;

use App\Enums\ContaPagarStatus;
use App\Models\ContaPagar;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContaPagarFactory extends Factory
{
    protected $model = ContaPagar::class;

    public function definition(): array
    {
        $valor = $this->faker->randomFloat(2, 50, 5000);
        return [
            'fornecedor_id' => null,
            'descricao' => $this->faker->sentence(4),
            'numero_documento' => (string) $this->faker->numberBetween(1000,99999),
            'data_emissao' => now()->subDays(rand(0, 30))->toDateString(),
            'data_vencimento' => now()->addDays(rand(-10, 30))->toDateString(),
            'valor_bruto' => $valor,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => $this->faker->randomElement(array_column(ContaPagarStatus::cases(), 'value')),
            'forma_pagamento' => $this->faker->randomElement(['PIX','BOLETO','TED','DINHEIRO','CARTAO']),
            'centro_custo' => $this->faker->randomElement(['ADM','OPER','MKT']),
            'categoria' => $this->faker->randomElement(['Água','Luz','Internet','Impostos','Fornecedor']),
            'observacoes' => $this->faker->optional()->sentence(),
        ];
    }
}
