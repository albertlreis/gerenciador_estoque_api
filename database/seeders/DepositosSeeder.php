<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DepositosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $depositos = [
            [
                'nome' => 'Showroom Umarizal',
                'endereco' => 'Av. Senador Lemos, 980 – Umarizal, Belém/PA',
            ],
            [
                'nome' => 'Depósito Marambaia',
                'endereco' => 'Rod. Augusto Montenegro, 5300 – Marambaia, Belém/PA',
            ],
            [
                'nome' => 'Depósito Ananindeua',
                'endereco' => 'BR-316, km 8 – Distrito Industrial, Ananindeua/PA',
            ],
            [
                'nome' => 'Showroom Batista Campos',
                'endereco' => 'Trav. Padre Eutíquio, 1455 – Batista Campos, Belém/PA',
            ],
        ];

        foreach ($depositos as &$d) {
            $d['created_at'] = $now;
            $d['updated_at'] = $now;
        }

        DB::table('depositos')->insert($depositos);
    }
}
