<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clientes', 'data_nascimento')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->date('data_nascimento')->nullable()->after('whatsapp');
            });
        }

        if (! Schema::hasColumn('parceiros', 'data_nascimento')) {
            Schema::table('parceiros', function (Blueprint $table) {
                $table->date('data_nascimento')->nullable()->after('telefone');
            });
        }
    }

    public function down(): void
    {
        // Columns are owned by the canonical 2026_03_05 migration.
    }
};
