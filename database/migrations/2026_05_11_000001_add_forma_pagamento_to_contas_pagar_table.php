<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->string('forma_pagamento', 50)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->dropIndex(['forma_pagamento']);
            $table->dropColumn('forma_pagamento');
        });
    }
};
