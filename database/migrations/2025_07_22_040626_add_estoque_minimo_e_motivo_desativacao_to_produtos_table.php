<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->text('motivo_desativacao')->nullable()->after('ativo');
            $table->unsignedInteger('estoque_minimo')->nullable()->after('manual_conservacao');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['motivo_desativacao', 'estoque_minimo']);
        });
    }
};
