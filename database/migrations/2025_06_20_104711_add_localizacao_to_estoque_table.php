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
        Schema::table('estoque', function (Blueprint $table) {
            $table->string('corredor')->nullable()->after('quantidade');
            $table->string('prateleira')->nullable()->after('corredor');
            $table->string('nivel')->nullable()->after('prateleira');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('estoque', function (Blueprint $table) {
            $table->dropColumn(['corredor', 'prateleira', 'nivel']);
        });
    }
};
