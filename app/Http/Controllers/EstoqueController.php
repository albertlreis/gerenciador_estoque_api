<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroEstoqueDTO;
use App\Enums\EstoqueMovimentacaoTipo;
use App\Helpers\AuthHelper;
use App\Http\Requests\FiltroEstoqueRequest;
use App\Http\Resources\MovimentacaoResource;
use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Models\Estoque;
use App\Services\EstoqueMovimentacaoService;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class EstoqueController extends Controller
{
    /**
     * Lista o estoque atual agrupado por produto e depósito com filtros e ordenação.
     *
     * @queryParam periodo array Opcional. [YYYY-MM-DD, YYYY-MM-DD] para considerar movimentações no intervalo.
     *
     * @param \App\Http\Requests\FiltroEstoqueRequest $request Instância da requisição HTTP com os parâmetros de filtro
     * @param EstoqueService $service Serviço responsável pela lógica de estoque
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function listarEstoqueAtual(FiltroEstoqueRequest $request, EstoqueService $service): JsonResponse|Response|BinaryFileResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $dto = new FiltroEstoqueDTO($request->validated());

        if ($request->get('export') === 'pdf') {
            return $service->exportarPdf($dto);
        }

        if ($request->get('export') === 'excel') {
            return $service->exportarExcel($dto);
        }

        $result = $service->listar($dto);

        return ProdutoEstoqueResource::collection($result)->response();
    }

    /**
     * Retorna um resumo com total de produtos, peças e depósitos.
     *
     * @param Request $request
     * @param EstoqueService $service
     * @return JsonResponse
     */
    public function resumoEstoque(FiltroEstoqueRequest $request, EstoqueService $service): JsonResponse
    {
        $dto = new FiltroEstoqueDTO($request->validated());
        $resumo = $service->gerarResumo($dto);

        return response()->json(new ResumoEstoqueResource($resumo));
    }

    /**
     * Lista os depósitos com estoque positivo de uma variação específica.
     *
     * @param int $id_variacao
     * @return JsonResponse
     */
    public function porVariacao(int $id_variacao): JsonResponse
    {
        $estoques = Estoque::with('deposito')
            ->where('id_variacao', $id_variacao)
            ->where('quantidade', '>', 0)
            ->get()
            ->filter(fn($e) => $e->deposito)
            ->map(fn($e) => [
                'id' => $e->deposito->id,
                'nome' => $e->deposito->nome,
                'quantidade' => $e->quantidade
            ])
            ->values();

        return response()->json($estoques);
    }

    /**
     * Registra um ajuste manual auditavel a partir do saldo final desejado.
     */
    public function registrarAjusteManual(Request $request, EstoqueMovimentacaoService $movimentacaoService): JsonResponse
    {
        if (!AuthHelper::podeRegistrarAjusteManualEstoque()) {
            return response()->json([
                'message' => 'Sem permissao para registrar ajuste manual de estoque.',
            ], 403);
        }

        $dados = $request->validate([
            'estoque_id' => ['required', 'integer', 'exists:estoque,id'],
            'quantidade_final' => ['required', 'integer', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $movimentacao = DB::transaction(function () use ($dados, $movimentacaoService) {
                /** @var Estoque $estoque */
                $estoque = Estoque::query()
                    ->whereKey((int) $dados['estoque_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $quantidadeAtual = (int) $estoque->quantidade;
                $quantidadeFinal = (int) $dados['quantidade_final'];
                $diferenca = $quantidadeFinal - $quantidadeAtual;

                if ($diferenca === 0) {
                    throw ValidationException::withMessages([
                        'quantidade_final' => ['A quantidade final deve ser diferente da quantidade atual.'],
                    ]);
                }

                $tipo = $diferenca > 0
                    ? EstoqueMovimentacaoTipo::ENTRADA->value
                    : EstoqueMovimentacaoTipo::SAIDA->value;

                $observacaoUsuario = trim((string) ($dados['observacao'] ?? ''));
                $observacao = sprintf(
                    'Ajuste manual de estoque. Saldo anterior: %d. Saldo final: %d.',
                    $quantidadeAtual,
                    $quantidadeFinal
                );

                if ($observacaoUsuario !== '') {
                    $observacao .= ' ' . $observacaoUsuario;
                }

                return $movimentacaoService->registrarMovimentacaoManual([
                    'id_variacao' => (int) $estoque->id_variacao,
                    'id_deposito_origem' => $diferenca < 0 ? (int) $estoque->id_deposito : null,
                    'id_deposito_destino' => $diferenca > 0 ? (int) $estoque->id_deposito : null,
                    'tipo' => $tipo,
                    'quantidade' => abs($diferenca),
                    'observacao' => $observacao,
                    'ref_type' => 'ajuste_manual',
                    'ref_id' => (int) $estoque->id,
                ], auth()->id());
            });

            return response()->json([
                'data' => new MovimentacaoResource($movimentacao),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
