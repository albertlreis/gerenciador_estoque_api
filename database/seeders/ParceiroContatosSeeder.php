<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParceiroContatosSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $parceiros = DB::table('parceiros')->get(['id', 'email', 'telefone']);

        foreach ($parceiros as $parceiro) {
            $email = is_string($parceiro->email) ? trim($parceiro->email) : null;
            if ($email !== null && $email !== '') {
                DB::table('parceiro_contatos')->updateOrInsert(
                    ['parceiro_id' => $parceiro->id, 'tipo' => 'email', 'valor' => $email],
                    ['rotulo' => 'principal', 'principal' => true, 'observacoes' => null, 'updated_at' => $now, 'created_at' => $now]
                );
            }

            $telefone = is_string($parceiro->telefone) ? trim($parceiro->telefone) : null;
            if ($telefone !== null && $telefone !== '') {
                DB::table('parceiro_contatos')->updateOrInsert(
                    ['parceiro_id' => $parceiro->id, 'tipo' => 'telefone', 'valor' => $telefone],
                    ['rotulo' => 'principal', 'principal' => true, 'observacoes' => null, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }
}
