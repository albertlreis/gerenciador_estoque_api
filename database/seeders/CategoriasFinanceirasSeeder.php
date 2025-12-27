<?php

namespace Database\Seeders;

use App\Models\CategoriaFinanceira;
use Illuminate\Database\Seeder;

class CategoriasFinanceirasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $receitas = CategoriaFinanceira::updateOrCreate(
            ['slug' => 'receitas'],
            ['nome' => 'Receitas','tipo' => 'receita','ordem' => 1,'ativo' => true,'padrao' => true,'categoria_pai_id' => null,'meta_json' => null]
        );

        $despesas = CategoriaFinanceira::updateOrCreate(
            ['slug' => 'despesas'],
            ['nome' => 'Despesas','tipo' => 'despesa','ordem' => 2,'ativo' => true,'padrao' => true,'categoria_pai_id' => null,'meta_json' => null]
        );

        $children = [
            // RECEITAS
            ['nome' => 'Vendas',    'slug' => 'vendas',    'tipo' => 'receita', 'pai' => $receitas->id, 'ordem' => 1],
            ['nome' => 'Serviços',  'slug' => 'servicos',  'tipo' => 'receita', 'pai' => $receitas->id, 'ordem' => 2],
            ['nome' => 'Juros',     'slug' => 'juros-receita', 'tipo' => 'receita', 'pai' => $receitas->id, 'ordem' => 3],
            ['nome' => 'Outros',    'slug' => 'outros-receitas','tipo' => 'receita','pai' => $receitas->id,'ordem' => 99,'padrao' => true],

            // DESPESAS (inclui o que suas seeds tentam achar por slug)
            ['nome' => 'Fornecedores',       'slug' => 'fornecedores',    'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 1],
            ['nome' => 'Impostos',           'slug' => 'impostos',        'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 2],
            ['nome' => 'Folha de Pagamento', 'slug' => 'folha-pagamento', 'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 3],

            ['nome' => 'Aluguel',            'slug' => 'aluguel',   'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 10],
            ['nome' => 'Internet',           'slug' => 'internet',  'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 11],
            ['nome' => 'Energia',            'slug' => 'energia',   'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 12],
            ['nome' => 'Software',           'slug' => 'software',  'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 13],
            ['nome' => 'Seguro',             'slug' => 'seguro',    'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 14],

            ['nome' => 'Outros', 'slug' => 'outros-despesas', 'tipo' => 'despesa', 'pai' => $despesas->id, 'ordem' => 99, 'padrao' => true],
        ];

        foreach ($children as $c) {
            CategoriaFinanceira::updateOrCreate(
                ['slug' => $c['slug']],
                [
                    'nome' => $c['nome'],
                    'tipo' => $c['tipo'],
                    'categoria_pai_id' => $c['pai'],
                    'ordem' => $c['ordem'],
                    'ativo' => true,
                    'padrao' => $c['padrao'] ?? false,
                    'meta_json' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
