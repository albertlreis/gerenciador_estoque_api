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
        if (!app()->environment('testing')) {
            return;
        }

        if (!Schema::hasTable('acesso_usuarios')) {
            return;
        }

        Schema::drop('acesso_usuarios');
    }
};
