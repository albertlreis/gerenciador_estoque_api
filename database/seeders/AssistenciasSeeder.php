<?php

namespace Database\Seeders;

use App\Models\Assistencia;
use Illuminate\Database\Seeder;

class AssistenciasSeeder extends Seeder
{
    public function run(): void
    {
        $lista = [
            [
                'nome' => 'TechFix Autorizada',
                'cnpj' => '12.345.678/0001-90',
                'telefone' => '(11) 4000-1000',
                'email' => 'contato@techfix.com.br',
                'contato' => 'Mariana Souza',
                'endereco_json' => [
                    'logradouro' => 'Rua das Laranjeiras, 100',
                    'bairro' => 'Centro',
                    'cidade' => 'São Paulo',
                    'uf' => 'SP',
                    'cep' => '01010-000',
                ],
                'ativo' => true,
                'observacoes' => 'Atende linha marcenaria e cadeiras.',
            ],
            [
                'nome' => 'Oficina do Móvel',
                'cnpj' => '98.765.432/0001-10',
                'telefone' => '(21) 3555-2020',
                'email' => 'suporte@oficinamovel.com.br',
                'contato' => 'Carlos Henrique',
                'endereco_json' => [
                    'logradouro' => 'Av. Atlântica, 250',
                    'bairro' => 'Copacabana',
                    'cidade' => 'Rio de Janeiro',
                    'uf' => 'RJ',
                    'cep' => '22010-001',
                ],
                'ativo' => true,
                'observacoes' => 'Especializada em estruturas metálicas.',
            ],
            [
                'nome' => 'Autorizada Norte Sul',
                'cnpj' => '11.111.111/0001-11',
                'telefone' => '(92) 3300-8080',
                'email' => 'atendimento@nortesul.com.br',
                'contato' => 'Eliane Martins',
                'endereco_json' => [
                    'logradouro' => 'Rua Manaós, 77',
                    'bairro' => 'Centro',
                    'cidade' => 'Manaus',
                    'uf' => 'AM',
                    'cep' => '69005-000',
                ],
                'ativo' => true,
                'observacoes' => null,
            ],
        ];

        foreach ($lista as $dados) {
            Assistencia::query()->updateOrCreate(
                ['cnpj' => $dados['cnpj']],
                $dados
            );
        }
    }
}
