<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_PAR = 'pva_variacao_atributo_valor_unique';

    private const INDEX_NOME = 'pva_variacao_atributo_index';

    public function up(): void
    {
        Schema::table('produto_variacao_atributos', function (Blueprint $table): void {
            // Mantém um índice de suporte à FK antes de remover a unicidade antiga.
            $table->index(['id_variacao', 'atributo'], self::INDEX_NOME);
        });

        Schema::table('produto_variacao_atributos', function (Blueprint $table): void {
            $table->dropUnique(['id_variacao', 'atributo']);
            $table->unique(['id_variacao', 'atributo', 'valor'], self::UNIQUE_PAR);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('produto_variacao_atributos')) {
            return;
        }

        $nomesVistos = [];
        DB::table('produto_variacao_atributos')
            ->select('id', 'id_variacao', 'atributo')
            ->chunkById(1000, function ($atributos) use (&$nomesVistos): void {
                foreach ($atributos as $atributo) {
                    $nomeNormalizado = $this->normalizarAtributo((string) $atributo->atributo);
                    $chave = (int) $atributo->id_variacao."\0".$nomeNormalizado;

                    if ($nomeNormalizado !== '' && isset($nomesVistos[$chave])) {
                        throw new \RuntimeException(
                            'Rollback abortado: existem variações com mais de um valor para nomes de atributo equivalentes.'
                        );
                    }

                    if ($nomeNormalizado !== '') {
                        $nomesVistos[$chave] = true;
                    }
                }
            });

        Schema::table('produto_variacao_atributos', function (Blueprint $table): void {
            // A unicidade antiga precisa existir antes da remoção dos índices novos,
            // pois sua primeira coluna também sustenta a chave estrangeira.
            $table->unique(['id_variacao', 'atributo']);
        });

        Schema::table('produto_variacao_atributos', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_PAR);
            $table->dropIndex(self::INDEX_NOME);
        });
    }

    private function normalizarAtributo(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $texto = preg_replace('/[^a-z0-9]+/i', '_', $texto) ?? '';

        return trim($texto, '_');
    }
};
