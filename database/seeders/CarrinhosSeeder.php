<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CarrinhosSeeder extends Seeder
{
    public function run(): void
    {
        $vendedores = DB::table('acesso_usuarios')
            ->whereIn('email', ['vendedor1@teste.com', 'vendedor2@teste.com', 'vendedor3@teste.com'])
            ->pluck('id')
            ->toArray();
        $clientes = DB::table('clientes')->pluck('id')->toArray();
        $parceiros = DB::table('parceiros')->pluck('id')->toArray();
        $variacoes = DB::table('produto_variacoes')->pluck('id')->toArray();
        $depositos = DB::table('depositos')->pluck('id')->toArray();
        $now = Carbon::now();

        $carrinhoItens = [];
        $clientesComRascunho = [];

        for ($i = 0; $i < 15; $i++) {
            $status = fake()->randomElement(['rascunho']);
            $idUsuario = fake()->randomElement($vendedores);
            $idCliente = fake()->optional()->randomElement($clientes);
            $idParceiro = fake()->optional()->randomElement($parceiros);

            // Limitar 1 carrinho rascunho por cliente
            if ($status === 'rascunho') {
                if (!$idCliente || in_array($idCliente, $clientesComRascunho)) {
                    continue;
                }
                $clientesComRascunho[] = $idCliente;
            }

            $carrinhoId = DB::table('carrinhos')->insertGetId([
                'status' => $status,
                'id_usuario' => $idUsuario,
                'id_cliente' => $idCliente,
                'id_parceiro' => $idParceiro,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $qtdItens = rand(1, 5);
            $variacoesSelecionadas = collect($variacoes)->shuffle()->take($qtdItens);

            foreach ($variacoesSelecionadas as $idVariacao) {
                $quantidade = rand(1, 3);
                $preco = fake()->randomFloat(2, 300, 3000);
                $subtotal = $quantidade * $preco;
                $idDeposito = fake()->randomElement($depositos);

                $carrinhoItens[] = [
                    'id_carrinho'     => $carrinhoId,
                    'id_variacao'     => $idVariacao,
                    'quantidade'      => $quantidade,
                    'preco_unitario'  => $preco,
                    'subtotal'        => $subtotal,
                    'id_deposito'     => $idDeposito,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        DB::table('carrinho_itens')->insert($carrinhoItens);
    }
}
