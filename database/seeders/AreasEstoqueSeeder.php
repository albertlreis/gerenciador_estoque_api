<?php

namespace Database\Seeders;

use App\Models\AreaEstoque;
use Illuminate\Database\Seeder;

/**
 * Semeia as áreas padrão solicitadas:
 * Assistência, Devolução, Tampos Avariados, Tampos Clientes, Avarias
 */
class AreasEstoqueSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            ['nome' => 'Assistência'],
            ['nome' => 'Devolução'],
            ['nome' => 'Tampos Avariados'],
            ['nome' => 'Tampos Clientes'],
            ['nome' => 'Avarias'],
        ];

        foreach ($areas as $a) {
            AreaEstoque::firstOrCreate(['nome' => $a['nome']], $a);
        }
    }
}
