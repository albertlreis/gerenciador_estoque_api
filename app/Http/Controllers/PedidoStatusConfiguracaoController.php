<?php

namespace App\Http\Controllers;

use App\Models\PedidoStatusDefinicao;
use App\Models\PedidoStatusFluxoItem;
use App\Services\PedidoStatusFluxoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PedidoStatusConfiguracaoController extends Controller
{
    public function __construct(private readonly PedidoStatusFluxoService $statusFluxo)
    {
    }

    public function catalogo(Request $request): JsonResponse
    {
        $incluirInativos = $request->boolean('incluir_inativos');

        return response()->json(
            $this->statusFluxo->catalogo($incluirInativos)
                ->map(fn (PedidoStatusDefinicao $status) => $this->statusParaArray($status))
                ->values()
        );
    }

    public function index(Request $request): JsonResponse
    {
        $incluirInativos = $request->boolean('incluir_inativos', true);

        $status = PedidoStatusDefinicao::query()
            ->orderByDesc('ativo')
            ->orderBy('nome')
            ->get();

        if (!$incluirInativos) {
            $status = $status->where('ativo', true)->values();
        }

        return response()->json([
            'data' => $status
                ->map(fn (PedidoStatusDefinicao $item) => $this->statusParaArray($item, true))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('pedido_statuses', 'codigo')],
            'nome' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'cor' => ['nullable', 'string', 'max:20'],
            'severidade' => ['nullable', 'string', Rule::in(['secondary', 'info', 'success', 'warning', 'danger', 'help', 'contrast'])],
            'icone' => ['nullable', 'string', 'max:80'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $status = PedidoStatusDefinicao::query()->create([
            'codigo' => $dados['codigo'],
            'nome' => $dados['nome'],
            'descricao' => $dados['descricao'] ?? null,
            'cor' => $this->normalizarCor($dados['cor'] ?? null),
            'severidade' => $dados['severidade'] ?? 'secondary',
            'icone' => $dados['icone'] ?? 'pi pi-info-circle',
            'ativo' => $dados['ativo'] ?? true,
            'sistema' => false,
            'protegido' => false,
            'papel_operacional' => null,
        ]);

        $this->statusFluxo->limparCache();

        return response()->json([
            'message' => 'Status criado com sucesso.',
            'data' => $this->statusParaArray($status, true),
        ], 201);
    }

    public function update(Request $request, string $codigo): JsonResponse
    {
        $status = PedidoStatusDefinicao::query()->where('codigo', $codigo)->firstOrFail();

        $dados = $request->validate([
            'nome' => ['sometimes', 'required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'cor' => ['nullable', 'string', 'max:20'],
            'severidade' => ['nullable', 'string', Rule::in(['secondary', 'info', 'success', 'warning', 'danger', 'help', 'contrast'])],
            'icone' => ['nullable', 'string', 'max:80'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        if ($status->protegido && array_key_exists('ativo', $dados) && !$dados['ativo']) {
            return response()->json([
                'message' => 'Status protegido nao pode ser desativado.',
                'errors' => ['ativo' => ['Status protegido nao pode ser desativado.']],
            ], 422);
        }

        if (array_key_exists('cor', $dados)) {
            $dados['cor'] = $this->normalizarCor($dados['cor']);
        }

        $status->fill($dados)->save();
        $this->statusFluxo->limparCache();

        return response()->json([
            'message' => 'Status atualizado com sucesso.',
            'data' => $this->statusParaArray($status->fresh(), true),
        ]);
    }

    public function destroy(string $codigo): JsonResponse
    {
        $status = PedidoStatusDefinicao::query()->where('codigo', $codigo)->firstOrFail();

        if ($status->protegido) {
            return response()->json([
                'message' => 'Status protegido nao pode ser excluido ou desativado.',
            ], 422);
        }

        if ($this->statusFluxo->statusUsado($status->codigo)) {
            DB::transaction(function () use ($status) {
                $status->update(['ativo' => false]);
                $status->fluxoItens()->update(['ativo' => false]);
            });

            $this->statusFluxo->limparCache();

            return response()->json([
                'message' => 'Status ja usado foi inativado para preservar o historico.',
                'deactivated' => true,
                'data' => $this->statusParaArray($status->fresh(), true),
            ]);
        }

        $status->delete();
        $this->statusFluxo->limparCache();

        return response()->json([
            'message' => 'Status excluido com sucesso.',
            'deleted' => true,
        ]);
    }

    public function fluxo(string $tipo): JsonResponse
    {
        $tipo = $this->statusFluxo->normalizarTipoFluxo($tipo);

        return response()->json([
            'tipo' => $tipo,
            'data' => $this->statusFluxo->fluxoDetalhadoPorTipo($tipo, false)->values(),
        ]);
    }

    public function atualizarFluxo(Request $request, string $tipo): JsonResponse
    {
        $tipo = $this->statusFluxo->normalizarTipoFluxo($tipo);

        $dados = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.codigo' => ['required', 'string', 'max:50', Rule::exists('pedido_statuses', 'codigo')],
            'itens.*.prazo_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'itens.*.exige_previsao_manual' => ['nullable', 'boolean'],
        ]);

        $codigos = collect($dados['itens'])->pluck('codigo')->values();

        if ($codigos->duplicates()->isNotEmpty()) {
            return response()->json([
                'message' => 'O fluxo nao pode conter status duplicados.',
                'errors' => ['itens' => ['O fluxo nao pode conter status duplicados.']],
            ], 422);
        }

        $statusAtivos = PedidoStatusDefinicao::query()
            ->whereIn('codigo', $codigos)
            ->where('ativo', true)
            ->pluck('id', 'codigo');

        if ($statusAtivos->count() !== $codigos->count()) {
            return response()->json([
                'message' => 'O fluxo so pode conter status ativos.',
                'errors' => ['itens' => ['O fluxo so pode conter status ativos.']],
            ], 422);
        }

        $faltantesProtegidos = array_values(array_diff($this->statusProtegidosObrigatorios($tipo), $codigos->all()));
        if (!empty($faltantesProtegidos)) {
            return response()->json([
                'message' => 'O fluxo precisa manter os status operacionais protegidos.',
                'errors' => [
                    'itens' => ['Inclua: ' . implode(', ', $faltantesProtegidos)],
                ],
            ], 422);
        }

        DB::transaction(function () use ($tipo, $dados, $statusAtivos) {
            PedidoStatusFluxoItem::query()->where('tipo_fluxo', $tipo)->delete();

            foreach (array_values($dados['itens']) as $indice => $item) {
                PedidoStatusFluxoItem::query()->create([
                    'tipo_fluxo' => $tipo,
                    'pedido_status_id' => $statusAtivos[$item['codigo']],
                    'ordem' => $indice + 1,
                    'prazo_dias' => $item['prazo_dias'] ?? null,
                    'exige_previsao_manual' => (bool) ($item['exige_previsao_manual'] ?? false),
                    'ativo' => true,
                ]);
            }
        });

        $this->statusFluxo->limparCache();

        return response()->json([
            'message' => 'Fluxo atualizado com sucesso.',
            'tipo' => $tipo,
            'data' => $this->statusFluxo->fluxoDetalhadoPorTipo($tipo, false)->values(),
        ]);
    }

    private function statusParaArray(PedidoStatusDefinicao $status, bool $comUso = false): array
    {
        $payload = [
            'id' => $status->id,
            'codigo' => $status->codigo,
            'value' => $status->codigo,
            'nome' => $status->nome,
            'label' => $status->nome,
            'descricao' => $status->descricao,
            'cor' => $status->cor,
            'severidade' => $status->severidade,
            'color' => $status->severidade,
            'icone' => $status->icone,
            'ativo' => (bool) $status->ativo,
            'sistema' => (bool) $status->sistema,
            'protegido' => (bool) $status->protegido,
            'papel_operacional' => $status->papel_operacional,
        ];

        if ($comUso) {
            $historico = DB::table('pedido_status_historico')->where('status', $status->codigo)->count();
            $previsoes = DB::table('pedido_status_previsoes')->where('status', $status->codigo)->count();

            $payload['uso_historico'] = $historico;
            $payload['uso_previsoes'] = $previsoes;
            $payload['uso_total'] = $historico + $previsoes;
        }

        return $payload;
    }

    private function normalizarCor(?string $cor): string
    {
        $cor = trim((string) $cor);

        if ($cor === '') {
            return '#adb5bd';
        }

        return str_starts_with($cor, '#') ? $cor : "#{$cor}";
    }

    private function statusProtegidosObrigatorios(string $tipo): array
    {
        return match ($tipo) {
            PedidoStatusFluxoService::TIPO_CONSIGNACAO => [
                'pedido_criado',
                'consignado',
                'devolucao_consignacao',
                'finalizado',
            ],
            PedidoStatusFluxoService::TIPO_REPOSICAO => [
                'pedido_criado',
                'entrega_estoque',
                'finalizado',
            ],
            default => [
                'pedido_criado',
                'envio_cliente',
                'entrega_cliente',
                'finalizado',
            ],
        };
    }
}
