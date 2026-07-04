<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;

/**
 * #378 — „Signale": Aufmerksamkeits-Inbox (Klasse B). Erzeugen mit Dedup (kein
 * Dauerfeuer), Lifecycle (offen→erledigt|ignoriert), Inbox-Query. team-scoped.
 */
class SignalService
{
    /**
     * Erzeugt/aktualisiert ein Signal — idempotent über dedup_key: existiert bereits ein
     * OFFENES Signal mit gleichem (Team, typ, dedup_key), wird es aktualisiert statt
     * dupliziert. opts: description, payload(array), dedup_key, ref_type, ref_id, source.
     */
    public function erzeuge(Team $team, SignalTyp $typ, SignalSeverity $severity, string $titel, array $opts = []): FoodAlchemistSignal
    {
        $dedup = $opts['dedup_key'] ?? null;
        if ($dedup !== null) {
            $vorhanden = FoodAlchemistSignal::where('team_id', $team->id)
                ->where('typ', $typ->value)->where('dedup_key', $dedup)
                ->where('status', SignalStatus::Offen->value)->first();
            if ($vorhanden !== null) {
                $vorhanden->update([
                    'severity' => $severity->value,
                    'titel' => $titel,
                    'description' => $opts['description'] ?? $vorhanden->description,
                    'payload' => $opts['payload'] ?? $vorhanden->payload,
                ]);

                return $vorhanden->refresh();
            }
        }

        return FoodAlchemistSignal::create([
            'team_id' => $team->id,
            'typ' => $typ->value,
            'severity' => $severity->value,
            'status' => SignalStatus::Offen->value,
            'titel' => $titel,
            'description' => $opts['description'] ?? null,
            'payload' => $opts['payload'] ?? null,
            'dedup_key' => $dedup,
            'ref_type' => $opts['ref_type'] ?? null,
            'ref_id' => $opts['ref_id'] ?? null,
            'source' => $opts['source'] ?? 'detektor',
        ]);
    }

    public function abschliessen(Team $team, int $id): void
    {
        $s = FoodAlchemistSignal::visibleToTeam($team)->findOrFail($id);
        $s->update(['status' => SignalStatus::Erledigt->value, 'erledigt_at' => now()]);
    }

    public function ignorieren(Team $team, int $id): void
    {
        $s = FoodAlchemistSignal::visibleToTeam($team)->findOrFail($id);
        $s->update(['status' => SignalStatus::Ignoriert->value, 'ignoriert_at' => now()]);
    }

    public function wiederOeffnen(Team $team, int $id): void
    {
        $s = FoodAlchemistSignal::visibleToTeam($team)->findOrFail($id);
        $s->update(['status' => SignalStatus::Offen->value, 'erledigt_at' => null, 'ignoriert_at' => null]);
    }

    public function paginate(array $filters, Team $team, int $perPage = 50): LengthAwarePaginator
    {
        $status = $filters['status'] ?? SignalStatus::Offen->value;

        return FoodAlchemistSignal::visibleToTeam($team)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when(($filters['typ'] ?? '') !== '', fn ($q) => $q->where('typ', $filters['typ']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function offeneCount(Team $team): int
    {
        return FoodAlchemistSignal::visibleToTeam($team)->offen()->count();
    }

    /** @return array<string,int> offene Signale je Typ */
    public function offeneNachTyp(Team $team): array
    {
        return FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->selectRaw('typ, COUNT(*) as c')->groupBy('typ')->pluck('c', 'typ')->all();
    }

    /** @return list<array{value:string,label:string}> */
    public function typWerte(): array
    {
        return array_map(fn (SignalTyp $t) => ['value' => $t->value, 'label' => $t->label()], SignalTyp::cases());
    }

    /** @return list<array{value:string,label:string}> */
    public function statusWerte(): array
    {
        return array_map(fn (SignalStatus $s) => ['value' => $s->value, 'label' => $s->label()], SignalStatus::cases());
    }
}
