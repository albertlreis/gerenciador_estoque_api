<?php

namespace App\Services;

use App\Models\Parceiro;
use App\Models\ParceiroContato;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ParceiroService
{
    /**
     * @param array{
     *   q?: string|null,
     *   status?: int|string|null,
     *   order_by?: 'nome'|'consultor_nome'|'nivel_fidelidade'|'created_at'|'updated_at'|null,
     *   order_dir?: 'asc'|'desc'|null,
     *   per_page?: int|null,
     *   page?: int|null,
     *   with_trashed?: bool|null
     * } $filtros
     */
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Parceiro::query()->with(['contatos' => function ($q) {
            $q->orderByDesc('principal')->orderBy('id');
        }]);

        $withTrashed = false;
        if (array_key_exists('with_trashed', $filtros)) {
            $withTrashed = filter_var($filtros['with_trashed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        }
        if ($withTrashed) {
            $query->withTrashed();
        }

        if (!empty($filtros['q'])) {
            $q = trim($filtros['q']);
            $digits = preg_replace('/\D+/', '', $q);
            $query->where(function (Builder $qb) use ($q, $digits) {
                $qb->where('nome', 'like', "%{$q}%")
                    ->orWhere('documento', 'like', "%{$digits}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('telefone', 'like', "%{$q}%")
                    ->orWhereHas('contatos', function (Builder $contatosQb) use ($q) {
                        $contatosQb->where('valor', 'like', "%{$q}%")
                            ->orWhere('valor_e164', 'like', "%{$q}%");
                    });
            });
        }

        $status = $filtros['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $orderBy = $filtros['order_by'] ?? 'nome';
        $orderDir = $filtros['order_dir'] ?? 'asc';
        $perPage = (int) ($filtros['per_page'] ?? 20);

        $query->orderBy($orderBy, $orderDir);

        return $query->paginate($perPage);
    }

    public function obter(int $id): Parceiro
    {
        /** @var Parceiro $parceiro */
        $parceiro = Parceiro::withTrashed()
            ->with(['contatos' => function ($q) {
                $q->orderByDesc('principal')->orderBy('id');
            }])
            ->findOrFail($id);

        return $parceiro;
    }

    public function criar(array $dados): Parceiro
    {
        return DB::transaction(function () use ($dados) {
            $dadosParceiro = $this->extractParceiroData($dados);
            $contatos = $this->normalizeContatosFromPayload($dados);

            $parceiro = Parceiro::create($dadosParceiro);
            $this->syncContatosReplaceAll($parceiro, $contatos);
            $this->syncLegacyColumnsFromContatos($parceiro);

            return $parceiro->load(['contatos' => function ($q) {
                $q->orderByDesc('principal')->orderBy('id');
            }]);
        });
    }

    public function atualizar(int $id, array $dados): Parceiro
    {
        return DB::transaction(function () use ($id, $dados) {
            $parceiro = Parceiro::withTrashed()
                ->with(['contatos' => function ($q) {
                    $q->orderByDesc('principal')->orderBy('id');
                }])
                ->findOrFail($id);

            $dadosParceiro = $this->extractParceiroData($dados);
            $parceiro->fill($dadosParceiro)->save();

            $hasContatosArray = array_key_exists('contatos', $dados);
            $hasLegacyContatoKeys = array_key_exists('email', $dados) || array_key_exists('telefone', $dados);

            if ($hasContatosArray) {
                $contatos = $this->normalizeContatosFromPayload($dados);
                $this->syncContatosReplaceAll($parceiro, $contatos);
                $this->syncLegacyColumnsFromContatos($parceiro);
            } elseif ($hasLegacyContatoKeys) {
                $contatos = $this->normalizeContatosFromPayload($dados);
                $tipos = [];
                if (array_key_exists('email', $dados)) {
                    $tipos[] = 'email';
                }
                if (array_key_exists('telefone', $dados)) {
                    $tipos[] = 'telefone';
                }

                $this->syncContatosByTipos($parceiro, $contatos, $tipos);
                $this->syncLegacyColumnsFromContatos($parceiro);
            }

            return $parceiro->fresh(['contatos' => function ($q) {
                $q->orderByDesc('principal')->orderBy('id');
            }]);
        });
    }

    public function remover(int $id): void
    {
        $parceiro = Parceiro::findOrFail($id);
        $parceiro->delete();
    }

    public function restaurar(int $id): Parceiro
    {
        $parceiro = Parceiro::withTrashed()->findOrFail($id);
        if ($parceiro->trashed()) {
            $parceiro->restore();
        }

        return $parceiro->load(['contatos' => function ($q) {
            $q->orderByDesc('principal')->orderBy('id');
        }]);
    }

    private function extractParceiroData(array $dados): array
    {
        $payload = Arr::except($dados, ['contatos']);

        return Arr::only($payload, [
            'nome',
            'tipo',
            'documento',
            'email',
            'telefone',
            'consultor_nome',
            'nivel_fidelidade',
            'endereco',
            'status',
            'observacoes',
        ]);
    }

    /**
     * @return array<int, array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}>
     */
    private function normalizeContatosFromPayload(array $dados): array
    {
        $contatos = [];

        $contatosPayload = $dados['contatos'] ?? [];
        if (is_array($contatosPayload)) {
            foreach ($contatosPayload as $contato) {
                if (!is_array($contato)) {
                    continue;
                }

                $normalizado = $this->normalizeContato($contato);
                if ($normalizado !== null) {
                    $contatos[] = $normalizado;
                }
            }
        }

        if (array_key_exists('email', $dados)) {
            $email = $this->normalizeEmail($dados['email']);
            if ($email !== null) {
                $contatos[] = [
                    'tipo' => 'email',
                    'valor' => $email,
                    'valor_e164' => null,
                    'rotulo' => 'principal',
                    'principal' => true,
                    'observacoes' => null,
                ];
            }
        }

        if (array_key_exists('telefone', $dados)) {
            $telefone = $this->normalizePhoneHuman($dados['telefone']);
            if ($telefone !== null) {
                $contatos[] = [
                    'tipo' => 'telefone',
                    'valor' => $telefone,
                    'valor_e164' => $this->normalizePhoneE164($telefone),
                    'rotulo' => 'principal',
                    'principal' => true,
                    'observacoes' => null,
                ];
            }
        }

        return $this->ensureSinglePrincipalPerTipo($contatos);
    }

    /**
     * @param array<string,mixed> $contato
     * @return array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}|null
     */
    private function normalizeContato(array $contato): ?array
    {
        $tipo = strtolower(trim((string) ($contato['tipo'] ?? '')));
        if (!in_array($tipo, ['email', 'telefone', 'outro'], true)) {
            return null;
        }

        $valorRaw = isset($contato['valor']) ? trim((string) $contato['valor']) : '';
        if ($valorRaw === '') {
            return null;
        }

        $valor = $valorRaw;
        $valorE164 = null;

        if ($tipo === 'email') {
            $valor = $this->normalizeEmail($valorRaw) ?? '';
            if ($valor === '') {
                return null;
            }
        }

        if ($tipo === 'telefone') {
            $valor = $this->normalizePhoneHuman($valorRaw) ?? '';
            if ($valor === '') {
                return null;
            }

            $valorE164Input = isset($contato['valor_e164']) ? trim((string) $contato['valor_e164']) : '';
            $valorE164 = $this->normalizePhoneE164($valorE164Input !== '' ? $valorE164Input : $valor);
        }

        return [
            'tipo' => $tipo,
            'valor' => $valor,
            'valor_e164' => $valorE164,
            'rotulo' => isset($contato['rotulo']) ? trim((string) $contato['rotulo']) : null,
            'principal' => !empty($contato['principal']),
            'observacoes' => isset($contato['observacoes']) ? trim((string) $contato['observacoes']) : null,
        ];
    }

    /**
     * @param array<int, array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}> $contatos
     * @return array<int, array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}>
     */
    private function ensureSinglePrincipalPerTipo(array $contatos): array
    {
        $porTipo = [];
        foreach ($contatos as $index => $contato) {
            $porTipo[$contato['tipo']][] = $index;
        }

        foreach ($porTipo as $indexes) {
            $principalIndex = null;
            foreach ($indexes as $idx) {
                if ($contatos[$idx]['principal']) {
                    $principalIndex = $idx;
                    break;
                }
            }

            if ($principalIndex === null && count($indexes) > 0) {
                $principalIndex = $indexes[0];
            }

            foreach ($indexes as $idx) {
                $contatos[$idx]['principal'] = $idx === $principalIndex;
            }
        }

        return $contatos;
    }

    /**
     * @param array<int, array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}> $contatos
     */
    private function syncContatosReplaceAll(Parceiro $parceiro, array $contatos): void
    {
        $contatos = $this->ensureSinglePrincipalPerTipo($contatos);

        $fingerprintsPayload = collect($contatos)
            ->map(fn(array $c) => $this->contactFingerprint($c['tipo'], $c['valor']))
            ->values()
            ->all();

        $ativos = $parceiro->contatos()->whereNull('deleted_at')->get();

        foreach ($ativos as $existente) {
            $fingerprint = $this->contactFingerprint($existente->tipo, $existente->valor);
            if (!in_array($fingerprint, $fingerprintsPayload, true)) {
                $existente->delete();
            }
        }

        $this->upsertContatos($parceiro, $contatos);
    }

    /**
     * @param array<int, array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}> $contatos
     * @param array<int, string> $tipos
     */
    private function syncContatosByTipos(Parceiro $parceiro, array $contatos, array $tipos): void
    {
        $tipos = array_values(array_unique(array_filter($tipos)));
        if (count($tipos) === 0) {
            return;
        }

        foreach ($tipos as $tipo) {
            $payloadTipo = array_values(array_filter($contatos, fn(array $c) => $c['tipo'] === $tipo));
            if (count($payloadTipo) === 0) {
                $parceiro->contatos()
                    ->where('tipo', $tipo)
                    ->whereNull('deleted_at')
                    ->delete();
                continue;
            }

            $fingerprintsPayload = collect($payloadTipo)
                ->map(fn(array $c) => $this->contactFingerprint($c['tipo'], $c['valor']))
                ->values()
                ->all();

            $ativosTipo = $parceiro->contatos()
                ->where('tipo', $tipo)
                ->whereNull('deleted_at')
                ->get();

            foreach ($ativosTipo as $existente) {
                $fingerprint = $this->contactFingerprint($existente->tipo, $existente->valor);
                if (!in_array($fingerprint, $fingerprintsPayload, true)) {
                    $existente->delete();
                }
            }

            $this->upsertContatos($parceiro, $payloadTipo);
        }
    }

    /**
     * @param array<int, array{tipo:string,valor:string,valor_e164:?string,rotulo:?string,principal:bool,observacoes:?string}> $contatos
     */
    private function upsertContatos(Parceiro $parceiro, array $contatos): void
    {
        foreach ($contatos as $contato) {
            $existente = $parceiro->contatos()
                ->where('tipo', $contato['tipo'])
                ->where('valor', $contato['valor'])
                ->whereNull('deleted_at')
                ->first();

            if ($existente instanceof ParceiroContato) {
                $existente->fill([
                    'valor_e164' => $contato['valor_e164'],
                    'rotulo' => $contato['rotulo'],
                    'principal' => $contato['principal'],
                    'observacoes' => $contato['observacoes'],
                ])->save();
                continue;
            }

            $parceiro->contatos()->create($contato);
        }

        $this->normalizePrincipalFlags($parceiro);
    }

    private function normalizePrincipalFlags(Parceiro $parceiro): void
    {
        $contatos = $parceiro->contatos()
            ->whereNull('deleted_at')
            ->orderByDesc('principal')
            ->orderBy('id')
            ->get()
            ->groupBy('tipo');

        foreach ($contatos as $grupo) {
            $firstPrincipalId = null;
            foreach ($grupo as $contato) {
                if ($contato->principal) {
                    $firstPrincipalId = $contato->id;
                    break;
                }
            }

            if ($firstPrincipalId === null) {
                $firstPrincipalId = $grupo->first()?->id;
            }

            foreach ($grupo as $contato) {
                $mustBePrincipal = $contato->id === $firstPrincipalId;
                if ((bool) $contato->principal !== $mustBePrincipal) {
                    $contato->principal = $mustBePrincipal;
                    $contato->save();
                }
            }
        }
    }

    private function syncLegacyColumnsFromContatos(Parceiro $parceiro): void
    {
        $contatos = $parceiro->contatos()
            ->whereNull('deleted_at')
            ->orderByDesc('principal')
            ->orderBy('id')
            ->get();

        $email = $this->pickContatoValorByTipo($contatos, 'email');
        $telefone = $this->pickContatoValorByTipo($contatos, 'telefone');

        $parceiro->forceFill([
            'email' => $email,
            'telefone' => $telefone,
        ])->save();
    }

    private function pickContatoValorByTipo(Collection $contatos, string $tipo): ?string
    {
        $porTipo = $contatos->where('tipo', $tipo);
        if ($porTipo->isEmpty()) {
            return null;
        }

        $principal = $porTipo->firstWhere('principal', true);
        if ($principal) {
            return $principal->valor;
        }

        return $porTipo->first()?->valor;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $email = strtolower(trim((string) $value));
        return $email !== '' ? $email : null;
    }

    private function normalizePhoneHuman(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        return $v;
    }

    private function normalizePhoneE164(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 8 || strlen($digits) === 9) {
            $digits = '91' . $digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 11) {
            return null;
        }

        return '+55' . $digits;
    }

    private function contactFingerprint(string $tipo, string $valor): string
    {
        return mb_strtolower($tipo . '|' . trim($valor));
    }
}
