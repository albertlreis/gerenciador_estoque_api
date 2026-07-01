<?php

namespace App\Services;

use App\Support\Logging\SierraLog;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsuarioPreferenciaService
{
    private const SCREEN_PREFIX = 'screen:';
    private const VERSION = 1;

    public function obterTela(int $usuarioId, string $screenKey): array
    {
        if (! $this->tabelaDisponivel()) {
            return $this->preferenciaPadrao();
        }

        $valor = DB::table('usuario_preferencias')
            ->where('usuario_id', $usuarioId)
            ->where('chave', $this->chaveTela($screenKey))
            ->value('valor');

        if ($valor === null || $valor === '') {
            return $this->preferenciaPadrao();
        }

        $decoded = is_array($valor) ? $valor : json_decode((string) $valor, true);

        return is_array($decoded)
            ? $this->normalizarPreferencia($decoded)
            : $this->preferenciaPadrao();
    }

    public function atualizarTela(int $usuarioId, string $screenKey, array $payload): array
    {
        $this->garantirTabelaDisponivel();

        $atual = $this->obterTela($usuarioId, $screenKey);
        $proxima = $atual;

        if (array_key_exists('filters', $payload)) {
            $proxima['filters'] = is_array($payload['filters']) ? $payload['filters'] : [];
        }

        if (array_key_exists('tables', $payload)) {
            $tables = is_array($payload['tables']) ? $payload['tables'] : [];
            foreach ($tables as $tableKey => $tableConfig) {
                if (! is_string($tableKey) || ! is_array($tableConfig)) {
                    continue;
                }

                $proxima['tables'][$tableKey] = array_replace_recursive(
                    $proxima['tables'][$tableKey] ?? [],
                    $tableConfig
                );
            }
        }

        $proxima['version'] = self::VERSION;
        $proxima = $this->normalizarPreferencia($proxima);
        $now = now();

        DB::table('usuario_preferencias')->upsert(
            [[
                'usuario_id' => $usuarioId,
                'chave' => $this->chaveTela($screenKey),
                'valor' => json_encode($proxima),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['usuario_id', 'chave'],
            ['valor', 'updated_at']
        );

        return $proxima;
    }

    public function removerTela(int $usuarioId, string $screenKey): void
    {
        $this->garantirTabelaDisponivel();

        DB::table('usuario_preferencias')
            ->where('usuario_id', $usuarioId)
            ->where('chave', $this->chaveTela($screenKey))
            ->delete();
    }

    public function preferenciaPadrao(): array
    {
        return [
            'version' => self::VERSION,
            'filters' => [],
            'tables' => [],
        ];
    }

    private function chaveTela(string $screenKey): string
    {
        return self::SCREEN_PREFIX . $screenKey;
    }

    private function normalizarPreferencia(array $preferencia): array
    {
        $tables = [];
        foreach (($preferencia['tables'] ?? []) as $tableKey => $config) {
            if (! is_string($tableKey) || ! preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $tableKey) || ! is_array($config)) {
                continue;
            }

            $hiddenColumns = array_values(array_unique(array_filter(
                array_map('strval', $config['hidden_columns'] ?? []),
                fn (string $field) => preg_match('/\A[A-Za-z0-9_.:-]{1,120}\z/', $field)
            )));

            $table = [
                'hidden_columns' => $hiddenColumns,
            ];

            if (isset($config['first']) && is_numeric($config['first'])) {
                $first = (int) $config['first'];
                if ($first >= 0) {
                    $table['first'] = $first;
                }
            }

            if (isset($config['rows']) && is_numeric($config['rows'])) {
                $rows = (int) $config['rows'];
                if ($rows > 0 && $rows <= 500) {
                    $table['rows'] = $rows;
                }
            }

            if (isset($config['sort']) && is_array($config['sort'])) {
                $field = isset($config['sort']['field']) ? (string) $config['sort']['field'] : null;
                $order = $config['sort']['order'] ?? null;
                if ($field && preg_match('/\A[A-Za-z0-9_.:-]{1,120}\z/', $field) && in_array($order, ['asc', 'desc', 1, -1, '1', '-1'], true)) {
                    $table['sort'] = [
                        'field' => $field,
                        'order' => in_array($order, ['asc', 1, '1'], true) ? 'asc' : 'desc',
                    ];
                }
            }

            $tables[$tableKey] = $table;
        }

        return [
            'version' => self::VERSION,
            'filters' => is_array($preferencia['filters'] ?? null) ? $preferencia['filters'] : [],
            'tables' => $tables,
        ];
    }

    private function tabelaDisponivel(): bool
    {
        try {
            return Schema::hasTable('usuario_preferencias');
        } catch (\Throwable $exception) {
            SierraLog::system('user_preferences.table_check_failed', [
                'operation' => 'schema_check',
                'exception' => $exception,
            ], 'warning');

            return false;
        }
    }

    private function garantirTabelaDisponivel(): void
    {
        if ($this->tabelaDisponivel()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Preferencias de usuario ainda nao estao disponiveis. Execute as migrations e tente novamente.',
        ], 503));
    }
}
