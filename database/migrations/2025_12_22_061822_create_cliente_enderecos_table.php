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
        Schema::create('cliente_enderecos', function (Blueprint $table) {
            $table->increments('id'); // <- int unsigned (compatível)

            $table->unsignedInteger('cliente_id'); // <- int unsigned (compatível)
            $table->foreign('cliente_id')
                ->references('id')
                ->on('clientes')
                ->onDelete('cascade');

            $table->string('cep', 10)->nullable();
            $table->string('endereco', 255)->nullable();
            $table->string('numero', 50)->nullable();
            $table->string('complemento', 255)->nullable();
            $table->string('bairro', 120)->nullable();
            $table->string('cidade', 120)->nullable();
            $table->string('estado', 2)->nullable();

            $table->boolean('principal')->default(false);
            $table->string('fingerprint', 64);

            $table->timestamps();

            $table->index(['cliente_id', 'principal']);
            $table->unique(['cliente_id', 'fingerprint'], 'uq_cliente_endereco_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('cliente_enderecos');
    }
};
