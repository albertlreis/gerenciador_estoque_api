<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->string('consultor_nome', 255)->nullable()->after('telefone');
            $table->string('nivel_fidelidade', 50)->nullable()->after('consultor_nome');
        });
    }

    public function down(): void
    {
        Schema::table('parceiros', function (Blueprint $table) {
            $table->dropColumn(['consultor_nome', 'nivel_fidelidade']);
        });
    }
};
