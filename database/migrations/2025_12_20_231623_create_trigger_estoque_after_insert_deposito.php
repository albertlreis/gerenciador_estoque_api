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
        DB::unprepared('
            CREATE TRIGGER trg_estoque_after_insert_deposito
            AFTER INSERT ON depositos
            FOR EACH ROW
            BEGIN
                INSERT INTO estoque (
                    id_variacao,
                    id_deposito,
                    quantidade,
                    created_at,
                    updated_at
                )
                SELECT
                    pv.id,
                    NEW.id,
                    0,
                    NOW(),
                    NOW()
                FROM produto_variacoes pv
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM estoque e
                    WHERE e.id_variacao = pv.id
                      AND e.id_deposito = NEW.id
                );
            END
        ');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_estoque_after_insert_deposito');
    }
};
