<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        DB::statement('
            INSERT INTO estoque (
                id_variacao,
                id_deposito,
                quantidade,
                created_at,
                updated_at
            )
            SELECT
                pv.id,
                d.id,
                0,
                NOW(),
                NOW()
            FROM produto_variacoes pv
            CROSS JOIN depositos d
            WHERE NOT EXISTS (
                SELECT 1
                FROM estoque e
                WHERE e.id_variacao = pv.id
                  AND e.id_deposito = d.id
            )
        ');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
