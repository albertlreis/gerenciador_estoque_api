<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('nfe_xml_path')->nullable()->after('data_limite_entrega');
            $table->string('nfe_xml_nome')->nullable()->after('nfe_xml_path');
            $table->string('nfe_xml_hash', 64)->nullable()->after('nfe_xml_nome');
            $table->unsignedInteger('nfe_xml_uploaded_by')->nullable()->after('nfe_xml_hash');
            $table->timestamp('nfe_xml_uploaded_at')->nullable()->after('nfe_xml_uploaded_by');

            $table->foreign('nfe_xml_uploaded_by')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['nfe_xml_uploaded_by']);
            $table->dropColumn([
                'nfe_xml_path',
                'nfe_xml_nome',
                'nfe_xml_hash',
                'nfe_xml_uploaded_by',
                'nfe_xml_uploaded_at',
            ]);
        });
    }
};
