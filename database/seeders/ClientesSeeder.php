<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\ClienteEndereco;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ClientesSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('pt_BR');
        $now = Carbon::now();

        for ($i = 0; $i < 20; $i++) {
            $isPJ = $faker->boolean(30); // 30% PJ

            /** @var Cliente $cliente */
            $cliente = Cliente::create([
                'nome'              => $isPJ ? $faker->company : $faker->name,
                'nome_fantasia'     => $isPJ ? $faker->companySuffix . ' ' . $faker->word : null,
                'documento'         => $isPJ ? $faker->cnpj(false) : $faker->cpf(false),
                'inscricao_estadual'=> $isPJ ? $faker->numerify('###########') : null,
                'email'             => $faker->unique()->safeEmail,
                'telefone'          => $faker->phoneNumber,
                'whatsapp'          => $faker->phoneNumber,
                'tipo'              => $isPJ ? 'pj' : 'pf',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            // Endereço principal
            ClienteEndereco::create([
                'cliente_id'  => $cliente->id,
                'cep'         => $faker->postcode,
                'endereco'    => $faker->streetName,
                'numero'      => $faker->buildingNumber,
                'complemento' => $faker->optional()->secondaryAddress,
                'bairro'      => $faker->randomElement([
                    'Marco', 'Umarizal', 'Pedreira', 'Batista Campos', 'Nazaré'
                ]),
                'cidade'      => 'Belém',
                'estado'      => 'PA',
                'principal'   => true,
                'fingerprint' => Str::uuid(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }
}
