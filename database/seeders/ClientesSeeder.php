<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ClientesSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();
        DB::table('clientes')->insert([
            [
                'nome'       => 'João Silva',
                'documento'  => '123456789',
                'email'      => 'joao.silva@example.com',
                'telefone'   => '1112345678',
                'endereco'   => 'Rua A, 123',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Maria Oliveira',
                'documento'  => '987654321',
                'email'      => 'maria.oliveira@example.com',
                'telefone'   => '2223456789',
                'endereco'   => 'Av. B, 456',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Pedro Santos',
                'documento'  => '456123789',
                'email'      => 'pedro.santos@example.com',
                'telefone'   => '3334567890',
                'endereco'   => 'Rua C, 789',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Ana Costa',
                'documento'  => '789123456',
                'email'      => 'ana.costa@example.com',
                'telefone'   => '4445678901',
                'endereco'   => 'Av. D, 321',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
