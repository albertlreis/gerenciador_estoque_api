<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeriadosSeeder extends Seeder
{
    public function run(): void
    {
        $anoAtual = (int) now('America/Belem')->year;
        $anos = [$anoAtual, $anoAtual + 1];
        $uf = (string) config('holidays.default_uf', 'PA');
        $now = now();

        $fixos = [
            ['mes_dia' => '01-01', 'nome' => 'Confraternização Universal', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '04-21', 'nome' => 'Tiradentes', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '05-01', 'nome' => 'Dia do Trabalho', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '09-07', 'nome' => 'Independência do Brasil', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '10-12', 'nome' => 'Nossa Senhora Aparecida', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '11-02', 'nome' => 'Finados', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '11-15', 'nome' => 'Proclamação da República', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '12-25', 'nome' => 'Natal', 'escopo' => 'nacional', 'uf' => null],
            ['mes_dia' => '08-15', 'nome' => 'Adesão do Pará', 'escopo' => 'estadual', 'uf' => $uf],
        ];

        $rows = [];
        foreach ($anos as $ano) {
            foreach ($fixos as $feriado) {
                $rows[] = [
                    'data' => "{$ano}-{$feriado['mes_dia']}",
                    'nome' => $feriado['nome'],
                    'escopo' => $feriado['escopo'],
                    'uf' => $feriado['uf'],
                    'fonte' => 'manual',
                    'ano' => $ano,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('feriados')->upsert(
            $rows,
            ['data', 'escopo', 'uf'],
            ['nome', 'fonte', 'ano', 'updated_at']
        );
    }
}
