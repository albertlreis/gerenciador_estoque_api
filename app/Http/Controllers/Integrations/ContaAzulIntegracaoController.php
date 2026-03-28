<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use App\Integrations\ContaAzul\Models\ContaAzulSyncLog;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContaAzulIntegracaoController extends Controller
{
    public function __construct(
        private readonly ContaAzulConnectionService $connections,
        private readonly ImportacaoContaAzulService $importacao,
        private readonly ConciliacaoContaAzulService $conciliacao,
        private readonly ReconciliacaoContaAzulService $reconciliacao
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);

        if (!$conexao) {
            return response()->json([
                'conectado' => false,
                'conexao' => null,
            ]);
        }

        return response()->json([
            'conectado' => $conexao->status === 'ativa',
            'conexao' => $conexao->only(['id', 'status', 'loja_id', 'ultimo_healthcheck_em', 'ultimo_erro', 'nome_externo', 'ambiente']),
        ]);
    }

    public function pendencias(Request $request): JsonResponse
    {
        $lojaId = $this->lojaId($request);

        return response()->json([
            'data' => $this->conciliacao->resumoPendencias($lojaId),
        ]);
    }

    public function testarConexao(Request $request): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão encontrada'], 404);
        }

        $ok = $this->connections->healthcheck($conexao);

        return response()->json(['ok' => $ok]);
    }

    public function batches(Request $request): JsonResponse
    {
        $q = ContaAzulImportBatch::query()->orderByDesc('id')->limit(50);
        if ($request->filled('loja_id')) {
            $q->where('loja_id', (int) $request->query('loja_id'));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function syncLogs(Request $request): JsonResponse
    {
        $q = ContaAzulSyncLog::query()->orderByDesc('id')->limit(100);
        if ($request->filled('loja_id')) {
            $q->where('loja_id', (int) $request->query('loja_id'));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function importar(Request $request, string $entidade): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão'], 404);
        }

        try {
            $tipo = $this->mapEntidade($entidade);
        } catch (\InvalidArgumentException) {
            return response()->json(['ok' => false, 'mensagem' => 'Entidade inválida'], 422);
        }

        $res = $this->importacao->importarParaStaging($conexao, $tipo, $lojaId);

        return response()->json(['ok' => true, 'resultado' => $res]);
    }

    public function conciliar(Request $request): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $res = $this->conciliacao->conciliarPessoas($lojaId);

        return response()->json(['ok' => true, 'resultado' => $res]);
    }

    public function conciliarEntidade(Request $request, string $entidade): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $res = match ($entidade) {
            'pessoas' => $this->conciliacao->conciliarPessoas($lojaId),
            'produtos' => $this->conciliacao->conciliarProdutos($lojaId),
            'vendas' => $this->conciliacao->conciliarVendas($lojaId),
            'titulos', 'financeiro' => $this->conciliacao->conciliarTitulos($lojaId),
            'baixas' => $this->conciliacao->conciliarBaixas($lojaId),
            'tudo' => $this->conciliacao->conciliarTudo($lojaId),
            default => null,
        };

        if ($res === null) {
            return response()->json(['ok' => false, 'mensagem' => 'Entidade inválida'], 422);
        }

        return response()->json(['ok' => true, 'resultado' => $res]);
    }

    public function reconciliar(Request $request): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão'], 404);
        }

        $recurso = (string) $request->input('recurso', 'pessoas');
        $this->reconciliacao->reconciliarRecurso($conexao, $recurso, $lojaId);

        return response()->json(['ok' => true]);
    }

    public function reconciliarTodos(Request $request): JsonResponse
    {
        $lojaId = $this->lojaId($request);
        $conexao = $this->connections->latestForLoja($lojaId);
        if (!$conexao) {
            return response()->json(['ok' => false, 'mensagem' => 'Nenhuma conexão'], 404);
        }

        $this->reconciliacao->reconciliarTodos($conexao, $lojaId);

        return response()->json(['ok' => true]);
    }

    private function lojaId(Request $request): ?int
    {
        $v = $request->query('loja_id', $request->input('loja_id'));

        return $v === null || $v === '' ? null : (int) $v;
    }

    private function mapEntidade(string $entidade): string
    {
        return match ($entidade) {
            'pessoas' => ContaAzulEntityType::PESSOA,
            'produtos' => ContaAzulEntityType::PRODUTO,
            'vendas' => ContaAzulEntityType::VENDA,
            'financeiro' => ContaAzulEntityType::TITULO,
            'baixas' => ContaAzulEntityType::BAIXA,
            'notas' => ContaAzulEntityType::NOTA,
            default => throw new \InvalidArgumentException('Entidade inválida'),
        };
    }
}
