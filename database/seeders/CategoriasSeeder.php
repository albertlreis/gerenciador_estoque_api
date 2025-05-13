<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CategoriasSeeder extends Seeder
{
    public function run()
    {
        $now = Carbon::now();

        // Categorias de nível raiz
        $sofasId = DB::table('categorias')->insertGetId([
            'nome' => 'Sofás',
            'descricao' => 'Sofás modernos e confortáveis para ambientes residenciais e corporativos',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mesasId = DB::table('categorias')->insertGetId([
            'nome' => 'Mesas',
            'descricao' => 'Mesas para sala de jantar, escritório e áreas de convivência',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cadeirasId = DB::table('categorias')->insertGetId([
            'nome' => 'Cadeiras',
            'descricao' => 'Cadeiras ergonômicas, de design moderno e materiais nobres',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $camasId = DB::table('categorias')->insertGetId([
            'nome' => 'Camas',
            'descricao' => 'Camas confortáveis com designs sofisticados para o quarto',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $estantesId = DB::table('categorias')->insertGetId([
            'nome' => 'Estantes',
            'descricao' => 'Estantes para organização e decoração, unindo estilo e funcionalidade',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Subcategorias de Sofás
        $sofaRetratilId = DB::table('categorias')->insertGetId([
            'nome' => 'Sofá Retrátil',
            'descricao' => 'Sofás com sistema retrátil para conforto extra',
            'categoria_pai_id' => $sofasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sofaCantoId = DB::table('categorias')->insertGetId([
            'nome' => 'Sofá de Canto',
            'descricao' => 'Sofás em L para aproveitar melhor o espaço',
            'categoria_pai_id' => $sofasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Sub-subcategoria de Sofá de Canto
        DB::table('categorias')->insert([
            [
                'nome' => 'Sofá de Canto com Chaise',
                'descricao' => 'Versões com chaise para maior conforto',
                'categoria_pai_id' => $sofaCantoId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Sofá de Canto Modular',
                'descricao' => 'Composição personalizável por módulos',
                'categoria_pai_id' => $sofaCantoId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Subcategorias de Mesas
        $mesaJantarId = DB::table('categorias')->insertGetId([
            'nome' => 'Mesa de Jantar',
            'descricao' => 'Mesas para refeições em família',
            'categoria_pai_id' => $mesasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mesaEscritorioId = DB::table('categorias')->insertGetId([
            'nome' => 'Mesa de Escritório',
            'descricao' => 'Mesas funcionais para trabalho ou home office',
            'categoria_pai_id' => $mesasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('categorias')->insert([
            [
                'nome' => 'Mesa Redonda',
                'descricao' => 'Mesas com formato circular',
                'categoria_pai_id' => $mesaJantarId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Mesa Retangular',
                'descricao' => 'Mesas compridas para mais lugares',
                'categoria_pai_id' => $mesaJantarId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Subcategorias de Cadeiras
        $cadeiraEscritorioId = DB::table('categorias')->insertGetId([
            'nome' => 'Cadeira de Escritório',
            'descricao' => 'Conforto e ergonomia para longas horas de trabalho',
            'categoria_pai_id' => $cadeirasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cadeiraSalaId = DB::table('categorias')->insertGetId([
            'nome' => 'Cadeira de Sala',
            'descricao' => 'Para complementar mesas de jantar e salas de estar',
            'categoria_pai_id' => $cadeirasId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('categorias')->insert([
            [
                'nome' => 'Cadeira Gamer',
                'descricao' => 'Ideal para jogos e longas sessões no computador',
                'categoria_pai_id' => $cadeiraEscritorioId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Cadeira Ergonômica',
                'descricao' => 'Com regulagens e apoio lombar',
                'categoria_pai_id' => $cadeiraEscritorioId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Subcategorias de Camas
        DB::table('categorias')->insert([
            [
                'nome' => 'Cama de Casal',
                'descricao' => 'Ideal para dois adultos',
                'categoria_pai_id' => $camasId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Cama de Solteiro',
                'descricao' => 'Para crianças ou quartos individuais',
                'categoria_pai_id' => $camasId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Beliche',
                'descricao' => 'Otimiza espaço com duas camas verticais',
                'categoria_pai_id' => $camasId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Subcategorias de Estantes
        DB::table('categorias')->insert([
            [
                'nome' => 'Estante para Livros',
                'descricao' => 'Organização de livros com estilo',
                'categoria_pai_id' => $estantesId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Estante para Sala',
                'descricao' => 'Decoração e armazenamento para salas',
                'categoria_pai_id' => $estantesId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
