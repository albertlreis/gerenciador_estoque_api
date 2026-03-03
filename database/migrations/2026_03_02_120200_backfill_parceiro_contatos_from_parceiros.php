<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('parceiros')
            ->select(['id', 'email', 'telefone'])
            ->orderBy('id')
            ->chunkById(200, function ($parceiros) {
                foreach ($parceiros as $parceiro) {
                    $this->insertContatoIfMissing((int) $parceiro->id, 'email', $parceiro->email);
                    $this->insertContatoIfMissing((int) $parceiro->id, 'telefone', $parceiro->telefone);
                }
            });
    }

    public function down(): void
    {
        DB::table('parceiro_contatos')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where('rotulo', 'legacy_email')
                    ->orWhere('rotulo', 'legacy_telefone');
            })
            ->delete();
    }

    private function insertContatoIfMissing(int $parceiroId, string $tipo, ?string $valor): void
    {
        $valor = is_string($valor) ? trim($valor) : null;
        if ($valor === null || $valor === '') {
            return;
        }

        $exists = DB::table('parceiro_contatos')
            ->where('parceiro_id', $parceiroId)
            ->where('tipo', $tipo)
            ->where('valor', $valor)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return;
        }

        $tipoHasPrincipal = DB::table('parceiro_contatos')
            ->where('parceiro_id', $parceiroId)
            ->where('tipo', $tipo)
            ->where('principal', 1)
            ->whereNull('deleted_at')
            ->exists();

        DB::table('parceiro_contatos')->insert([
            'parceiro_id' => $parceiroId,
            'tipo' => $tipo,
            'valor' => $valor,
            'valor_e164' => null,
            'rotulo' => $tipo === 'email' ? 'legacy_email' : 'legacy_telefone',
            'principal' => $tipoHasPrincipal ? 0 : 1,
            'observacoes' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }
};
