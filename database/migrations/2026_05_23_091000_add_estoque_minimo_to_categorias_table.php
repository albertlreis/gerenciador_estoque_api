<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            if (!Schema::hasColumn('categorias', 'estoque_minimo')) {
                $table->unsignedInteger('estoque_minimo')->nullable()->after('categoria_pai_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            if (Schema::hasColumn('categorias', 'estoque_minimo')) {
                $table->dropColumn('estoque_minimo');
            }
        });
    }
};
