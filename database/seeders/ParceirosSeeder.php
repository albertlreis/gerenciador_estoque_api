<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ParceirosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $parceiros = [
            [
                'nome' => 'Ateliê Arq Norte',
                'tipo' => 'arquiteto',
                'documento' => '11111111111',
                'email' => 'contato@ateliearqnorte.com',
                'telefone' => '91910000001',
                'consultor_nome' => 'Equipe Comercial',
                'nivel_fidelidade' => 'ouro',
                'endereco' => 'Belém/PA',
            ],
            [
                'nome' => 'Studio Decor Prime',
                'tipo' => 'designer',
                'documento' => '22222222222',
                'email' => 'contato@studiodecorprime.com',
                'telefone' => '91910000002',
                'consultor_nome' => 'Equipe Comercial',
                'nivel_fidelidade' => 'prata',
                'endereco' => 'Ananindeua/PA',
            ],
            [
                'nome' => 'Consultoria Espaço & Forma',
                'tipo' => 'consultor',
                'documento' => '33333333333',
                'email' => 'contato@espacoeforma.com',
                'telefone' => '91910000003',
                'consultor_nome' => 'Equipe Comercial',
                'nivel_fidelidade' => 'bronze',
                'endereco' => 'Belém/PA',
            ],
        ];

        foreach ($parceiros as &$parceiro) {
            $parceiro['created_at'] = $now;
            $parceiro['updated_at'] = $now;
        }

        DB::table('parceiros')->upsert(
            $parceiros,
            ['documento'],
            ['nome', 'tipo', 'email', 'telefone', 'consultor_nome', 'nivel_fidelidade', 'endereco', 'updated_at']
        );
    }
}
