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
            ['nome' => 'Sofás', 'descricao' => 'Sofás modernos e confortáveis para ambientes residenciais e corporativos', 'categoria_pai' => null],
            ['nome' => 'Mesas', 'descricao' => 'Mesas para sala de jantar, escritório e áreas de convivência', 'categoria_pai' => null],
            ['nome' => 'Cadeiras', 'descricao' => 'Cadeiras ergonômicas, de design moderno e materiais nobres', 'categoria_pai' => null],
            ['nome' => 'Camas', 'descricao' => 'Camas confortáveis com designs sofisticados para o quarto', 'categoria_pai' => null],
            ['nome' => 'Estantes', 'descricao' => 'Estantes para organização e decoração, unindo estilo e funcionalidade', 'categoria_pai' => null],
            ['nome' => 'Jardim e Varanda', 'descricao' => 'Móveis resistentes e elegantes para áreas externas', 'categoria_pai' => null],
            ['nome' => 'Infantil', 'descricao' => 'Móveis funcionais e seguros para o quarto das crianças', 'categoria_pai' => null],
            ['nome' => 'Cozinha', 'descricao' => 'Móveis otimizados para praticidade no dia a dia', 'categoria_pai' => null],
            ['nome' => 'Sofá Retrátil', 'descricao' => 'Sofás com sistema retrátil para conforto extra', 'categoria_pai' => 'Sofás'],
            ['nome' => 'Sofá de Canto', 'descricao' => 'Sofás em L para aproveitar melhor o espaço', 'categoria_pai' => 'Sofás'],
            ['nome' => 'Sofá de Canto com Chaise', 'descricao' => 'Versões com chaise para maior conforto', 'categoria_pai' => 'Sofá de Canto'],
            ['nome' => 'Sofá de Canto Modular', 'descricao' => 'Composição personalizável por módulos', 'categoria_pai' => 'Sofá de Canto'],
            ['nome' => 'Sofá Cama', 'descricao' => 'Móveis multifuncionais ideais para espaços compactos', 'categoria_pai' => 'Sofás'],
            ['nome' => 'Mesa de Jantar', 'descricao' => 'Mesas para refeições em família', 'categoria_pai' => 'Mesas'],
            ['nome' => 'Mesa de Escritório', 'descricao' => 'Mesas funcionais para trabalho ou home office', 'categoria_pai' => 'Mesas'],
            ['nome' => 'Mesa Redonda', 'descricao' => 'Mesas com formato circular', 'categoria_pai' => 'Mesa de Jantar'],
            ['nome' => 'Mesa Retangular', 'descricao' => 'Mesas compridas para mais lugares', 'categoria_pai' => 'Mesa de Jantar'],
            ['nome' => 'Escrivaninha', 'descricao' => 'Mesas compactas para estudos ou trabalho em casa', 'categoria_pai' => 'Mesas'],
            ['nome' => 'Cadeira de Escritório', 'descricao' => 'Conforto e ergonomia para longas horas de trabalho', 'categoria_pai' => 'Cadeiras'],
            ['nome' => 'Cadeira de Sala', 'descricao' => 'Para complementar mesas de jantar e salas de estar', 'categoria_pai' => 'Cadeiras'],
            ['nome' => 'Cadeira Gamer', 'descricao' => 'Ideal para jogos e longas sessões no computador', 'categoria_pai' => 'Cadeira de Escritório'],
            ['nome' => 'Cadeira Ergonômica', 'descricao' => 'Com regulagens e apoio lombar', 'categoria_pai' => 'Cadeira de Escritório'],
            ['nome' => 'Poltrona', 'descricao' => 'Assento individual para descanso e leitura', 'categoria_pai' => 'Cadeiras'],
            ['nome' => 'Cama de Casal', 'descricao' => 'Ideal para dois adultos', 'categoria_pai' => 'Camas'],
            ['nome' => 'Cama de Solteiro', 'descricao' => 'Para crianças ou quartos individuais', 'categoria_pai' => 'Camas'],
            ['nome' => 'Beliche', 'descricao' => 'Otimiza espaço com duas camas verticais', 'categoria_pai' => 'Camas'],
            ['nome' => 'Cama Infantil', 'descricao' => 'Modelos temáticos e adaptados para crianças', 'categoria_pai' => 'Infantil'],
            ['nome' => 'Estante para Livros', 'descricao' => 'Organização de livros com estilo', 'categoria_pai' => 'Estantes'],
            ['nome' => 'Estante para Sala', 'descricao' => 'Decoração e armazenamento para salas', 'categoria_pai' => 'Estantes'],
            ['nome' => 'Estante Modular', 'descricao' => 'Permite composições personalizadas e versáteis', 'categoria_pai' => 'Estantes'],
            ['nome' => 'Aparador', 'descricao' => 'Peça decorativa para halls e salas', 'categoria_pai' => 'Estantes'],
            ['nome' => 'Nichos Decorativos', 'descricao' => 'Estrutura leve para exposição de objetos', 'categoria_pai' => 'Estantes'],
            ['nome' => 'Balcão de Cozinha', 'descricao' => 'Móvel de apoio para preparação de refeições', 'categoria_pai' => 'Cozinha'],
        ];

        foreach ($rows as $row) {
            DB::table('categorias')->updateOrInsert(
                ['nome' => $row['nome']],
                [
                    'descricao' => $row['descricao'],
                    'categoria_pai_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $ids = DB::table('categorias')->pluck('id', 'nome');

        foreach ($rows as $row) {
            DB::table('categorias')
                ->where('nome', $row['nome'])
                ->update([
                    'categoria_pai_id' => $row['categoria_pai'] ? ($ids[$row['categoria_pai']] ?? null) : null,
                    'updated_at' => $now,
                ]);
        }
    }
}
