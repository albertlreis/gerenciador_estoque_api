<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasColumn('clientes', 'data_nascimento')) {
                $table->date('data_nascimento')->nullable()->after('whatsapp');
                $table->index('data_nascimento', 'idx_clientes_data_nascimento');
            }
        });

        Schema::table('parceiros', function (Blueprint $table) {
            if (!Schema::hasColumn('parceiros', 'data_nascimento')) {
                $table->date('data_nascimento')->nullable()->after('documento');
                $table->index('data_nascimento', 'idx_parceiros_data_nascimento');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (Schema::hasColumn('clientes', 'data_nascimento')) {
                $table->dropIndex('idx_clientes_data_nascimento');
                $table->dropColumn('data_nascimento');
            }
        });

        Schema::table('parceiros', function (Blueprint $table) {
            if (Schema::hasColumn('parceiros', 'data_nascimento')) {
                $table->dropIndex('idx_parceiros_data_nascimento');
                $table->dropColumn('data_nascimento');
            }
        });
    }
};

