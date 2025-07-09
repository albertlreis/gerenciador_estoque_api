<?php

namespace Database\Seeders;

use App\Models\Estoque;
use App\Models\LocalizacaoEstoque;
use Illuminate\Database\Seeder;

class LocalizacaoEstoqueSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $estoques = Estoque::all();

        foreach ($estoques as $estoque) {
            LocalizacaoEstoque::updateOrCreate(
                ['estoque_id' => $estoque->id],
                [
                    'corredor' => 'C' . rand(1, 5),
                    'prateleira' => 'P' . rand(1, 3),
                    'coluna' => 'L' . rand(1, 4),
                    'nivel' => 'N' . rand(1, 2),
                    'observacoes' => 'Gerado automaticamente na seed'
                ]
            );
        }
    }
}
