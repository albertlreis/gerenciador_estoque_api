<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!app()->environment('testing')) {
            return;
        }

        if (Schema::hasTable('acesso_usuarios')) {
            return;
        }

        Schema::create('acesso_usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('email')->nullable();
            $table->string('senha')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Segurança: nunca tocar em tabela compartilhada fora de testes.
        if (!app()->environment('testing')) {
            return;
        }

        // Evita risco no banco compartilhado; tabela de teste pode permanecer.
        $database = (string) config('database.connections.mysql.database');
        if ($database !== 'estoque_test') {
            return;
        }
    }
};
