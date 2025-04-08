<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CreateFakeDataForEstoque extends Migration
{
    public function up()
    {
        $now = Carbon::now();

        // Inserir Categorias
        DB::table('categorias')->insert([
            [
                'nome'       => 'Sofás',
                'descricao'  => 'Sofás modernos e confortáveis para ambientes residenciais e corporativos',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Mesas',
                'descricao'  => 'Mesas para sala de jantar, escritório e áreas de convivência',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Cadeiras',
                'descricao'  => 'Cadeiras ergonômicas, de design moderno e materiais nobres',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Camas',
                'descricao'  => 'Camas confortáveis com designs sofisticados para o quarto',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Estantes',
                'descricao'  => 'Estantes para organização e decoração, unindo estilo e funcionalidade',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Inserir Produtos (15 registros)
        $produtos = [
            [
                'nome'         => 'Sofá Retrátil',
                'descricao'    => 'Sofá retrátil com dois assentos, ideal para ambientes pequenos.',
                'id_categoria' => 1,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Sofá Seccional',
                'descricao'    => 'Sofá seccional para ambientes amplos e confortáveis.',
                'id_categoria' => 1,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Mesa de Jantar Elegance',
                'descricao'    => 'Mesa de jantar elegante para refeições em família.',
                'id_categoria' => 2,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Mesa de Centro Moderna',
                'descricao'    => 'Mesa de centro com design moderno e linhas minimalistas.',
                'id_categoria' => 2,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Cadeira Ergonômica Office',
                'descricao'    => 'Cadeira ergonômica para longas horas de trabalho no escritório.',
                'id_categoria' => 3,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Cadeira de Madeira Rústica',
                'descricao'    => 'Cadeira com acabamento rústico que realça o charme natural.',
                'id_categoria' => 3,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Cama Box Queen',
                'descricao'    => 'Cama box queen size com estrutura robusta e design moderno.',
                'id_categoria' => 4,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Cama King Size Luxo',
                'descricao'    => 'Cama king size que une luxo e conforto para um sono de qualidade.',
                'id_categoria' => 4,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Estante Modular',
                'descricao'    => 'Estante modular, que se adapta a diversos espaços e necessidades.',
                'id_categoria' => 5,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Estante Vertical',
                'descricao'    => 'Estante vertical que otimiza ambientes com pouco espaço.',
                'id_categoria' => 5,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Sofá-cama Compacto',
                'descricao'    => 'Sofá-cama compacto ideal para apartamentos e estúdios.',
                'id_categoria' => 1,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Mesa Extensível',
                'descricao'    => 'Mesa extensível para acomodar mais pessoas em ocasiões especiais.',
                'id_categoria' => 2,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Cadeira Gamer',
                'descricao'    => 'Cadeira gamer com design arrojado e ajuste ergonômico.',
                'id_categoria' => 3,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Cama Simples',
                'descricao'    => 'Cama simples e funcional para quartos minimalistas.',
                'id_categoria' => 4,
                'ativo'        => true,
            ],
            [
                'nome'         => 'Estante com Vidro',
                'descricao'    => 'Estante com prateleiras de vidro, trazendo um toque de modernidade.',
                'id_categoria' => 5,
                'ativo'        => true,
            ],
        ];

        foreach ($produtos as $produto) {
            $produto['created_at'] = $now;
            $produto['updated_at'] = $now;
            DB::table('produtos')->insert($produto);
        }

        // Inserir variações para os produtos (1 variação para cada produto)
        $variacoes = [];
        foreach (range(1, 15) as $produtoId) {
            $catId = $produtos[$produtoId - 1]['id_categoria'];
            $sku = "SKU-{$produtoId}-01";
            $nome = $produtos[$produtoId - 1]['nome'] . " - Variação Padrão";

            // Definir preços, custos e dimensões baseados na categoria
            switch ($catId) {
                case 1: // Sofás
                    $preco = 2500.00;
                    $custo = 1800.00;
                    $peso = 80;
                    $altura = 90;
                    $largura = 200;
                    $profundidade = 100;
                    break;
                case 2: // Mesas
                    $preco = 1200.00;
                    $custo = 800.00;
                    $peso = 30;
                    $altura = 75;
                    $largura = 150;
                    $profundidade = 80;
                    break;
                case 3: // Cadeiras
                    $preco = 600.00;
                    $custo = 400.00;
                    $peso = 10;
                    $altura = 100;
                    $largura = 50;
                    $profundidade = 50;
                    break;
                case 4: // Camas
                    $preco = 2000.00;
                    $custo = 1500.00;
                    $peso = 70;
                    $altura = 50;
                    $largura = 210;
                    $profundidade = 180;
                    break;
                case 5: // Estantes
                    $preco = 1000.00;
                    $custo = 700.00;
                    $peso = 40;
                    $altura = 150;
                    $largura = 100;
                    $profundidade = 40;
                    break;
                default:
                    $preco = 1000.00;
                    $custo = 800.00;
                    $peso = 0;
                    $altura = 0;
                    $largura = 0;
                    $profundidade = 0;
                    break;
            }

            $variacoes[] = [
                'id_produto'   => $produtoId,
                'sku'          => $sku,
                'nome'         => $nome,
                'preco'        => $preco,
                'custo'        => $custo,
                'peso'         => $peso,
                'altura'       => $altura,
                'largura'      => $largura,
                'profundidade' => $profundidade,
                'codigo_barras'=> '123456789012',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        DB::table('produto_variacoes')->insert($variacoes);

        // Inserir Clientes (4 registros)
        DB::table('clientes')->insert([
            [
                'nome'       => 'João Silva',
                'documento'  => '123456789',
                'email'      => 'joao.silva@example.com',
                'telefone'   => '1112345678',
                'endereco'   => 'Rua A, 123',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Maria Oliveira',
                'documento'  => '987654321',
                'email'      => 'maria.oliveira@example.com',
                'telefone'   => '2223456789',
                'endereco'   => 'Av. B, 456',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Pedro Santos',
                'documento'  => '456123789',
                'email'      => 'pedro.santos@example.com',
                'telefone'   => '3334567890',
                'endereco'   => 'Rua C, 789',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome'       => 'Ana Costa',
                'documento'  => '789123456',
                'email'      => 'ana.costa@example.com',
                'telefone'   => '4445678901',
                'endereco'   => 'Av. D, 321',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Inserir Pedidos (8 registros)
        DB::table('pedidos')->insert([
            [
                'id_cliente'   => 1,
                'data_pedido'  => $now->copy()->subDays(10),
                'status'       => 'novo',
                'observacoes'  => 'Primeiro pedido',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 2,
                'data_pedido'  => $now->copy()->subDays(8),
                'status'       => 'finalizado',
                'observacoes'  => 'Entrega realizada',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 3,
                'data_pedido'  => $now->copy()->subDays(7),
                'status'       => 'pendente',
                'observacoes'  => 'Aguardando pagamento',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 4,
                'data_pedido'  => $now->copy()->subDays(5),
                'status'       => 'novo',
                'observacoes'  => 'Pedido em processamento',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 1,
                'data_pedido'  => $now->copy()->subDays(3),
                'status'       => 'finalizado',
                'observacoes'  => 'Pedido entregue',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 2,
                'data_pedido'  => $now->copy()->subDays(2),
                'status'       => 'cancelado',
                'observacoes'  => 'Cliente cancelou',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 3,
                'data_pedido'  => $now->copy()->subDays(1),
                'status'       => 'novo',
                'observacoes'  => 'Pedido recebido',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'id_cliente'   => 4,
                'data_pedido'  => $now,
                'status'       => 'pendente',
                'observacoes'  => 'Pagamento pendente',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ]);

        // Inserir Itens dos Pedidos na tabela pedido_itens
        // Exemplo de dados:
        DB::table('pedido_itens')->insert([
            // Pedido 1: 2 itens
            [
                'id_pedido'      => 1,
                'id_variacao'    => 1, // Sofá Retrátil
                'quantidade'     => 2,
                'preco_unitario' => 2500.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 1,
                'id_variacao'    => 5, // Cadeira Ergonômica Office
                'quantidade'     => 1,
                'preco_unitario' => 600.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 2: 1 item
            [
                'id_pedido'      => 2,
                'id_variacao'    => 3, // Mesa de Jantar Elegance
                'quantidade'     => 4,
                'preco_unitario' => 1200.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 3: 1 item
            [
                'id_pedido'      => 3,
                'id_variacao'    => 7, // Cama Box Queen
                'quantidade'     => 1,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 4: 2 itens
            [
                'id_pedido'      => 4,
                'id_variacao'    => 8, // Cama King Size Luxo
                'quantidade'     => 2,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 4,
                'id_variacao'    => 2, // Sofá Seccional
                'quantidade'     => 1,
                'preco_unitario' => 2500.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 5: 2 itens
            [
                'id_pedido'      => 5,
                'id_variacao'    => 9, // Estante Modular
                'quantidade'     => 3,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 5,
                'id_variacao'    => 12, // Mesa Extensível
                'quantidade'     => 1,
                'preco_unitario' => 1200.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 6: 1 item
            [
                'id_pedido'      => 6,
                'id_variacao'    => 6, // Cadeira de Madeira Rústica
                'quantidade'     => 2,
                'preco_unitario' => 600.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 7: 2 itens
            [
                'id_pedido'      => 7,
                'id_variacao'    => 10, // Estante Vertical
                'quantidade'     => 2,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'id_pedido'      => 7,
                'id_variacao'    => 14, // Cama Simples
                'quantidade'     => 1,
                'preco_unitario' => 2000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            // Pedido 8: 1 item
            [
                'id_pedido'      => 8,
                'id_variacao'    => 15, // Estante com Vidro
                'quantidade'     => 1,
                'preco_unitario' => 1000.00,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ]);
    }

    public function down()
    {
        // Remover os dados inseridos
        DB::table('pedido_itens')->truncate();
        DB::table('pedidos')->truncate();
        DB::table('clientes')->truncate();
        DB::table('produto_variacoes')->truncate();
        DB::table('produtos')->truncate();
        DB::table('categorias')->truncate();
    }
}
