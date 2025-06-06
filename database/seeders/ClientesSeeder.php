<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Carbon\Carbon;

class ClientesSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('pt_BR');
        $now = Carbon::now();
        $clientes = [];

        for ($i = 0; $i < 20; $i++) {
            $isPJ = $faker->boolean(30); // 30% PJ

            $clientes[] = [
                'nome' => $isPJ ? $faker->company : $faker->name,
                'nome_fantasia' => $isPJ ? $faker->companySuffix . ' ' . $faker->word : null,
                'documento' => $isPJ ? $faker->cnpj(false) : $faker->cpf(false),
                'inscricao_estadual' => $isPJ ? $faker->numerify('###########') : null,
                'email' => $faker->unique()->safeEmail,
                'telefone' => $faker->phoneNumber,
                'whatsapp' => $faker->phoneNumber,
                'endereco' => $faker->streetName,
                'numero' => $faker->buildingNumber,
                'bairro' => $faker->randomElement(['Marco', 'Umarizal', 'Pedreira', 'Batista Campos', 'Nazaré']),
                'cidade' => 'Belém',
                'estado' => 'PA',
                'cep' => $faker->postcode,
                'complemento' => $faker->optional()->secondaryAddress,
                'tipo' => $isPJ ? 'pj' : 'pf',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('clientes')->insert($clientes);
    }
}
