<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('acesso_usuarios')) {
            return;
        }

        Schema::table('acesso_usuarios', function (Blueprint $table) {
            if (!Schema::hasColumn('acesso_usuarios', 'forcar_troca_senha')) {
                $table->boolean('forcar_troca_senha')
                    ->default(false)
                    ->after('senha_alterada_em');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('acesso_usuarios')) {
            return;
        }

        Schema::table('acesso_usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('acesso_usuarios', 'forcar_troca_senha')) {
                $table->dropColumn('forcar_troca_senha');
            }
        });
    }
};
