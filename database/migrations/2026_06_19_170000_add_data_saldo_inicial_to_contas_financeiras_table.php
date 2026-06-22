<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table) {
            if (!Schema::hasColumn('contas_financeiras', 'data_saldo_inicial')) {
                $table->date('data_saldo_inicial')
                    ->default('1900-01-01')
                    ->after('saldo_inicial')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contas_financeiras', function (Blueprint $table) {
            if (Schema::hasColumn('contas_financeiras', 'data_saldo_inicial')) {
                $table->dropColumn('data_saldo_inicial');
            }
        });
    }
};
