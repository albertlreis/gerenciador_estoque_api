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
    public function up()
    {
        Schema::table('carrinhos', function (Blueprint $table) {
            $table->enum('status', ['rascunho', 'finalizado', 'cancelado'])->default('rascunho')->after('id');
            $table->unsignedInteger('id_parceiro')->nullable()->after('id_cliente');
            $table->foreign('id_parceiro')->references('id')->on('parceiros');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('carrinhos', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropForeign(['id_parceiro']);
            $table->dropColumn('id_parceiro');
        });
    }
};
