<?php

namespace Database\Seeders;

use App\Models\CategoriaFinanceira;
use Illuminate\Database\Seeder;

class CategoriasFinanceirasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // === RECEITAS ===
        $receitas = CategoriaFinanceira::create([
            'nome'   => 'Receitas',
            'slug'   => 'receitas',
            'tipo'   => 'receita',
            'ordem'  => 1,
            'ativo'  => true,
            'padrao' => true,
        ]);

        CategoriaFinanceira::insert([
            [
                'nome' => 'Vendas',
                'slug' => 'vendas',
                'tipo' => 'receita',
                'categoria_pai_id' => $receitas->id,
                'ordem' => 1,
                'ativo' => true,
                'padrao' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Serviços',
                'slug' => 'servicos',
                'tipo' => 'receita',
                'categoria_pai_id' => $receitas->id,
                'ordem' => 2,
                'ativo' => true,
                'padrao' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // === DESPESAS ===
        $despesas = CategoriaFinanceira::create([
            'nome'   => 'Despesas',
            'slug'   => 'despesas',
            'tipo'   => 'despesa',
            'ordem'  => 2,
            'ativo'  => true,
            'padrao' => true,
        ]);

        CategoriaFinanceira::insert([
            [
                'nome' => 'Fornecedores',
                'slug' => 'fornecedores',
                'tipo' => 'despesa',
                'categoria_pai_id' => $despesas->id,
                'ordem' => 1,
                'ativo' => true,
                'padrao' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Impostos',
                'slug' => 'impostos',
                'tipo' => 'despesa',
                'categoria_pai_id' => $despesas->id,
                'ordem' => 2,
                'ativo' => true,
                'padrao' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Folha de Pagamento',
                'slug' => 'folha-pagamento',
                'tipo' => 'despesa',
                'categoria_pai_id' => $despesas->id,
                'ordem' => 3,
                'ativo' => true,
                'padrao' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nome' => 'Outros',
                'slug' => 'outros-despesas',
                'tipo' => 'despesa',
                'categoria_pai_id' => $despesas->id,
                'ordem' => 99,
                'ativo' => true,
                'padrao' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
