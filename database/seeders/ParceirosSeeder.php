<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Carbon\Carbon;

class ParceirosSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('pt_BR');
        $tipos = ['arquiteto', 'designer', 'engenheiro', 'decorador', 'consultor'];
        $now = Carbon::now();

        $parceiros = [];

        for ($i = 0; $i < 15; $i++) {
            $parceiros[] = [
                'nome' => $faker->name,
                'tipo' => $faker->randomElement($tipos),
                'documento' => $faker->cpf(false),
                'email' => $faker->unique()->safeEmail,
                'telefone' => $faker->phoneNumber,
                'endereco' => $faker->address,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('parceiros')->insert($parceiros);
    }
}
