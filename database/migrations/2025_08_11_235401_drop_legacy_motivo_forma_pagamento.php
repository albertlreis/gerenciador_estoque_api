<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produto_variacao_outlets', function (Blueprint $t) {
            $t->foreignId('motivo_id')->nullable(false)->change();
        });

        Schema::table('produto_variacao_outlet_pagamentos', function (Blueprint $t) {
            $t->foreignId('forma_pagamento_id')->nullable(false)->change();
        });

        Schema::table('produto_variacao_outlets', function (Blueprint $t) {
            if (Schema::hasColumn('produto_variacao_outlets', 'motivo')) {
                $t->dropColumn('motivo');
            }
        });
        Schema::table('produto_variacao_outlet_pagamentos', function (Blueprint $t) {
            if (Schema::hasColumn('produto_variacao_outlet_pagamentos', 'forma_pagamento')) {
                $t->dropColumn('forma_pagamento');
            }
        });
    }

    public function down(): void
    {
        // Recria as colunas (nullable) apenas para rollback
        Schema::table('produto_variacao_outlets', function (Blueprint $t) {
            if (!Schema::hasColumn('produto_variacao_outlets', 'motivo')) {
                $t->string('motivo')->nullable()->after('motivo_id');
            }
            $t->foreignId('motivo_id')->nullable()->change();
        });
        Schema::table('produto_variacao_outlet_pagamentos', function (Blueprint $t) {
            if (!Schema::hasColumn('produto_variacao_outlet_pagamentos', 'forma_pagamento')) {
                $t->string('forma_pagamento')->nullable()->after('forma_pagamento_id');
            }
            $t->foreignId('forma_pagamento_id')->nullable()->change();
        });
    }
};
