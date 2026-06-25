<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulAutoMatchService;
use App\Enums\ContaStatus;
use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\FormaPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContaAzulCatalogosFinanceirosTest extends TestCase
{
    use RefreshDatabase;

    public function test_concilia_conta_financeira_existente_por_nome_sem_sobrescrever(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Banco Sierra',
            'slug' => 'banco-sierra',
            'tipo' => 'banco',
            'ativo' => false,
        ]);

        $this->insertStaging('stg_conta_azul_contas_financeiras', 'conta-ext-1', [
            'id' => 'conta-ext-1',
            'nome' => 'Banco Sierra',
            'ativo' => true,
            'agencia' => '0001',
        ]);

        $resultado = $this->service()->conciliarContasFinanceiras();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertSame(1, ContaFinanceira::query()->where('nome', 'Banco Sierra')->count());
        $this->assertFalse((bool) $conta->fresh()->ativo);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'id_local' => $conta->id,
            'id_externo' => 'conta-ext-1',
        ]);
    }

    public function test_cria_categoria_financeira_com_hierarquia_em_duas_passagens(): void
    {
        $this->insertStaging('stg_conta_azul_categorias_financeiras', 'cat-pai', [
            'id' => 'cat-pai',
            'nome' => 'Receitas Operacionais',
            'tipo' => 'receita',
        ]);
        $this->insertStaging('stg_conta_azul_categorias_financeiras', 'cat-filha', [
            'id' => 'cat-filha',
            'nome' => 'Servicos',
            'tipo' => 'receita',
            'idCategoriaPai' => 'cat-pai',
        ]);

        $resultado = $this->service()->conciliarCategoriasFinanceiras();

        $this->assertSame(2, $resultado['conciliados']);
        $pai = CategoriaFinanceira::query()->where('nome', 'Receitas Operacionais')->firstOrFail();
        $filha = CategoriaFinanceira::query()->where('nome', 'Servicos')->firstOrFail();
        $this->assertSame((int) $pai->id, (int) $filha->categoria_pai_id);
        $this->assertSame(2, ContaAzulMapeamento::query()->where('tipo_entidade', ContaAzulEntityType::CATEGORIA_FINANCEIRA)->count());
    }

    public function test_cria_centro_de_custo_com_hierarquia(): void
    {
        $this->insertStaging('stg_conta_azul_centros_custo', 'cc-pai', [
            'id' => 'cc-pai',
            'nome' => 'Administrativo',
        ]);
        $this->insertStaging('stg_conta_azul_centros_custo', 'cc-filho', [
            'id' => 'cc-filho',
            'nome' => 'Backoffice',
            'parentId' => 'cc-pai',
        ]);

        $resultado = $this->service()->conciliarCentrosCusto();

        $this->assertSame(2, $resultado['conciliados']);
        $pai = CentroCusto::query()->where('nome', 'Administrativo')->firstOrFail();
        $filho = CentroCusto::query()->where('nome', 'Backoffice')->firstOrFail();
        $this->assertSame((int) $pai->id, (int) $filho->centro_custo_pai_id);
    }

    public function test_concilia_parcela_com_titulo_local_mapeado(): void
    {
        $titulo = $this->criarContaReceber();
        $this->mapear(ContaAzulEntityType::TITULO, $titulo->id, 'evento-rec-1');

        $this->insertStaging('stg_conta_azul_parcelas', 'parcela-ext-1', [
            'id' => 'parcela-ext-1',
            'id_evento' => 'evento-rec-1',
            'evento_tipo_sierra' => ContaAzulEntityType::TITULO,
            'numero' => 1,
            'valor' => '100.00',
        ]);

        $resultado = $this->service()->conciliarParcelas();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::PARCELA,
            'id_local' => $titulo->id,
            'id_externo' => 'parcela-ext-1',
        ]);
    }

    public function test_concilia_baixa_criando_pagamento_receber_quando_ausente(): void
    {
        $contaFinanceira = ContaFinanceira::create([
            'nome' => 'Banco Operacional',
            'slug' => 'banco-operacional',
            'tipo' => 'banco',
            'ativo' => true,
        ]);
        $titulo = $this->criarContaReceber();

        $this->mapear(ContaAzulEntityType::TITULO, $titulo->id, 'evento-rec-2');
        $this->mapear(ContaAzulEntityType::CONTA_FINANCEIRA, $contaFinanceira->id, 'conta-ext-2');

        $this->insertStaging('stg_conta_azul_baixas', 'baixa-ext-1', [
            'id' => 'baixa-ext-1',
            'id_evento' => 'evento-rec-2',
            'evento_tipo_sierra' => ContaAzulEntityType::TITULO,
            'idContaFinanceira' => 'conta-ext-2',
            'valorPago' => '100.00',
            'dataPagamento' => '2026-05-10',
            'metodo_pagamento' => 'PIX',
        ]);

        $resultado = $this->service()->conciliarBaixas();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertSame(1, ContaReceberPagamento::query()->where('conta_receber_id', $titulo->id)->count());
        $this->assertDatabaseHas('contas_receber_pagamentos', [
            'conta_receber_id' => $titulo->id,
            'conta_financeira_id' => $contaFinanceira->id,
            'data_pagamento' => '2026-05-10',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
        ]);
        $this->assertDatabaseHas('formas_pagamento', [
            'slug' => 'pix',
            'ativo' => true,
        ]);
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::BAIXA,
            'id_externo' => 'baixa-ext-1',
        ]);
    }

    public function test_concilia_saldo_atualizando_apenas_campos_de_saldo(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Banco Local',
            'slug' => 'banco-local',
            'tipo' => 'banco',
            'ativo' => false,
            'meta_json' => ['origem' => 'manual'],
        ]);
        $this->mapear(ContaAzulEntityType::CONTA_FINANCEIRA, $conta->id, 'conta-ext-3');

        $this->insertStaging('stg_conta_azul_saldos_contas_financeiras', 'conta-ext-3', [
            'saldoAtual' => '1234.56',
            'consultado_em' => '2026-05-12 10:00:00',
        ]);

        $resultado = $this->service()->conciliarSaldosContasFinanceiras();
        $conta->refresh();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertSame('Banco Local', $conta->nome);
        $this->assertFalse((bool) $conta->ativo);
        $this->assertSame('1234.56', $conta->saldo_atual);
        $this->assertSame('manual', $conta->meta_json['origem']);
        $this->assertArrayHasKey('conta_azul_saldo', $conta->meta_json);
    }

    public function test_concilia_forma_pagamento_criando_catalogo_derivado(): void
    {
        $this->insertStaging('stg_conta_azul_formas_pagamento', 'CARTAO_CREDITO', [
            'codigo' => 'CARTAO_CREDITO',
            'nome' => 'Cartao de credito',
        ]);

        $resultado = $this->service()->conciliarFormasPagamento();

        $this->assertSame(1, $resultado['conciliados']);
        $this->assertSame(1, FormaPagamento::query()->where('slug', 'cartao-credito')->count());
        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::FORMA_PAGAMENTO,
            'id_externo' => 'CARTAO_CREDITO',
            'codigo_externo' => 'CARTAO_CREDITO',
        ]);
    }

    private function service(): ConciliacaoContaAzulService
    {
        return new ConciliacaoContaAzulService(new ContaAzulAutoMatchService());
    }

    private function criarContaReceber(): ContaReceber
    {
        return ContaReceber::create([
            'descricao' => 'Titulo Conta Azul',
            'numero_documento' => 'REC-001',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-20',
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => ContaStatus::ABERTA,
        ]);
    }

    private function mapear(string $tipo, int $idLocal, string $idExterno): void
    {
        ContaAzulMapeamento::create([
            'tipo_entidade' => $tipo,
            'id_local' => $idLocal,
            'id_externo' => $idExterno,
            'origem_inicial' => 'teste',
            'sincronizado_em' => now(),
        ]);
    }

    private function insertStaging(string $table, string $externalId, array $payload): void
    {
        DB::table($table)->insert([
            'loja_id' => null,
            'identificador_externo' => $externalId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'hash_payload' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'status_conciliacao' => 'novo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
