<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('is_outlet')->default(false)->after('ativo');
            $table->date('data_ultima_saida')->nullable()->after('is_outlet');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['is_outlet', 'data_ultima_saida']);
        });
    }
};
