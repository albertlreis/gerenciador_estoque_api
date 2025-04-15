<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterClientesReorderAndAddFields extends Migration
{
    public function up()
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nome_fantasia')->nullable()->after('nome')->comment('Nome fantasia do cliente (apenas para pessoa jurídica)');
            $table->string('inscricao_estadual')->nullable()->after('documento')->comment('Inscrição estadual (apenas para pessoa jurídica)');
            $table->string('tipo', 10)->default('pf')->after('endereco')->comment('Tipo de cliente: pf (Pessoa Física) ou pj (Pessoa Jurídica)');
            $table->string('whatsapp', 20)->nullable()->after('tipo');
            $table->string('cep', 20)->nullable()->after('whatsapp');
            $table->string('complemento', 255)->nullable()->after('cep');
        });
    }

    public function down()
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['nome_fantasia', 'inscricao_estadual', 'whatsapp', 'cep', 'complemento']);
        });
    }
}
