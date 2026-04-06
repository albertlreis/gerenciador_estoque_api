<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupCatalogoE2EFixturesCommand extends Command
{
    protected $signature = 'sierra:e2e-catalogo-fixtures';

    protected $description = 'Prepara massa deterministica para testes E2E do catalogo, carrinho e finalizacao.';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Este comando e permitido apenas em ambiente local/testing.');
            return self::FAILURE;
        }

        DB::transaction(function () {
            $this->limparFluxoOperacionalE2E();
            $contexto = $this->upsertContextoBase();
            $this->upsertProdutosCatalogo($contexto);
        });

        $this->info('Fixtures E2E do catalogo prontas.');
        $this->line('Cliente existente: cliente.catalogo.e2e@local.test');
        $this->line('Produtos: E2E-SOFA-AZUL / E2E-MESA-VERDE / E2E-POLTRONA-SEM');

        return self::SUCCESS;
    }

    private function limparFluxoOperacionalE2E(): void
    {
        $clienteIds = DB::table('clientes')
            ->where(function ($query) {
                $query->where('email', 'cliente.catalogo.e2e@local.test')
                    ->orWhere('email', 'like', 'cliente.novo.%@local.test');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($clienteIds !== []) {
            $pedidoIds = DB::table('pedidos')
                ->whereIn('id_cliente', $clienteIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($pedidoIds !== []) {
                $contaReceberIds = DB::table('contas_receber')
                    ->whereIn('pedido_id', $pedidoIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($contaReceberIds !== []) {
                    DB::table('contas_receber_pagamentos')->whereIn('conta_receber_id', $contaReceberIds)->delete();
                    DB::table('contas_receber')->whereIn('id', $contaReceberIds)->delete();
                }

                $consignacaoIds = DB::table('consignacoes')
                    ->whereIn('pedido_id', $pedidoIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($consignacaoIds !== []) {
                    DB::table('consignacao_devolucoes')->whereIn('consignacao_id', $consignacaoIds)->delete();
                    DB::table('consignacoes')->whereIn('id', $consignacaoIds)->delete();
                }

                $pedidoItemIds = DB::table('pedido_itens')
                    ->whereIn('id_pedido', $pedidoIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                DB::table('estoque_movimentacoes')->whereIn('pedido_id', $pedidoIds)->delete();
                DB::table('estoque_reservas')->whereIn('pedido_id', $pedidoIds)->delete();
                DB::table('pedido_status_historicos')->whereIn('pedido_id', $pedidoIds)->delete();

                if ($pedidoItemIds !== []) {
                    DB::table('pedido_itens')->whereIn('id', $pedidoItemIds)->delete();
                }

                DB::table('pedidos')->whereIn('id', $pedidoIds)->delete();
            }

            $carrinhoIds = DB::table('carrinhos')
                ->whereIn('id_cliente', $clienteIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($carrinhoIds !== []) {
                DB::table('carrinho_itens')->whereIn('id_carrinho', $carrinhoIds)->delete();
                DB::table('carrinhos')->whereIn('id', $carrinhoIds)->delete();
            }

            DB::table('clientes')->whereIn('id', $clienteIds)->delete();
        }
    }

    private function upsertContextoBase(): array
    {
        $now = now();

        DB::table('categorias')->updateOrInsert(
            ['nome' => 'Categoria E2E Catalogo'],
            ['descricao' => 'Categoria tecnica para testes E2E', 'updated_at' => $now, 'created_at' => $now]
        );

        $categoria = DB::table('categorias')->where('nome', 'Categoria E2E Catalogo')->first();

        DB::table('fornecedores')->updateOrInsert(
            ['nome' => 'Fornecedor E2E Catalogo'],
            [
                'status' => 1,
                'cnpj' => null,
                'email' => 'fornecedor.catalogo.e2e@test.com',
                'telefone' => null,
                'endereco' => null,
                'observacoes' => 'Fixture E2E',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $fornecedor = DB::table('fornecedores')->where('nome', 'Fornecedor E2E Catalogo')->first();

        $depositoCentro = $this->upsertDeposito('Deposito E2E Centro');
        $depositoSecundario = $this->upsertDeposito('Deposito E2E Secundario');

        $cliente = DB::table('clientes')->where('email', 'cliente.catalogo.e2e@local.test')->first();
        if (!$cliente) {
            $clienteId = DB::table('clientes')->insertGetId([
                'nome' => 'Cliente Catalogo E2E',
                'nome_fantasia' => null,
                'documento' => null,
                'inscricao_estadual' => null,
                'email' => 'cliente.catalogo.e2e@local.test',
                'telefone' => '91999999999',
                'whatsapp' => null,
                'tipo' => 'pf',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $cliente = DB::table('clientes')->where('id', $clienteId)->first();
        } else {
            DB::table('clientes')->where('id', $cliente->id)->update([
                'nome' => 'Cliente Catalogo E2E',
                'tipo' => 'pf',
                'updated_at' => $now,
            ]);
        }

        return [
            'categoria_id' => (int) $categoria->id,
            'fornecedor_id' => (int) $fornecedor->id,
            'deposito_centro_id' => $depositoCentro,
            'deposito_secundario_id' => $depositoSecundario,
            'cliente_id' => (int) $cliente->id,
        ];
    }

    private function upsertDeposito(string $nome): int
    {
        $now = now();
        $deposito = DB::table('depositos')->where('nome', $nome)->first();

        if ($deposito) {
            DB::table('depositos')->where('id', $deposito->id)->update([
                'updated_at' => $now,
            ]);

            return (int) $deposito->id;
        }

        return (int) DB::table('depositos')->insertGetId([
            'nome' => $nome,
            'endereco' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertProdutosCatalogo(array $contexto): void
    {
        $produtos = [
            [
                'codigo_produto' => 'E2E-CAT-SOFA-AZUL',
                'nome' => 'Sofa E2E Azul',
                'referencia' => 'E2E-SOFA-AZUL',
                'sku_interno' => 'E2E-SKU-SOFA-AZUL',
                'preco' => 1999.90,
                'custo' => 1200,
                'estoques' => [
                    ['deposito_id' => $contexto['deposito_centro_id'], 'quantidade' => 5],
                    ['deposito_id' => $contexto['deposito_secundario_id'], 'quantidade' => 2],
                ],
                'atributos' => [
                    ['atributo' => 'cor', 'valor' => 'Azul'],
                    ['atributo' => 'material', 'valor' => 'Veludo'],
                ],
            ],
            [
                'codigo_produto' => 'E2E-CAT-MESA-VERDE',
                'nome' => 'Mesa E2E Verde',
                'referencia' => 'E2E-MESA-VERDE',
                'sku_interno' => 'E2E-SKU-MESA-VERDE',
                'preco' => 899.90,
                'custo' => 450,
                'estoques' => [
                    ['deposito_id' => $contexto['deposito_centro_id'], 'quantidade' => 3],
                ],
                'atributos' => [
                    ['atributo' => 'cor', 'valor' => 'Verde'],
                    ['atributo' => 'material', 'valor' => 'Madeira'],
                ],
            ],
            [
                'codigo_produto' => 'E2E-CAT-POLTRONA-SEM',
                'nome' => 'Poltrona E2E Sem Estoque',
                'referencia' => 'E2E-POLTRONA-SEM',
                'sku_interno' => 'E2E-SKU-POLTRONA-SEM',
                'preco' => 1299.90,
                'custo' => 700,
                'estoques' => [
                    ['deposito_id' => $contexto['deposito_centro_id'], 'quantidade' => 0],
                ],
                'atributos' => [
                    ['atributo' => 'cor', 'valor' => 'Bege'],
                    ['atributo' => 'material', 'valor' => 'Linho'],
                ],
            ],
        ];

        foreach ($produtos as $produtoData) {
            $this->upsertProduto($contexto, $produtoData);
        }
    }

    private function upsertProduto(array $contexto, array $produtoData): void
    {
        $now = now();

        $produto = DB::table('produtos')
            ->where('codigo_produto', $produtoData['codigo_produto'])
            ->first();

        if ($produto) {
            DB::table('produtos')->where('id', $produto->id)->update([
                'nome' => $produtoData['nome'],
                'descricao' => 'Produto tecnico para testes E2E do catalogo.',
                'id_categoria' => $contexto['categoria_id'],
                'id_fornecedor' => $contexto['fornecedor_id'],
                'altura' => 90,
                'largura' => 120,
                'profundidade' => 80,
                'peso' => 25,
                'ativo' => 1,
                'estoque_minimo' => 1,
                'updated_at' => $now,
            ]);
            $produtoId = (int) $produto->id;
        } else {
            $produtoId = (int) DB::table('produtos')->insertGetId([
                'nome' => $produtoData['nome'],
                'descricao' => 'Produto tecnico para testes E2E do catalogo.',
                'id_categoria' => $contexto['categoria_id'],
                'id_fornecedor' => $contexto['fornecedor_id'],
                'codigo_produto' => $produtoData['codigo_produto'],
                'altura' => 90,
                'largura' => 120,
                'profundidade' => 80,
                'peso' => 25,
                'ativo' => 1,
                'estoque_minimo' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $variacao = DB::table('produto_variacoes')
            ->where('produto_id', $produtoId)
            ->where('referencia', $produtoData['referencia'])
            ->first();

        if ($variacao) {
            DB::table('produto_variacoes')->where('id', $variacao->id)->update([
                'nome' => $produtoData['nome'] . ' Variacao',
                'sku_interno' => $produtoData['sku_interno'],
                'preco' => $produtoData['preco'],
                'custo' => $produtoData['custo'],
                'updated_at' => $now,
            ]);
            $variacaoId = (int) $variacao->id;
        } else {
            $variacaoId = (int) DB::table('produto_variacoes')->insertGetId([
                'produto_id' => $produtoId,
                'referencia' => $produtoData['referencia'],
                'sku_interno' => $produtoData['sku_interno'],
                'nome' => $produtoData['nome'] . ' Variacao',
                'preco' => $produtoData['preco'],
                'custo' => $produtoData['custo'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('produto_variacao_atributos')->where('id_variacao', $variacaoId)->delete();
        foreach ($produtoData['atributos'] as $atributo) {
            DB::table('produto_variacao_atributos')->insert([
                'id_variacao' => $variacaoId,
                'atributo' => $atributo['atributo'],
                'valor' => $atributo['valor'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($produtoData['estoques'] as $estoque) {
            DB::table('estoque')->updateOrInsert(
                [
                    'id_variacao' => $variacaoId,
                    'id_deposito' => $estoque['deposito_id'],
                ],
                [
                    'quantidade' => $estoque['quantidade'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
