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
            CREATE TRIGGER trg_estoque_after_insert_variacao
            AFTER INSERT ON produto_variacoes
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
                    NEW.id,
                    d.id,
                    0,
                    NOW(),
                    NOW()
                FROM depositos d
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM estoque e
                    WHERE e.id_variacao = NEW.id
                      AND e.id_deposito = d.id
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
        DB::unprepared('DROP TRIGGER IF EXISTS trg_estoque_after_insert_variacao');
    }
};
