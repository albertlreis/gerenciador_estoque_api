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
            if (!Schema::hasColumn('acesso_usuarios', 'telefone')) {
                $table->string('telefone', 30)->nullable()->after('email');
            }

            if (!Schema::hasColumn('acesso_usuarios', 'cargo')) {
                $table->string('cargo', 100)->nullable()->after('telefone');
            }

            if (!Schema::hasColumn('acesso_usuarios', 'avatar_path')) {
                $table->string('avatar_path', 255)->nullable()->after('cargo');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('acesso_usuarios')) {
            return;
        }

        Schema::table('acesso_usuarios', function (Blueprint $table) {
            foreach (['avatar_path', 'cargo', 'telefone'] as $column) {
                if (Schema::hasColumn('acesso_usuarios', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
