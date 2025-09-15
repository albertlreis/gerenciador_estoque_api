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
        Schema::create('parceiros', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome', 255);
            $table->string('tipo', 50);
            $table->string('documento', 50);
            $table->string('email', 100)->nullable();
            $table->string('telefone', 50)->nullable();
            $table->text('endereco')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status']);
            $table->index(['documento']);
            $table->index(['nome']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('parceiros');
    }
};
