<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use App\Models\ProdutoVariacaoVinculo;
use App\Models\ProdutoVariacao;
use Carbon\Carbon;

class ProdutoVariacaoVinculosSeeder extends Seeder
{
    public function run(): void
    {
        $variacoes = ProdutoVariacao::take(20)->get();

        if ($variacoes->count() < 20) {
            throw new Exception('É necessário pelo menos 20 variações para criar os vínculos.');
        }

        $xProdExemplos = [
            'CAMA KING COM PÉS EMBUTIDOS',
            'SOFÁ SECCIONAL LUXO COURO SINTÉTICO',
            'MESA DE JANTAR EM MADEIRA MACIÇA',
            'CADEIRA ESTOFADA LINHA PREMIUM',
            'ESTANTE 5 PRATELEIRAS COM VIDRO',
            'POLTRONA DECORATIVA ESTILO CLÁSSICO',
            'MESA REDONDA RÚSTICA DE PINUS',
            'CAMA BOX INFANTIL COLORIDA',
            'CADEIRA OFFICE COM APOIO ERGONÔMICO',
            'SOFÁ RETRÁTIL COM ENCOSTO RECLINÁVEL',
            'CABECEIRA ESTOFADA COM LED',
            'MESA COMPACTA DOBRÁVEL MDF BRANCA',
            'PRATELEIRA MODULAR PAREDE',
            'CADEIRA ALTA PARA BALCÃO',
            'CÔMODA COM 4 GAVETAS EM NOGUEIRA',
            'SOFÁ EM L COM TECIDO SUEDE',
            'MESA DE APOIO CROMADA',
            'CADEIRA DE BALANÇO EM FERRO',
            'MÓDULO DE ESTANTE INDUSTRIAL',
            'PUFF RETRÔ EM TECIDO GEOMÉTRICO'
        ];

        foreach ($variacoes as $i => $variacao) {
            ProdutoVariacaoVinculo::create([
                'descricao_xml' => $xProdExemplos[$i],
                'produto_variacao_id' => $variacao->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
