<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillParceiroContatosCommand extends Command
{
    protected $signature = 'parceiros:backfill-contatos';

    protected $description = 'Migra email/telefone legados de parceiros para parceiro_contatos de forma idempotente';

    public function handle(): int
    {
        $inseridos = 0;

        DB::table('parceiros')
            ->select(['id', 'email', 'telefone'])
            ->orderBy('id')
            ->chunkById(200, function ($parceiros) use (&$inseridos) {
                foreach ($parceiros as $parceiro) {
                    $inseridos += $this->insertContatoIfMissing((int) $parceiro->id, 'email', $parceiro->email);
                    $inseridos += $this->insertContatoIfMissing((int) $parceiro->id, 'telefone', $parceiro->telefone);
                }
            });

        $this->info("Backfill concluído. Inseridos: {$inseridos}");

        return self::SUCCESS;
    }

    private function insertContatoIfMissing(int $parceiroId, string $tipo, ?string $valor): int
    {
        $valor = is_string($valor) ? trim($valor) : null;
        if ($valor === null || $valor === '') {
            return 0;
        }

        $exists = DB::table('parceiro_contatos')
            ->where('parceiro_id', $parceiroId)
            ->where('tipo', $tipo)
            ->where('valor', $valor)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return 0;
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

        return 1;
    }
}
