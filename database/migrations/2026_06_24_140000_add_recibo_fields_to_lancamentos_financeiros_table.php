<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lancamentos_financeiros', function (Blueprint $table) {
            $table->string('recibo_pessoa_nome', 255)->nullable()->after('observacoes');
            $table->string('recibo_pessoa_documento', 60)->nullable()->after('recibo_pessoa_nome');
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos_financeiros', function (Blueprint $table) {
            $table->dropColumn(['recibo_pessoa_nome', 'recibo_pessoa_documento']);
        });
    }
};
