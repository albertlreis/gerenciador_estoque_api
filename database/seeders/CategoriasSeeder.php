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
        $rows = [
            ['id' => 1, 'nome' => 'Sofás', 'descricao' => 'Sofás modernos e confortáveis para ambientes residenciais e corporativos', 'categoria_pai_id' => null],
            ['id' => 2, 'nome' => 'Mesas', 'descricao' => 'Mesas para sala de jantar, escritório e áreas de convivência', 'categoria_pai_id' => null],
            ['id' => 3, 'nome' => 'Cadeiras', 'descricao' => 'Cadeiras ergonômicas, de design moderno e materiais nobres', 'categoria_pai_id' => null],
            ['id' => 4, 'nome' => 'Camas', 'descricao' => 'Camas confortáveis com designs sofisticados para o quarto', 'categoria_pai_id' => null],
            ['id' => 5, 'nome' => 'Estantes', 'descricao' => 'Estantes para organização e decoração, unindo estilo e funcionalidade', 'categoria_pai_id' => null],
            ['id' => 6, 'nome' => 'Jardim e Varanda', 'descricao' => 'Móveis resistentes e elegantes para áreas externas', 'categoria_pai_id' => null],
            ['id' => 7, 'nome' => 'Infantil', 'descricao' => 'Móveis funcionais e seguros para o quarto das crianças', 'categoria_pai_id' => null],
            ['id' => 8, 'nome' => 'Cozinha', 'descricao' => 'Móveis otimizados para praticidade no dia a dia', 'categoria_pai_id' => null],
            ['id' => 9, 'nome' => 'Sofá Retrátil', 'descricao' => 'Sofás com sistema retrátil para conforto extra', 'categoria_pai_id' => 1],
            ['id' => 10, 'nome' => 'Sofá de Canto', 'descricao' => 'Sofás em L para aproveitar melhor o espaço', 'categoria_pai_id' => 1],
            ['id' => 11, 'nome' => 'Sofá de Canto com Chaise', 'descricao' => 'Versões com chaise para maior conforto', 'categoria_pai_id' => 10],
            ['id' => 12, 'nome' => 'Sofá de Canto Modular', 'descricao' => 'Composição personalizável por módulos', 'categoria_pai_id' => 10],
            ['id' => 13, 'nome' => 'Sofá Cama', 'descricao' => 'Móveis multifuncionais ideais para espaços compactos', 'categoria_pai_id' => 1],
            ['id' => 14, 'nome' => 'Mesa de Jantar', 'descricao' => 'Mesas para refeições em família', 'categoria_pai_id' => 2],
            ['id' => 15, 'nome' => 'Mesa de Escritório', 'descricao' => 'Mesas funcionais para trabalho ou home office', 'categoria_pai_id' => 2],
            ['id' => 16, 'nome' => 'Mesa Redonda', 'descricao' => 'Mesas com formato circular', 'categoria_pai_id' => 14],
            ['id' => 17, 'nome' => 'Mesa Retangular', 'descricao' => 'Mesas compridas para mais lugares', 'categoria_pai_id' => 14],
            ['id' => 18, 'nome' => 'Escrivaninha', 'descricao' => 'Mesas compactas para estudos ou trabalho em casa', 'categoria_pai_id' => 2],
            ['id' => 19, 'nome' => 'Cadeira de Escritório', 'descricao' => 'Conforto e ergonomia para longas horas de trabalho', 'categoria_pai_id' => 3],
            ['id' => 20, 'nome' => 'Cadeira de Sala', 'descricao' => 'Para complementar mesas de jantar e salas de estar', 'categoria_pai_id' => 3],
            ['id' => 21, 'nome' => 'Cadeira Gamer', 'descricao' => 'Ideal para jogos e longas sessões no computador', 'categoria_pai_id' => 19],
            ['id' => 22, 'nome' => 'Cadeira Ergonômica', 'descricao' => 'Com regulagens e apoio lombar', 'categoria_pai_id' => 19],
            ['id' => 23, 'nome' => 'Poltrona', 'descricao' => 'Assento individual para descanso e leitura', 'categoria_pai_id' => 3],
            ['id' => 24, 'nome' => 'Cama de Casal', 'descricao' => 'Ideal para dois adultos', 'categoria_pai_id' => 4],
            ['id' => 25, 'nome' => 'Cama de Solteiro', 'descricao' => 'Para crianças ou quartos individuais', 'categoria_pai_id' => 4],
            ['id' => 26, 'nome' => 'Beliche', 'descricao' => 'Otimiza espaço com duas camas verticais', 'categoria_pai_id' => 4],
            ['id' => 27, 'nome' => 'Cama Infantil', 'descricao' => 'Modelos temáticos e adaptados para crianças', 'categoria_pai_id' => 7],
            ['id' => 28, 'nome' => 'Estante para Livros', 'descricao' => 'Organização de livros com estilo', 'categoria_pai_id' => 5],
            ['id' => 29, 'nome' => 'Estante para Sala', 'descricao' => 'Decoração e armazenamento para salas', 'categoria_pai_id' => 5],
            ['id' => 30, 'nome' => 'Estante Modular', 'descricao' => 'Permite composições personalizadas e versáteis', 'categoria_pai_id' => 5],
            ['id' => 31, 'nome' => 'Aparador', 'descricao' => 'Peça decorativa para halls e salas', 'categoria_pai_id' => 5],
            ['id' => 32, 'nome' => 'Nichos Decorativos', 'descricao' => 'Estrutura leve para exposição de objetos', 'categoria_pai_id' => 5],
            ['id' => 33, 'nome' => 'Balcão de Cozinha', 'descricao' => 'Móvel de apoio para preparação de refeições', 'categoria_pai_id' => 8],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('categorias')->upsert(
            $rows,
            ['id'],
            ['nome', 'descricao', 'categoria_pai_id', 'updated_at']
        );
    }
}
