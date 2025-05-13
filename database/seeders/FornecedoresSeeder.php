<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FornecedoresSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $fornecedores = [
            [
                'nome' => 'Moveis Premium LTDA',
                'cnpj' => '12.345.678/0001-01',
                'email' => 'contato@moveispremium.com',
                'telefone' => '(11) 99999-0001',
                'endereco' => 'Av. Brasil, 123 - São Paulo/SP',
            ],
            [
                'nome' => 'Estofados Ideal',
                'cnpj' => '98.765.432/0001-02',
                'email' => 'vendas@estofadosideal.com.br',
                'telefone' => '(21) 98888-2222',
                'endereco' => 'Rua das Palmeiras, 456 - Rio de Janeiro/RJ',
            ],
            [
                'nome' => 'Design Industrial Móveis',
                'cnpj' => '33.456.789/0001-03',
                'email' => 'contato@designindustrial.com',
                'telefone' => '(31) 97777-3333',
                'endereco' => 'Av. Afonso Pena, 890 - Belo Horizonte/MG',
            ],
            [
                'nome' => 'Conforto Móveis',
                'cnpj' => '44.123.456/0001-04',
                'email' => 'conforto@moveis.com',
                'telefone' => '(41) 96666-4444',
                'endereco' => 'Rua Curitiba, 99 - Curitiba/PR',
            ],
            [
                'nome' => 'Móveis & Estilo',
                'cnpj' => '55.987.654/0001-05',
                'email' => 'vendas@moveisestilo.com',
                'telefone' => '(51) 95555-5555',
                'endereco' => 'Av. Ipiranga, 222 - Porto Alegre/RS',
            ],
        ];

        foreach ($fornecedores as &$f) {
            $f['created_at'] = $now;
            $f['updated_at'] = $now;
        }

        DB::table('fornecedores')->insert($fornecedores);
    }
}
