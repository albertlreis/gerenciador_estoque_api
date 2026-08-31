<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pedido_statuses')) {
            return;
        }

        DB::table('pedido_statuses')->updateOrInsert(
            ['codigo' => 'entrega_pendente'],
            [
                'nome' => 'Entrega Pendente',
                'descricao' => 'Produto recebido e mantido fisicamente no estoque, aguardando entrega ao cliente.',
                'cor' => '#fd7e14',
                'severidade' => 'warning',
                'icone' => 'pi pi-clock',
                'ativo' => true,
                'sistema' => true,
                'protegido' => true,
                'papel_operacional' => 'entrega_pendente',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('pedido_statuses')) {
            return;
        }

        DB::table('pedido_statuses')
            ->where('codigo', 'entrega_pendente')
            ->delete();
    }
};
