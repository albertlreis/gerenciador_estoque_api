<?php

namespace App\Services;

use App\Contracts\GoogleCalendarSyncServiceInterface;
use App\Models\Evento;
use App\Models\EventoParticipante;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EventoService
{
    public function __construct(private readonly GoogleCalendarSyncServiceInterface $googleSync)
    {
    }

    public function listar(array $filtros = []): Collection
    {
        $from = $this->normalizeBoundary($filtros['from'] ?? null, false);
        $to = $this->normalizeBoundary($filtros['to'] ?? null, true);

        $query = Evento::query()
            ->with([
                'criador:id,nome,email',
                'participantes.usuario:id,nome,email',
            ])
            ->orderBy('inicio_em');

        if ($from !== null) {
            $query->where('fim_em', '>=', $from);
        }

        if ($to !== null) {
            $query->where('inicio_em', '<=', $to);
        }

        if (!empty($filtros['usuario_id'])) {
            $usuarioId = (int) $filtros['usuario_id'];

            $query->where(function (Builder $builder) use ($usuarioId) {
                $builder
                    ->where('criado_por', $usuarioId)
                    ->orWhereHas('participantes', fn (Builder $participantes) => $participantes->where('user_id', $usuarioId));
            });
        }

        return $query->get();
    }

    private function normalizeBoundary(?string $value, bool $endOfDay): ?CarbonImmutable
    {
        if (!$value) {
            return null;
        }

        $date = CarbonImmutable::parse($value);

        if (strlen($value) === 10) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    public function usuariosDisponiveis(): Collection
    {
        return Usuario::query()
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'email']);
    }

    public function criar(array $dados, int $usuarioId): Evento
    {
        return DB::transaction(function () use ($dados, $usuarioId) {
            $participantes = $dados['participantes'] ?? [];
            unset($dados['participantes']);

            $evento = Evento::create([
                ...$dados,
                'criado_por' => $usuarioId,
                'google_sync_status' => 'pending',
            ]);

            $this->syncParticipantes($evento, $participantes);
            $evento->load(['criador:id,nome,email', 'participantes.usuario:id,nome,email']);
            $this->googleSync->syncCreated($evento);

            return $evento;
        });
    }

    public function atualizar(Evento $evento, array $dados): Evento
    {
        return DB::transaction(function () use ($evento, $dados) {
            $participantes = $dados['participantes'] ?? null;
            unset($dados['participantes']);

            $evento->fill($dados);
            $evento->forceFill(['google_sync_status' => 'pending'])->save();

            if (is_array($participantes)) {
                $this->syncParticipantes($evento, $participantes);
            }

            $evento->load(['criador:id,nome,email', 'participantes.usuario:id,nome,email']);
            $this->googleSync->syncUpdated($evento);

            return $evento;
        });
    }

    public function excluir(Evento $evento): void
    {
        DB::transaction(function () use ($evento) {
            $evento->loadMissing('participantes');
            $evento->participantes()->delete();
            $this->googleSync->syncDeleted($evento);
            $evento->delete();
        });
    }

    public function adicionarParticipante(Evento $evento, array $dados): Evento
    {
        EventoParticipante::updateOrCreate(
            [
                'evento_id' => $evento->id,
                'user_id' => (int) $dados['user_id'],
            ],
            [
                'obrigatorio' => (bool) ($dados['obrigatorio'] ?? false),
                'status_confirmacao' => null,
                'created_at' => now(),
            ]
        );

        $evento->forceFill(['google_sync_status' => 'pending'])->save();
        $evento->load(['criador:id,nome,email', 'participantes.usuario:id,nome,email']);
        $this->googleSync->syncUpdated($evento);

        return $evento;
    }

    public function removerParticipante(Evento $evento, int $usuarioId): Evento
    {
        $evento->participantes()->where('user_id', $usuarioId)->delete();
        $evento->forceFill(['google_sync_status' => 'pending'])->save();
        $evento->load(['criador:id,nome,email', 'participantes.usuario:id,nome,email']);
        $this->googleSync->syncUpdated($evento);

        return $evento;
    }

    private function syncParticipantes(Evento $evento, array $participantes): void
    {
        $normalizados = collect($participantes)
            ->filter(fn ($item) => is_array($item) && !empty($item['user_id']))
            ->map(fn (array $item) => [
                'user_id' => (int) $item['user_id'],
                'obrigatorio' => (bool) ($item['obrigatorio'] ?? false),
            ])
            ->unique('user_id')
            ->values();

        $evento->participantes()->delete();

        if ($normalizados->isEmpty()) {
            return;
        }

        $evento->participantes()->createMany(
            $normalizados->map(fn (array $item) => [
                'user_id' => $item['user_id'],
                'obrigatorio' => $item['obrigatorio'],
                'status_confirmacao' => null,
                'created_at' => now(),
            ])->all()
        );
    }
}
