<?php

namespace Tests\Feature\Financeiro;

use App\Exports\ContasReceberExport;
use App\Models\Cliente;
use App\Models\ContaReceber;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContaReceberOrdenacaoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = $this->criarUsuario('Ordenacao Principal');
        Sanctum::actingAs($this->usuario);
    }

    public function test_aplica_padrao_estavel_com_datas_nulas_ao_final(): void
    {
        $futuro = $this->criarConta(['data_vencimento' => '2026-08-20']);
        $vencidaUm = $this->criarConta(['data_vencimento' => '2026-08-01']);
        $vencidaDois = $this->criarConta(['data_vencimento' => '2026-08-01']);
        $semData = $this->criarConta(['data_vencimento' => null]);

        $response = $this->getJson('/api/v1/financeiro/contas-receber?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.sort_field', 'data_vencimento')
            ->assertJsonPath('meta.sort_direction', 'asc');

        $this->assertSame(
            [$vencidaUm->id, $vencidaDois->id, $futuro->id, $semData->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_aceita_toda_allowlist_nas_duas_direcoes(): void
    {
        $clienteA = Cliente::create(['nome' => 'Ana Cliente']);
        $clienteZ = Cliente::create(['nome' => 'Zilda Cliente']);
        $pedidoA = Pedido::create([
            'id_cliente' => $clienteA->id,
            'id_usuario' => $this->usuario->id,
            'numero_externo' => 'PED-A',
        ]);
        $pedidoZ = Pedido::create([
            'id_cliente' => $clienteZ->id,
            'id_usuario' => $this->usuario->id,
            'numero_externo' => 'PED-Z',
        ]);
        $primeira = $this->criarConta([
            'pedido_id' => $pedidoA->id,
            'descricao' => 'Alfa',
            'data_vencimento' => '2026-08-01',
            'valor_bruto' => 100,
            'valor_liquido' => 100,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
        ]);
        $segunda = $this->criarConta([
            'pedido_id' => $pedidoZ->id,
            'descricao' => 'Zeta',
            'data_vencimento' => '2026-08-20',
            'valor_bruto' => 200,
            'valor_liquido' => 200,
            'saldo_aberto' => 200,
            'status' => 'PAGA',
        ]);

        foreach (['id', 'pedido', 'cliente', 'descricao', 'data_vencimento', 'valor_liquido', 'saldo_aberto', 'status'] as $field) {
            $asc = $this->getJson('/api/v1/financeiro/contas-receber?' . http_build_query([
                'sort_field' => $field,
                'sort_direction' => 'asc',
            ]))->assertOk();
            $desc = $this->getJson('/api/v1/financeiro/contas-receber?' . http_build_query([
                'sort_field' => $field,
                'sort_direction' => 'desc',
            ]))->assertOk();

            $this->assertSame($primeira->id, $asc->json('data.0.id'), "Falha asc em {$field}");
            $this->assertSame($segunda->id, $desc->json('data.0.id'), "Falha desc em {$field}");
        }
    }

    public function test_rejeita_parametros_invalidos_sem_alterar_preferencia(): void
    {
        $this->criarConta();
        $this->getJson('/api/v1/financeiro/contas-receber?sort_field=status&sort_direction=desc')
            ->assertOk();

        $antes = DB::table('usuario_preferencias')
            ->where('usuario_id', $this->usuario->id)
            ->where('chave', 'financeiro.contas_receber.ordenacao')
            ->value('valor');

        $this->getJson('/api/v1/financeiro/contas-receber?sort_field=senha&sort_direction=desc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_field']);
        $this->getJson('/api/v1/financeiro/contas-receber?sort_field=status&sort_direction=down')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_direction']);
        $this->getJson('/api/v1/financeiro/contas-receber?sort_field=status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_direction']);

        $depois = DB::table('usuario_preferencias')
            ->where('usuario_id', $this->usuario->id)
            ->where('chave', 'financeiro.contas_receber.ordenacao')
            ->value('valor');

        $this->assertSame($antes, $depois);
    }

    public function test_restaura_preferencia_isolada_por_usuario(): void
    {
        $primeira = $this->criarConta(['descricao' => 'Alfa']);
        $segunda = $this->criarConta(['descricao' => 'Zeta']);

        $this->getJson('/api/v1/financeiro/contas-receber?sort_field=descricao&sort_direction=desc')
            ->assertOk();
        $this->getJson('/api/v1/financeiro/contas-receber')
            ->assertOk()
            ->assertJsonPath('data.0.id', $segunda->id)
            ->assertJsonPath('meta.sort_field', 'descricao');

        $outro = $this->criarUsuario('Ordenacao Secundaria');
        Sanctum::actingAs($outro);

        $this->getJson('/api/v1/financeiro/contas-receber')
            ->assertOk()
            ->assertJsonPath('data.0.id', $primeira->id)
            ->assertJsonPath('meta.sort_field', 'data_vencimento');
    }

    public function test_paginacao_e_exportacao_usam_a_mesma_ordenacao(): void
    {
        $contas = collect();
        foreach (range(1, 5) as $numero) {
            $contas->push($this->criarConta([
                'descricao' => "Conta {$numero}",
                'data_vencimento' => '2026-08-10',
            ]));
        }

        $paginaUm = $this->getJson('/api/v1/financeiro/contas-receber?sort_field=data_vencimento&sort_direction=desc&per_page=2&page=1')
            ->assertOk()->json('data');
        $paginaDois = $this->getJson('/api/v1/financeiro/contas-receber?sort_field=data_vencimento&sort_direction=desc&per_page=2&page=2')
            ->assertOk()->json('data');

        $idsUm = collect($paginaUm)->pluck('id');
        $idsDois = collect($paginaDois)->pluck('id');
        $this->assertCount(0, $idsUm->intersect($idsDois));
        $this->assertSame(
            $contas->sortByDesc('id')->pluck('id')->take(4)->values()->all(),
            $idsUm->concat($idsDois)->values()->all()
        );

        $this->assertSame(
            $contas->sortByDesc('id')->pluck('id')->values()->all(),
            ContasReceberExport::query([
                'sort_field' => 'data_vencimento',
                'sort_direction' => 'desc',
            ])->pluck('contas_receber.id')->all()
        );
    }

    public function test_exportacao_valida_ordenacao_sem_gravar_preferencia(): void
    {
        $this->criarConta();

        $this->getJson('/api/v1/financeiro/contas-receber/export/pdf?sort_field=senha&sort_direction=asc')
            ->assertStatus(422);

        $this->assertDatabaseMissing('usuario_preferencias', [
            'usuario_id' => $this->usuario->id,
            'chave' => 'financeiro.contas_receber.ordenacao',
        ]);
    }

    private function criarConta(array $dados = []): ContaReceber
    {
        return ContaReceber::create(array_merge([
            'descricao' => 'Conta teste',
            'data_vencimento' => '2026-08-01',
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'valor_liquido' => 100,
            'valor_recebido' => 0,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
        ], $dados));
    }

    private function criarUsuario(string $nome): Usuario
    {
        return Usuario::create([
            'nome' => $nome,
            'email' => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
    }
}
