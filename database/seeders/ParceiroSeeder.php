<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Parceiro;

class ParceiroSeeder extends Seeder
{
    public function run(): void
    {
        Parceiro::insert([
            [
                'nome' => 'Arq. Fernanda Lima',
                'tipo' => 'arquiteto',
                'documento' => '12345678901',
                'email' => 'fernanda@example.com',
                'telefone' => '(91) 91234-5678',
                'endereco' => 'Rua das Palmeiras, 100',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Design Paula Ribeiro',
                'tipo' => 'designer',
                'documento' => '98765432100',
                'email' => 'paula@example.com',
                'telefone' => '(91) 99876-5432',
                'endereco' => 'Av. Beira Rio, 200',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Studio Criativo Maia',
                'tipo' => 'designer',
                'documento' => '54321987600',
                'email' => 'maia@studio.com',
                'telefone' => '(91) 99911-2222',
                'endereco' => 'Travessa Tucumã, 85',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Eng. Carlos Alberto',
                'tipo' => 'engenheiro',
                'documento' => '32165498700',
                'email' => 'carlos@engenharia.com',
                'telefone' => '(91) 99888-7777',
                'endereco' => 'Rua do Arsenal, 50',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Marcenaria Madeira Fina',
                'tipo' => 'marceneiro',
                'documento' => '45678912300',
                'email' => 'contato@madeirafina.com',
                'telefone' => '(91) 98123-4567',
                'endereco' => 'Av. Almirante Barroso, 900',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Paisagismo Verde Vida',
                'tipo' => 'paisagista',
                'documento' => '11223344556',
                'email' => 'verde@vida.com',
                'telefone' => '(91) 98777-3344',
                'endereco' => 'Rodovia Augusto Montenegro, 1120',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Construtora Renascer',
                'tipo' => 'construtora',
                'documento' => '10293847566',
                'email' => 'renascer@construtora.com',
                'telefone' => '(91) 98456-7890',
                'endereco' => 'Passagem Jacarandá, 305',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Consultoria ArqPlan',
                'tipo' => 'consultoria',
                'documento' => '99887766554',
                'email' => 'contato@arqplan.com',
                'telefone' => '(91) 99333-1122',
                'endereco' => 'Rua dos Cravos, 190',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Decoradora Ana Paula',
                'tipo' => 'decorador',
                'documento' => '77665544332',
                'email' => 'ana@decoradora.com',
                'telefone' => '(91) 99666-5544',
                'endereco' => 'Rua do Comércio, 45',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Iluminação Ideal',
                'tipo' => 'iluminador',
                'documento' => '33445566778',
                'email' => 'atendimento@ideal.com',
                'telefone' => '(91) 99999-8888',
                'endereco' => 'Av. Nazaré, 1000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
