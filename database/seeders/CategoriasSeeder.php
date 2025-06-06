<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CategoriasSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // === CATEGORIAS DE NÍVEL RAIZ ===
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

        $jardimVarandaId = DB::table('categorias')->insertGetId([
            'nome' => 'Jardim e Varanda',
            'descricao' => 'Móveis resistentes e elegantes para áreas externas',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $infantilId = DB::table('categorias')->insertGetId([
            'nome' => 'Infantil',
            'descricao' => 'Móveis funcionais e seguros para o quarto das crianças',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cozinhaId = DB::table('categorias')->insertGetId([
            'nome' => 'Cozinha',
            'descricao' => 'Móveis otimizados para praticidade no dia a dia',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // === SUBCATEGORIAS DE SOFÁS ===
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
            [
                'nome' => 'Sofá Cama',
                'descricao' => 'Móveis multifuncionais ideais para espaços compactos',
                'categoria_pai_id' => $sofasId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // === SUBCATEGORIAS DE MESAS ===
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
            [
                'nome' => 'Escrivaninha',
                'descricao' => 'Mesas compactas para estudos ou trabalho em casa',
                'categoria_pai_id' => $mesasId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // === SUBCATEGORIAS DE CADEIRAS ===
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
            [
                'nome' => 'Poltrona',
                'descricao' => 'Assento individual para descanso e leitura',
                'categoria_pai_id' => $cadeirasId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // === SUBCATEGORIAS DE CAMAS ===
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
            [
                'nome' => 'Cama Infantil',
                'descricao' => 'Modelos temáticos e adaptados para crianças',
                'categoria_pai_id' => $infantilId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // === SUBCATEGORIAS DE ESTANTES ===
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
            [
                'nome' => 'Estante Modular',
                'descricao' => 'Permite composições personalizadas e versáteis',
                'categoria_pai_id' => $estantesId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Aparador',
                'descricao' => 'Peça decorativa para halls e salas',
                'categoria_pai_id' => $estantesId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Nichos Decorativos',
                'descricao' => 'Estrutura leve para exposição de objetos',
                'categoria_pai_id' => $estantesId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // === SUBCATEGORIAS DE COZINHA ===
        DB::table('categorias')->insert([
            [
                'nome' => 'Balcão de Cozinha',
                'descricao' => 'Móvel de apoio para preparação de refeições',
                'categoria_pai_id' => $cozinhaId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
