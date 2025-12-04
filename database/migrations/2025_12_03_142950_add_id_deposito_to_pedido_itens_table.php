<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {

            // Só adiciona se ainda não existir
            if (!Schema::hasColumn('pedido_itens', 'id_deposito')) {
                $table->unsignedInteger('id_deposito')->nullable()->after('id_variacao');

                $table->foreign('id_deposito')
                    ->references('id')
                    ->on('depositos')
                    ->nullOnDelete();

                // FK opcional (caso sua tabela depositos exista)
                if (Schema::hasTable('depositos')) {
                    $table->foreign('id_deposito')
                        ->references('id')
                        ->on('depositos')
                        ->nullOnDelete();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table) {

            if (Schema::hasColumn('pedido_itens', 'id_deposito')) {

                // Remove FK primeiro, se existir
                try {
                    $table->dropForeign(['id_deposito']);
                } catch (Throwable $e) {
                    // ignora, FK pode não existir
                }

                // Remove coluna
                $table->dropColumn('id_deposito');
            }
        });
    }
};
