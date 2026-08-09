<?php

namespace App\Console\Commands;

use App\Models\PedidoImportacao;
use App\Models\PedidoImportacaoItem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Services\FornecedorModeloAtributosService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RepararAcabamentosImportacaoPedidoCommand extends Command
{
    private const TIPOS = ['madeira', 'metal_vidro', 'tecido_1', 'tecido_2'];

    protected $signature = 'pedidos:reparar-acabamentos-importacao
        {pedido_importacao_id : ID da importacao de pedido a analisar}
        {--execute : Persiste as correcoes ou o rollback}
        {--dry-run : Forca simulacao, mesmo com --execute}
        {--manifest= : Caminho relativo para gravar o manifesto gerado}
        {--rollback= : Caminho relativo do manifesto de execucao a reverter}';

    protected $description = 'Converte o MODEL de uma importacao em atributos tipados, com dry-run e rollback.';

    public function handle(FornecedorModeloAtributosService $classificador): int
    {
        $importacaoId = filter_var($this->argument('pedido_importacao_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! $importacaoId || ! PedidoImportacao::query()->whereKey($importacaoId)->exists()) {
            $this->error('Importacao de pedido invalida ou inexistente.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $rollback = trim((string) $this->option('rollback'));

        try {
            return $rollback !== ''
                ? $this->reverter($importacaoId, $rollback, $execute)
                : $this->reparar($importacaoId, $execute, $classificador);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function reparar(
        int $importacaoId,
        bool $execute,
        FornecedorModeloAtributosService $classificador
    ): int {
        $processar = function () use ($importacaoId, $execute, $classificador): array {
            $itens = PedidoImportacaoItem::query()
                ->where('pedido_importacao_id', $importacaoId)
                ->orderBy('id')
                ->get();
            $entradas = [];

            foreach ($itens as $item) {
                $entradas[] = $this->analisarItem($item, $execute, $classificador);
            }

            $modo = $execute ? 'execucao' : 'dry-run';
            $manifesto = $this->montarManifesto($modo, $importacaoId, $entradas);
            $caminho = $this->salvarManifesto($manifesto, $importacaoId, $modo);

            return [$entradas, $caminho];
        };

        [$entradas, $caminho] = $execute ? DB::transaction($processar) : $processar();

        $this->exibirResumo($entradas, $execute ? 'Execucao concluida.' : 'Dry-run concluido.');
        $this->info("Manifesto: {$caminho}");

        return self::SUCCESS;
    }

    private function analisarItem(
        PedidoImportacaoItem $item,
        bool $execute,
        FornecedorModeloAtributosService $classificador
    ): array {
        $entrada = [
            'pedido_importacao_item_id' => (int) $item->id,
            'produto_variacao_id' => $item->produto_variacao_id ? (int) $item->produto_variacao_id : null,
            'modelo_referencia' => null,
            'atributos_desejados' => [],
            'atributos_criados' => [],
            'atributos_removidos' => [],
            'acao' => null,
        ];

        if (! $item->produto_variacao_id) {
            return $this->finalizarEntrada($entrada, 'sem_variacao');
        }

        $query = ProdutoVariacao::query()->with('atributos')->whereKey($item->produto_variacao_id);
        if ($execute) {
            $query->lockForUpdate();
        }
        $variacao = $query->first();

        if (! $variacao) {
            return $this->finalizarEntrada($entrada, 'variacao_inexistente');
        }

        $legados = $variacao->atributos
            ->filter(fn (ProdutoVariacaoAtributo $atributo) => in_array(
                $this->normalizarNome((string) $atributo->atributo),
                ['acabamentos', 'modelo_referencia'],
                true
            ))
            ->values();
        $modelo = $this->extrairModeloReferencia($item->dados_importados_json ?? [], $legados);
        $entrada['modelo_referencia'] = $modelo;

        if ($modelo === null) {
            return $this->finalizarEntrada($entrada, 'sem_origem');
        }

        $desejados = $classificador->classificar($modelo);
        $entrada['atributos_desejados'] = $desejados;
        if ($desejados === []) {
            return $this->finalizarEntrada($entrada, 'sem_atributos_classificados');
        }

        $tiposDesejados = collect($desejados)->pluck('atributo')->unique()->all();
        $tipadosExistentes = $variacao->atributos
            ->filter(fn (ProdutoVariacaoAtributo $atributo) => in_array(
                $this->normalizarNome((string) $atributo->atributo),
                self::TIPOS,
                true
            ))
            ->values();

        $conflitantes = $tipadosExistentes->filter(function (ProdutoVariacaoAtributo $atributo) use ($desejados, $tiposDesejados): bool {
            $tipo = $this->normalizarNome((string) $atributo->atributo);
            if (! in_array($tipo, $tiposDesejados, true)) {
                return false;
            }

            return ! collect($desejados)->contains(fn (array $desejado) =>
                $desejado['atributo'] === $tipo
                && $this->normalizarValor($desejado['valor']) === $this->normalizarValor((string) $atributo->valor)
            );
        });

        if ($conflitantes->isNotEmpty()) {
            $entrada['conflitos'] = $conflitantes
                ->map(fn (ProdutoVariacaoAtributo $atributo) => $this->snapshotAtributo($atributo))
                ->values()
                ->all();

            return $this->finalizarEntrada($entrada, 'conflito_atributos_existentes');
        }

        $disponiveis = $tipadosExistentes->all();
        $faltantes = [];
        foreach ($desejados as $desejado) {
            $indice = collect($disponiveis)->search(fn (ProdutoVariacaoAtributo $atributo) =>
                $this->normalizarNome((string) $atributo->atributo) === $desejado['atributo']
                && $this->normalizarValor((string) $atributo->valor) === $this->normalizarValor($desejado['valor'])
            );

            if ($indice === false) {
                $faltantes[] = $desejado;
            } else {
                unset($disponiveis[$indice]);
            }
        }

        $entrada['atributos_criados'] = array_map(fn (array $atributo) => [
            'id' => null,
            ...$atributo,
        ], $faltantes);
        $entrada['atributos_removidos'] = $legados
            ->map(fn (ProdutoVariacaoAtributo $atributo) => $this->snapshotAtributo($atributo))
            ->all();

        if ($faltantes === [] && $legados->isEmpty()) {
            return $this->finalizarEntrada($entrada, 'ja_corrigido');
        }

        if (! $execute) {
            return $this->finalizarEntrada($entrada, 'pendente');
        }

        $criados = [];
        foreach ($faltantes as $atributo) {
            $criado = $variacao->atributos()->create($atributo);
            $criados[] = $this->snapshotAtributo($criado);
        }
        foreach ($legados as $legado) {
            $legado->delete();
        }

        $entrada['atributos_criados'] = $criados;

        return $this->finalizarEntrada($entrada, $legados->isNotEmpty() ? 'convertido' : 'criado');
    }

    private function reverter(int $importacaoId, string $manifestoOrigem, bool $execute): int
    {
        $this->validarCaminhoRelativo($manifestoOrigem);
        $conteudo = Storage::disk('local')->get($manifestoOrigem);
        $manifesto = json_decode($conteudo, true);

        if (! is_array($manifesto) || ! $this->checksumManifestoValido($manifesto)) {
            throw new RuntimeException('Manifesto de rollback invalido ou com checksum divergente.');
        }

        if (($manifesto['modo'] ?? null) !== 'execucao' || (int) ($manifesto['pedido_importacao_id'] ?? 0) !== $importacaoId) {
            throw new RuntimeException('O manifesto nao pertence a uma execucao desta importacao.');
        }

        $processar = function () use ($manifesto, $manifestoOrigem, $importacaoId, $execute): array {
            $entradas = [];

            foreach (($manifesto['itens'] ?? []) as $item) {
                if (! in_array(($item['acao'] ?? null), ['criado', 'convertido'], true)) {
                    continue;
                }

                $entrada = [
                    'pedido_importacao_item_id' => (int) ($item['pedido_importacao_item_id'] ?? 0),
                    'produto_variacao_id' => (int) ($item['produto_variacao_id'] ?? 0),
                    'modelo_referencia' => $item['modelo_referencia'] ?? null,
                    'atributos_criados' => $item['atributos_criados'] ?? [],
                    'atributos_restaurados' => [],
                    'acao' => null,
                ];
                $criados = collect($item['atributos_criados'] ?? []);
                $registros = ProdutoVariacaoAtributo::query()
                    ->whereIn('id', $criados->pluck('id')->filter()->all())
                    ->when($execute, fn ($query) => $query->lockForUpdate())
                    ->get()
                    ->keyBy('id');

                $intactos = $criados->every(function (array $esperado) use ($registros, $item): bool {
                    $registro = $registros->get((int) ($esperado['id'] ?? 0));

                    return $registro
                        && (int) $registro->id_variacao === (int) ($item['produto_variacao_id'] ?? 0)
                        && $this->normalizarNome((string) $registro->atributo) === $this->normalizarNome((string) ($esperado['atributo'] ?? ''))
                        && $this->normalizarValor((string) $registro->valor) === $this->normalizarValor((string) ($esperado['valor'] ?? ''));
                });

                if (! $intactos) {
                    $entradas[] = $this->finalizarEntrada($entrada, 'conflito_registro_alterado');
                    continue;
                }

                if (! $execute) {
                    $entradas[] = $this->finalizarEntrada($entrada, 'rollback_pendente');
                    continue;
                }

                foreach ($registros as $registro) {
                    $registro->delete();
                }
                foreach (($item['atributos_removidos'] ?? []) as $legado) {
                    $restaurado = ProdutoVariacaoAtributo::query()->create([
                        'id_variacao' => (int) ($item['produto_variacao_id'] ?? 0),
                        'atributo' => (string) ($legado['atributo'] ?? ''),
                        'valor' => (string) ($legado['valor'] ?? ''),
                    ]);
                    $entrada['atributos_restaurados'][] = $this->snapshotAtributo($restaurado);
                }

                $entradas[] = $this->finalizarEntrada($entrada, 'revertido');
            }

            $modo = $execute ? 'rollback' : 'rollback-dry-run';
            $rollback = $this->montarManifesto($modo, $importacaoId, $entradas, $manifestoOrigem);
            $caminho = $this->salvarManifesto($rollback, $importacaoId, $modo);

            return [$entradas, $caminho];
        };

        [$entradas, $caminho] = $execute ? DB::transaction($processar) : $processar();

        $this->exibirResumo($entradas, $execute ? 'Rollback concluido.' : 'Dry-run do rollback concluido.');
        $this->info("Manifesto: {$caminho}");

        return collect($entradas)->contains(fn (array $item) => $item['acao'] === 'conflito_registro_alterado')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function extrairModeloReferencia(array $dados, Collection $legados): ?string
    {
        foreach ((array) ($dados['atributos_raw'] ?? []) as $atributo) {
            if (! is_array($atributo)) {
                continue;
            }

            $nome = $this->normalizarNome((string) ($atributo['nome'] ?? $atributo['atributo'] ?? ''));
            $valor = trim((string) ($atributo['valor'] ?? ''));

            if ($nome === 'modelo_referencia' && $valor !== '') {
                return (string) Str::of($valor)->squish();
            }
        }

        $valorLegado = $legados->first()?->valor;

        return $valorLegado ? (string) Str::of((string) $valorLegado)->squish() : null;
    }

    private function snapshotAtributo(ProdutoVariacaoAtributo $atributo): array
    {
        return [
            'id' => (int) $atributo->id,
            'atributo' => (string) $atributo->atributo,
            'valor' => (string) $atributo->valor,
        ];
    }

    private function normalizarNome(string $nome): string
    {
        return (string) Str::of($nome)->squish()->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
    }

    private function normalizarValor(string $valor): string
    {
        return (string) Str::of($valor)->squish()->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->trim();
    }

    private function finalizarEntrada(array $entrada, string $acao): array
    {
        $entrada['acao'] = $acao;
        $entrada['checksum'] = $this->checksum($entrada);

        return $entrada;
    }

    private function montarManifesto(
        string $modo,
        int $importacaoId,
        array $itens,
        ?string $manifestoOrigem = null
    ): array {
        $manifesto = [
            'versao' => 2,
            'modo' => $modo,
            'pedido_importacao_id' => $importacaoId,
            'gerado_em' => now()->toIso8601String(),
            'manifesto_origem' => $manifestoOrigem,
            'itens' => array_values($itens),
        ];
        $manifesto['checksum'] = $this->checksum($manifesto);

        return $manifesto;
    }

    private function checksumManifestoValido(array $manifesto): bool
    {
        $checksum = (string) ($manifesto['checksum'] ?? '');
        unset($manifesto['checksum']);

        return $checksum !== '' && hash_equals($checksum, $this->checksum($manifesto));
    }

    private function checksum(array $dados): string
    {
        return hash('sha256', json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function salvarManifesto(array $manifesto, int $importacaoId, string $modo): string
    {
        $opcao = trim((string) $this->option('manifest'));
        $caminho = $opcao !== ''
            ? $opcao
            : sprintf(
                'correcoes/acabamentos-importacao/importacao-%d-%s-%s-%s.json',
                $importacaoId,
                $modo,
                now()->format('Ymd-His'),
                Str::lower((string) Str::uuid())
            );
        $this->validarCaminhoRelativo($caminho);

        if (Storage::disk('local')->exists($caminho)) {
            throw new RuntimeException('O manifesto de destino ja existe; informe outro caminho para preservar a evidencia anterior.');
        }

        $gravado = Storage::disk('local')->put(
            $caminho,
            json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        if (! $gravado) {
            throw new RuntimeException('Nao foi possivel gravar o manifesto; nenhuma transacao deve ser confirmada.');
        }

        return $caminho;
    }

    private function validarCaminhoRelativo(string $caminho): void
    {
        if ($caminho === '' || str_contains($caminho, '..') || Str::startsWith($caminho, ['/', '\\'])) {
            throw new RuntimeException('O caminho do manifesto deve ser relativo ao disco local e nao pode conter "..".');
        }
    }

    private function exibirResumo(array $entradas, string $titulo): void
    {
        $this->info($titulo);
        $this->table(
            ['Acao', 'Total'],
            collect($entradas)
                ->countBy('acao')
                ->sortKeys()
                ->map(fn (int $total, string $acao) => [$acao, $total])
                ->values()
                ->all()
        );
    }
}
