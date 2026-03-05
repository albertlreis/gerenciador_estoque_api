<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\ClienteEndereco;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ClientesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $clientes = [
            ['nome' => 'João Silva', 'nome_fantasia' => null, 'documento' => '11111111111', 'inscricao_estadual' => null, 'email' => 'joao.silva@cliente.local', 'telefone' => '91920000001', 'whatsapp' => '91920000001', 'tipo' => 'pf'],
            ['nome' => 'Maria Souza', 'nome_fantasia' => null, 'documento' => '22222222222', 'inscricao_estadual' => null, 'email' => 'maria.souza@cliente.local', 'telefone' => '91920000002', 'whatsapp' => '91920000002', 'tipo' => 'pf'],
            ['nome' => 'Empresa Exemplo LTDA', 'nome_fantasia' => 'Empresa Exemplo', 'documento' => '12345678000199', 'inscricao_estadual' => '123456789', 'email' => 'financeiro@empresaexemplo.local', 'telefone' => '91920000003', 'whatsapp' => '91920000003', 'tipo' => 'pj'],
        ];

        foreach ($clientes as $clienteData) {
            /** @var Cliente $cliente */
            $cliente = Cliente::query()->updateOrCreate(
                ['documento' => $clienteData['documento']],
                array_merge($clienteData, ['created_at' => $now, 'updated_at' => $now])
            );

            ClienteEndereco::query()->updateOrCreate([
                'cliente_id'  => $cliente->id,
                'fingerprint' => hash('sha256', $clienteData['documento'] . '|principal'),
            ], [
                'cep'         => '66000000',
                'endereco'    => 'Endereço principal',
                'numero'      => 'S/N',
                'complemento' => null,
                'bairro'      => 'Centro',
                'cidade'      => 'Belém',
                'estado'      => 'PA',
                'principal'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }
}
