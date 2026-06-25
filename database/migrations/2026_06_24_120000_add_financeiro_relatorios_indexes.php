<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lancamentos_financeiros', function (Blueprint $table) {
            $table->index(['conta_id', 'status', 'data_movimento'], 'ix_lf_conta_status_movimento');
            $table->index(['categoria_id', 'centro_custo_id', 'competencia'], 'ix_lf_cat_cc_competencia');
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos_financeiros', function (Blueprint $table) {
            $table->dropIndex('ix_lf_conta_status_movimento');
            $table->dropIndex('ix_lf_cat_cc_competencia');
        });
    }
};
