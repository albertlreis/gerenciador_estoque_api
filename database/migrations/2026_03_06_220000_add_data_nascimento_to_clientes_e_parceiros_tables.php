<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->date('data_nascimento')->nullable()->after('whatsapp');
        });

        Schema::table('parceiros', function (Blueprint $table) {
            $table->date('data_nascimento')->nullable()->after('telefone');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('data_nascimento');
        });

        Schema::table('parceiros', function (Blueprint $table) {
            $table->dropColumn('data_nascimento');
        });
    }
};
