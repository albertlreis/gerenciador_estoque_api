<?php

namespace App\Services\ConciliacaoBancaria;

use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Fornecedor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ConciliacaoBancariaMatcher
{
    private const WINDOW_DAYS = 3;
    private const GENERIC_TOKENS = [
        'com', 'das', 'dos', 'eireli', 'ltda', 'para', 'sierra', 'the',
    ];

    /**
     * @param array<string,mixed> $transaction
     * @return array<string,mixed>
     */
    public function match(array $transaction, int $contaFinanceiraId): array
    {
        $valor = round((float) $transaction['valor'], 2);
        $date = Carbon::parse($transaction['data_movimento']);
        $memo = (string) ($transaction['memo'] ?? '');

        return $valor < 0
            ? $this->matchDebito(abs($valor), $date, $memo, $contaFinanceiraId)
            : $this->matchCredito($valor, $date, $memo, $contaFinanceiraId);
    }

    public function inferFormaPagamento(?string $memo): string
    {
        $normalized = $this->normalize($memo ?? '');

        if (str_contains($normalized, 'pix')) {
            return 'PIX';
        }
        if (str_contains($normalized, 'boleto') || str_contains($normalized, 'cobranca')) {
            return 'BOLETO';
        }
        if (str_contains($normalized, 'ted') || str_contains($normalized, 'doc ')) {
            return 'TED';
        }
        if (str_contains($normalized, 'cartao') || str_contains($normalized, 'credito') || str_contains($normalized, 'debito')) {
            return 'CARTAO';
        }

        return 'OUTROS';
    }

    private function matchDebito(float $valor, CarbonInterface $date, string $memo, int $contaFinanceiraId): array
    {
        $pagamentos = ContaPagarPagamento::query()
            ->with(['conta.fornecedor'])
            ->where('conta_financeira_id', $contaFinanceiraId)
            ->whereBetween('data_pagamento', [$date->copy()->subDays(self::WINDOW_DAYS)->toDateString(), $date->copy()->addDays(self::WINDOW_DAYS)->toDateString()])
            ->get()
            ->filter(fn (ContaPagarPagamento $pagamento) => $this->moneyCents((float) $pagamento->valor) === $this->moneyCents($valor))
            ->values();

        $paymentResult = $this->uniquePayment($pagamentos, 'conta_pagar_pagamento', 'Pagamento de conta a pagar ja registrado');
        if ($paymentResult) {
            return $paymentResult;
        }

        $contas = ContaPagar::query()
            ->with(['fornecedor'])
            ->whereIn('status', ['ABERTA', 'PARCIAL'])
            ->whereBetween('data_vencimento', [$date->copy()->subDays(self::WINDOW_DAYS)->toDateString(), $date->copy()->addDays(self::WINDOW_DAYS)->toDateString()])
            ->get()
            ->filter(fn (ContaPagar $conta) => $this->moneyCents((float) $conta->saldo_aberto) === $this->moneyCents($valor))
            ->values();

        return $this->withProbableIdentification(
            $this->uniqueTitle($contas, 'conta_pagar', $memo, 'Conta a pagar'),
            $this->probableFornecedor($valor, $memo)
        );
    }

    private function matchCredito(float $valor, CarbonInterface $date, string $memo, int $contaFinanceiraId): array
    {
        $pagamentos = ContaReceberPagamento::query()
            ->with(['conta.cliente', 'conta.pedido.cliente'])
            ->where('conta_financeira_id', $contaFinanceiraId)
            ->whereBetween('data_pagamento', [$date->copy()->subDays(self::WINDOW_DAYS)->toDateString(), $date->copy()->addDays(self::WINDOW_DAYS)->toDateString()])
            ->get()
            ->filter(fn (ContaReceberPagamento $pagamento) => $this->moneyCents((float) $pagamento->valor) === $this->moneyCents($valor))
            ->values();

        $paymentResult = $this->uniquePayment($pagamentos, 'conta_receber_pagamento', 'Pagamento de conta a receber ja registrado');
        if ($paymentResult) {
            return $paymentResult;
        }

        $contas = ContaReceber::query()
            ->with(['cliente', 'pedido.cliente'])
            ->whereIn('status', ['ABERTA', 'PARCIAL'])
            ->whereBetween('data_vencimento', [$date->copy()->subDays(self::WINDOW_DAYS)->toDateString(), $date->copy()->addDays(self::WINDOW_DAYS)->toDateString()])
            ->get()
            ->filter(fn (ContaReceber $conta) => $this->moneyCents((float) $conta->saldo_aberto) === $this->moneyCents($valor))
            ->values();

        return $this->withProbableIdentification(
            $this->uniqueTitle($contas, 'conta_receber', $memo, 'Conta a receber'),
            $this->probableCliente($valor, $memo)
        );
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed>|null $probable
     * @return array<string,mixed>
     */
    private function withProbableIdentification(array $result, ?array $probable): array
    {
        if (($result['status'] ?? null) !== 'pendente' || !$probable) {
            return $result;
        }

        return array_merge($result, [
            'candidatos' => [$probable],
            'score' => $probable['score'] ?? null,
            'observacao' => $probable['motivo'] ?? ($result['observacao'] ?? null),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function probableFornecedor(float $valor, string $memo): ?array
    {
        if ($this->isBankFeeMemo($memo)) {
            return $this->bankFeeCandidate();
        }

        $entityText = $this->extractEntityText($memo);
        if ($entityText === '') {
            return null;
        }

        $candidate = $this->bestFornecedor($entityText);
        if (!$candidate) {
            return null;
        }

        /** @var Fornecedor $fornecedor */
        $fornecedor = $candidate['model'];
        $history = $this->fornecedorHistory((int) $fornecedor->id, $valor);
        $score = max((int) $candidate['score'], $history['valor_exato'] ? 80 : 72);
        $score = min(84, $score);
        $motivo = $history['valor_exato']
            ? 'Fornecedor provavel por texto e recorrencia historica; sem titulo aberto com valor/data'
            : 'Fornecedor provavel por texto; sem titulo aberto com valor/data';

        return [
            'tipo' => 'fornecedor_provavel',
            'id' => (int) $fornecedor->id,
            'score' => $score,
            'motivo' => $motivo,
            'label' => sprintf('Fornecedor provavel #%d - %s', $fornecedor->id, $fornecedor->nome),
            'confirmavel' => false,
            'texto_extraido' => $entityText,
            'historico' => $history,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function probableCliente(float $valor, string $memo): ?array
    {
        $entityText = $this->extractEntityText($memo);
        if ($entityText === '') {
            return null;
        }

        $candidate = $this->bestCliente($entityText);
        if (!$candidate) {
            return null;
        }

        /** @var Cliente $cliente */
        $cliente = $candidate['model'];
        $history = $this->clienteHistory((int) $cliente->id, $valor);
        $score = max((int) $candidate['score'], $history['valor_exato'] ? 80 : 72);
        $score = min(84, $score);
        $motivo = $history['valor_exato']
            ? 'Cliente provavel por texto e recorrencia historica; sem titulo aberto com valor/data'
            : 'Cliente provavel por texto; sem titulo aberto com valor/data';

        return [
            'tipo' => 'cliente_provavel',
            'id' => (int) $cliente->id,
            'score' => $score,
            'motivo' => $motivo,
            'label' => sprintf('Cliente provavel #%d - %s', $cliente->id, $cliente->nome),
            'confirmavel' => false,
            'texto_extraido' => $entityText,
            'historico' => $history,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bankFeeCandidate(): array
    {
        $fornecedor = Fornecedor::query()
            ->where('status', 1)
            ->where('nome', 'like', '%BANCO DO BRASIL%')
            ->orderBy('id')
            ->first();

        return [
            'tipo' => 'tarifa_bancaria_provavel',
            'id' => $fornecedor?->id ? (int) $fornecedor->id : null,
            'score' => 65,
            'motivo' => 'Tarifa bancaria provavel do Banco do Brasil; sem titulo aberto com valor/data',
            'label' => $fornecedor
                ? sprintf('Fornecedor provavel #%d - %s', $fornecedor->id, $fornecedor->nome)
                : 'Tarifa bancaria provavel - Banco do Brasil',
            'confirmavel' => false,
        ];
    }

    /**
     * @param Collection<int,ContaPagarPagamento|ContaReceberPagamento> $pagamentos
     * @return array<string,mixed>|null
     */
    private function uniquePayment(Collection $pagamentos, string $tipo, string $motivo): ?array
    {
        if ($pagamentos->count() === 0) {
            return null;
        }

        if ($pagamentos->count() > 1) {
            return $this->conflict($pagamentos->map(fn ($pagamento) => $this->candidateFromModel($tipo, $pagamento, 100, $motivo))->all(), 'Mais de um pagamento existente com mesma conta, data e valor');
        }

        return $this->suggest($this->candidateFromModel($tipo, $pagamentos->first(), 100, $motivo));
    }

    /**
     * @param Collection<int,ContaPagar|ContaReceber> $contas
     * @return array<string,mixed>
     */
    private function uniqueTitle(Collection $contas, string $tipo, string $memo, string $labelPrefix): array
    {
        if ($contas->count() === 0) {
            return ['status' => 'pendente', 'observacao' => "{$labelPrefix} sem candidato por valor e data"];
        }

        $textMatches = $contas
            ->filter(fn ($conta) => $this->matchesText($memo, $this->candidateTexts($conta)))
            ->values();

        if ($textMatches->count() === 1) {
            return $this->suggest($this->candidateFromModel($tipo, $textMatches->first(), 95, "{$labelPrefix} unica por valor, data e texto"));
        }

        if ($textMatches->count() > 1) {
            return $this->conflict(
                $textMatches->map(fn ($conta) => $this->candidateFromModel($tipo, $conta, 95, "{$labelPrefix} por valor, data e texto"))->all(),
                "Mais de uma {$labelPrefix} com mesmo valor, data e texto"
            );
        }

        if ($contas->count() === 1) {
            return $this->suggest($this->candidateFromModel($tipo, $contas->first(), 85, "{$labelPrefix} unica por valor e data"));
        }

        return $this->conflict(
            $contas->map(fn ($conta) => $this->candidateFromModel($tipo, $conta, 85, "{$labelPrefix} por valor e data"))->all(),
            "Mais de uma {$labelPrefix} com mesmo valor e data"
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function suggest(array $candidate): array
    {
        return [
            'status' => 'sugerido',
            'candidato' => $candidate,
            'observacao' => $candidate['motivo'] ?? null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function conflict(array $candidates, string $observacao): array
    {
        return [
            'status' => 'conflito',
            'observacao' => $observacao,
            'candidatos' => array_values($candidates),
        ];
    }

    /**
     * @param ContaPagar|ContaReceber|ContaPagarPagamento|ContaReceberPagamento $model
     * @return array<string,mixed>
     */
    private function candidateFromModel(string $tipo, object $model, int $score, string $motivo): array
    {
        return [
            'tipo' => $tipo,
            'id' => (int) $model->id,
            'score' => $score,
            'motivo' => $motivo,
            'label' => $this->candidateLabel($tipo, $model),
        ];
    }

    private function candidateLabel(string $tipo, object $model): string
    {
        if ($model instanceof ContaPagarPagamento) {
            return sprintf('Pagamento conta a pagar #%d - %s', $model->id, $model->conta?->descricao ?: '-');
        }
        if ($model instanceof ContaReceberPagamento) {
            return sprintf('Pagamento conta a receber #%d - %s', $model->id, $model->conta?->descricao ?: '-');
        }
        if ($model instanceof ContaPagar) {
            return sprintf('Conta a pagar #%d - %s', $model->id, $model->descricao ?: '-');
        }
        if ($model instanceof ContaReceber) {
            return sprintf('Conta a receber #%d - %s', $model->id, $model->descricao ?: '-');
        }

        return "{$tipo} #{$model->id}";
    }

    /**
     * @return array{model:Fornecedor,score:int}|null
     */
    private function bestFornecedor(string $entityText): ?array
    {
        $tokens = $this->significantTokens($entityText);
        if (!$tokens) {
            return null;
        }

        $fornecedores = Fornecedor::query()
            ->where('status', 1)
            ->where(function ($query) use ($tokens) {
                foreach (array_slice($tokens, 0, 5) as $token) {
                    $query->orWhere('nome', 'like', '%' . $token . '%');
                }
            })
            ->limit(80)
            ->get(['id', 'nome']);

        return $this->bestNamedModel($fornecedores, $entityText);
    }

    /**
     * @return array{model:Cliente,score:int}|null
     */
    private function bestCliente(string $entityText): ?array
    {
        $tokens = $this->significantTokens($entityText);
        if (!$tokens) {
            return null;
        }

        $clientes = Cliente::query()
            ->where(function ($query) use ($tokens) {
                foreach (array_slice($tokens, 0, 5) as $token) {
                    $query->orWhere('nome', 'like', '%' . $token . '%')
                        ->orWhere('nome_fantasia', 'like', '%' . $token . '%');
                }
            })
            ->limit(80)
            ->get(['id', 'nome', 'nome_fantasia']);

        return $this->bestNamedModel($clientes, $entityText);
    }

    /**
     * @param Collection<int,Fornecedor|Cliente> $models
     * @return array{model:Fornecedor|Cliente,score:int}|null
     */
    private function bestNamedModel(Collection $models, string $entityText): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach ($models as $model) {
            $score = max(
                $this->nameScore($entityText, (string) $model->nome),
                $model instanceof Cliente ? $this->nameScore($entityText, (string) $model->nome_fantasia) : 0
            );

            if ($score > $bestScore) {
                $best = $model;
                $bestScore = $score;
            }
        }

        return $best && $bestScore >= 58
            ? ['model' => $best, 'score' => $bestScore]
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function fornecedorHistory(int $fornecedorId, float $valor): array
    {
        $contas = ContaPagar::query()
            ->where('fornecedor_id', $fornecedorId)
            ->orderByDesc('data_vencimento')
            ->limit(20)
            ->get(['id', 'data_vencimento', 'valor_bruto', 'desconto', 'juros', 'multa', 'status']);

        $exact = $contas->first(fn (ContaPagar $conta) => $this->moneyCents((float) $conta->valor_liquido) === $this->moneyCents($valor));
        $last = $contas->first();

        return [
            'valor_exato' => (bool) $exact,
            'titulo_valor_exato_id' => $exact?->id ? (int) $exact->id : null,
            'ultimo_titulo_id' => $last?->id ? (int) $last->id : null,
            'ultimo_vencimento' => $last?->data_vencimento?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function clienteHistory(int $clienteId, float $valor): array
    {
        $contas = ContaReceber::query()
            ->where('cliente_id', $clienteId)
            ->orderByDesc('data_vencimento')
            ->limit(20)
            ->get(['id', 'data_vencimento', 'valor_bruto', 'desconto', 'juros', 'multa', 'status']);

        $exact = $contas->first(fn (ContaReceber $conta) => $this->moneyCents((float) $conta->valor_liquido) === $this->moneyCents($valor));
        $last = $contas->first();

        return [
            'valor_exato' => (bool) $exact,
            'titulo_valor_exato_id' => $exact?->id ? (int) $exact->id : null,
            'ultimo_titulo_id' => $last?->id ? (int) $last->id : null,
            'ultimo_vencimento' => $last?->data_vencimento?->format('Y-m-d'),
        ];
    }

    /**
     * @param ContaPagar|ContaReceber $conta
     * @return array<int,string>
     */
    private function candidateTexts(object $conta): array
    {
        if ($conta instanceof ContaPagar) {
            return [
                (string) $conta->descricao,
                (string) $conta->numero_documento,
                (string) $conta->fornecedor?->nome,
            ];
        }

        $cliente = $conta->cliente?->id ? $conta->cliente : $conta->pedido?->cliente;

        return [
            (string) $conta->descricao,
            (string) $conta->numero_documento,
            (string) $cliente?->nome,
            (string) $conta->pedido?->numero_externo,
        ];
    }

    /**
     * @param array<int,string> $texts
     */
    private function matchesText(string $memo, array $texts): bool
    {
        $memoNorm = $this->normalize($memo);
        if ($memoNorm === '') {
            return false;
        }

        foreach ($texts as $text) {
            $textNorm = $this->normalize($text);
            if (strlen($textNorm) < 3) {
                continue;
            }

            if (str_contains($memoNorm, $textNorm) || str_contains($textNorm, $memoNorm)) {
                return true;
            }
        }

        return false;
    }

    private function nameScore(string $entityText, string $name): int
    {
        $entityNorm = $this->normalize($entityText);
        $nameNorm = $this->normalize($name);
        if ($entityNorm === '' || $nameNorm === '') {
            return 0;
        }

        if (strlen($entityNorm) >= 5 && (str_contains($nameNorm, $entityNorm) || str_contains($entityNorm, $nameNorm))) {
            return 86;
        }

        $entityTokens = $this->significantTokens($entityNorm);
        $nameTokens = $this->significantTokens($nameNorm);
        if (!$entityTokens || !$nameTokens) {
            return 0;
        }

        $matches = 0;
        foreach ($entityTokens as $entityToken) {
            foreach ($nameTokens as $nameToken) {
                if ($this->tokensMatch($entityToken, $nameToken)) {
                    $matches++;
                    break;
                }
            }
        }

        $ratio = $matches / max(count($entityTokens), 1);
        $score = (int) round(35 + ($ratio * 50));

        if ($matches > 0 && $this->tokensMatch($entityTokens[0], $nameTokens[0])) {
            $score += 8;
        }

        return min(86, $score);
    }

    private function tokensMatch(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return min(strlen($left), strlen($right)) >= 3
            && (str_starts_with($left, $right) || str_starts_with($right, $left));
    }

    /**
     * @return array<int,string>
     */
    private function significantTokens(string $value): array
    {
        $tokens = preg_split('/\s+/', $this->normalize($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, function (string $token) {
            return strlen($token) >= 3
                && !is_numeric($token)
                && !in_array($token, self::GENERIC_TOKENS, true);
        }));
    }

    private function extractEntityText(string $memo): string
    {
        $normalized = $this->normalize($memo);
        $phrases = [
            'pagamento de boleto',
            'pag boleto',
            'pix enviado',
            'pix recebido',
            'transf enviada',
            'transferencia enviada',
            'pgto conta agua',
            'conta agua',
            'impostos',
            'cobranca',
            'cliente',
            'enviado',
            'recebido',
            'boleto',
            'pgto',
            'pag',
        ];

        foreach ($phrases as $phrase) {
            $normalized = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/', ' ', $normalized) ?? $normalized;
        }

        $normalized = preg_replace('/\b\d{1,4}\b/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function isBankFeeMemo(string $memo): bool
    {
        $normalized = $this->normalize($memo);

        return str_contains($normalized, 'tar agrupadas')
            || str_contains($normalized, 'tarifa pix')
            || str_contains($normalized, 'cbr liquidacao')
            || str_contains($normalized, 'cbr baixa')
            || str_contains($normalized, 'cbr instrucoes');
    }

    private function normalize(string $value): string
    {
        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function moneyCents(float $value): int
    {
        return (int) round($value * 100);
    }
}
