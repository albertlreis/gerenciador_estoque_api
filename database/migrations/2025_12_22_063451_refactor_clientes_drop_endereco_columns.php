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
        Schema::table('clientes', function (Blueprint $table) {
            // documento: unique (NULLs múltiplos são permitidos no MySQL)
            $table->string('documento', 50)->nullable()->change();
            $table->unique('documento', 'uq_clientes_documento');

            // remove endereço legado
            $table->dropColumn([
                'endereco',
                'numero',
                'complemento',
                'bairro',
                'cidade',
                'estado',
                'cep',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        //
    }
};
