<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;

/**
 * #378 — „Signale": Aufmerksamkeits-Inbox (Klasse B). Erzeugen mit Dedup (kein
 * Dauerfeuer), Lifecycle (offen→erledigt|ignoriert), Inbox-Query. team-scoped.
 */
class SignalService
{
    /**
     * Erzeugt/aktualisiert ein Signal — idempotent über dedup_key: existiert bereits ein
     * OFFENES Signal mit gleicher (Team, typ, dedup_key, outlet_id)-Lane, wird es aktualisiert
     * statt dupliziert. opts: description, payload(array), dedup_key, ref_type, ref_id, source,
     * outlet_id. `outlet_id` (Ebene 2) ist Teil der Dedup-Identität: dasselbe Gericht kann in der
     * Team-Core-Lane (NULL) UND je Betrieb ein eigenes Signal tragen, ohne zu kollidieren.
     */
    public function erzeuge(Team $team, SignalTyp $typ, SignalSeverity $severity, string $titel, array $opts = []): FoodAlchemistSignal
    {
        // `title` ist VARCHAR(255) — lange Rezeptnamen (z. B. FIN-Aroma-Ketten) sprengen die Spalte
        // auf MySQL (SQLite erzwingt die Länge NICHT → in Tests unsichtbar). Defensiv kappen; der
        // volle Befund steht ohnehin in description/payload.
        $titel = mb_substr($titel, 0, 255);
        $outletId = isset($opts['outlet_id']) && $opts['outlet_id'] !== null ? (int) $opts['outlet_id'] : null;
        $dedup = $opts['dedup_key'] ?? null;
        if ($dedup !== null) {
            $vorhanden = FoodAlchemistSignal::where('team_id', $team->id)
                ->where('type', $typ->value)->where('dedup_key', $dedup)
                ->when($outletId === null, fn (Builder $q) => $q->whereNull('outlet_id'), fn (Builder $q) => $q->where('outlet_id', $outletId))
                ->where('status', SignalStatus::Offen->value)->first();
            if ($vorhanden !== null) {
                $vorhanden->update([
                    'severity' => $severity->value,
                    'title' => $titel,
                    'description' => $opts['description'] ?? $vorhanden->description,
                    'payload' => $opts['payload'] ?? $vorhanden->payload,
                    // V-009: die Wiederkehr ist der eigentliche Befund. Ein Signal, das nach
                    // jedem Fix zurückkommt, ist ein Prozess-Problem und kein Datenfehler —
                    // ohne Zähler sieht es aus wie jedes andere.
                    'last_seen_at' => now(),
                    'seen_count' => ((int) ($vorhanden->seen_count ?? 1)) + 1,
                ]);

                return $vorhanden->refresh();
            }
        }

        return FoodAlchemistSignal::create([
            'team_id' => $team->id,
            'outlet_id' => $outletId,
            'type' => $typ->value,
            'severity' => $severity->value,
            'status' => SignalStatus::Offen->value,
            'title' => $titel,
            'description' => $opts['description'] ?? null,
            'payload' => $opts['payload'] ?? null,
            'dedup_key' => $dedup,
            'ref_type' => $opts['ref_type'] ?? null,
            'ref_id' => $opts['ref_id'] ?? null,
            'source' => $opts['source'] ?? 'detektor',
            // Die Anlage IST die erste Sichtung — `seen_count = 1` ab Zeile eins, damit
            // „einmal gesehen" und „nie gezählt" nicht dieselbe Zahl sind.
            'last_seen_at' => now(),
            'seen_count' => 1,
        ]);
    }

    /**
     * V-011 — der Gegenzweig zum Emittieren: eine Lücke, die auf 0 gemessen wurde, schließt
     * ihr offenes Signal, statt es als Phantom stehen zu lassen.
     *
     * Bis hier war der eingebaute Fixer die **einzige** Schließ-Stelle (`SignalFixService`).
     * Wurde derselbe Befund auf anderem Weg behoben — Rezept von Hand korrigiert, Lead-LA im
     * GP-Editor gesetzt, Import-Nachlauf, Bulk-Skript —, blieb das offene Signal mit seinem
     * alten Titel („42 — …") stehen, bis ein Mensch es manuell abhakte. Zeitreihe (E1) und
     * Zustands-Zeile (E2) zählen aber **offene Zeilen**, nicht gemessene Lücken: das Phantom
     * hielt beide hoch, während die Ampel-Hälfte im selben Cockpit längst 0 zeigte.
     *
     * **`$source` ist Pflicht, nicht optional.** Geschlossen wird nur, was aus derselben
     * Quelle stammt, die gerade gemessen hat. Ampel und Detektor teilen sich Signal-Typen
     * (`DatenqualitaetGpLa` kommt aus beiden), und „Befund weg" ist auf der Detektor-Seite
     * je Detektor definiert und nicht generisch ableitbar (V-011, zweiter Absatz). Ohne den
     * Filter würde eine Ampel-Messung fremde Signale mit-abräumen — genau die stille
     * Verschiebung, gegen die dieser Umbau geschrieben ist.
     *
     * Der Grund landet im `payload` und **nicht** in `description`/`title`: beide Felder
     * werden angezeigt und tragen den Befund selbst; sie zu überschreiben würde die Historie
     * der Zeile beim Schließen löschen.
     *
     * @return int Anzahl geschlossener Signale (0 oder 1 im Normalfall — der Dedup lässt
     *             pro Team+Typ+Key nur eine offene Zeile zu)
     */
    public function schliesseGemessen(Team $team, SignalTyp $typ, string $dedupKey, string $source, string $grund, ?int $outletId = null): int
    {
        $offene = FoodAlchemistSignal::where('team_id', $team->id)
            ->where('type', $typ->value)
            ->where('dedup_key', $dedupKey)
            ->where('source', $source)
            // Ebene 2: strikt in der eigenen Lane schließen — der Team-Core-Lauf (NULL) darf keine
            // Betriebs-Signale abräumen und umgekehrt.
            ->when($outletId === null, fn (Builder $q) => $q->whereNull('outlet_id'), fn (Builder $q) => $q->where('outlet_id', $outletId))
            ->where('status', SignalStatus::Offen->value)
            ->get();

        foreach ($offene as $s) {
            $s->update([
                'status' => SignalStatus::Erledigt->value,
                'erledigt_at' => now(),
                'payload' => array_merge((array) ($s->payload ?? []), [
                    'auto_geschlossen' => $grund,
                    'auto_geschlossen_am' => now()->toIso8601String(),
                ]),
            ]);
        }

        return $offene->count();
    }

    /**
     * V-011 (Detektor-Seite): schließt alle OFFENEN Signale eines Typs aus derselben Quelle,
     * deren dedup_key im aktuellen Lauf NICHT (mehr) emittiert wurde — der Gegenzweig für die
     * per-Entität arbeitenden Geld-/Marge-Detektoren, die gesunde Fälle einfach nicht emittieren
     * (also kein `wert=0` je Key liefern wie die Datenqualitäts-Ampel). Ohne diesen Sweep bliebe
     * ein behobenes Geld-Signal („Marge unter Ziel" nach Preis-Fix) für immer offen im Postfach.
     *
     * Leere `$liveKeys` ⇒ es ist nichts mehr offen-würdig ⇒ alle offenen dieses Typs schließen.
     * **Nur der Aufrufer eines VOLLSTÄNDIGEN Laufs darf das rufen** — bei gecapptem/Teil-Lauf
     * würden fremde (nur nicht-geprüfte) Signale mit-abgeräumt.
     *
     * Ebene 2: `$outletId` grenzt den Sweep auf **eine** Lane ein — jeder Betriebs-Lauf räumt nur
     * seine eigenen Signale ab, der Team-Core-Lauf (NULL) nur die NULL-Lane. Ohne diese Grenze würde
     * der Team-Core-Lauf (dessen `$liveKeys` die Betriebs-Keys nicht enthält) die Betriebs-Signale
     * fälschlich als „verschwunden" schließen.
     *
     * @param  list<string>  $liveKeys  die in diesem Lauf emittierten dedup_keys
     * @return int  Anzahl geschlossener Signale
     */
    public function schliesseVerschwundene(Team $team, SignalTyp $typ, string $source, array $liveKeys, string $grund, ?int $outletId = null): int
    {
        $offene = FoodAlchemistSignal::where('team_id', $team->id)
            ->where('type', $typ->value)
            ->where('source', $source)
            ->when($outletId === null, fn (Builder $q) => $q->whereNull('outlet_id'), fn (Builder $q) => $q->where('outlet_id', $outletId))
            ->where('status', SignalStatus::Offen->value)
            ->whereNotNull('dedup_key')
            ->when($liveKeys !== [], fn ($q) => $q->whereNotIn('dedup_key', $liveKeys))
            ->get();

        foreach ($offene as $s) {
            $s->update([
                'status' => SignalStatus::Erledigt->value,
                'erledigt_at' => now(),
                'payload' => array_merge((array) ($s->payload ?? []), [
                    'auto_geschlossen' => $grund,
                    'auto_geschlossen_am' => now()->toIso8601String(),
                ]),
            ]);
        }

        return $offene->count();
    }

    public function abschliessen(Team $team, int $id): void
    {
        $s = FoodAlchemistSignal::where('team_id', $team->id)->findOrFail($id);
        $s->update(['status' => SignalStatus::Erledigt->value, 'erledigt_at' => now()]);
    }

    public function ignorieren(Team $team, int $id): void
    {
        $s = FoodAlchemistSignal::where('team_id', $team->id)->findOrFail($id);
        $s->update(['status' => SignalStatus::Ignoriert->value, 'ignoriert_at' => now()]);
    }

    public function wiederOeffnen(Team $team, int $id): void
    {
        $s = FoodAlchemistSignal::where('team_id', $team->id)->findOrFail($id);
        $s->update(['status' => SignalStatus::Offen->value, 'erledigt_at' => null, 'ignoriert_at' => null]);
    }

    /**
     * Inbox-Liste. `exclude_types` (Spec 21 · E2, Rausch-Guard) blendet Typen aus, die
     * als aggregierte Zustands-Zeile geführt werden — aber **nur** in der ungefilterten
     * Ansicht: sobald man den Typ explizit wählt, sind die Einzel-Signale wieder da.
     * Der Guard versteckt also, er löscht nicht.
     */
    public function paginate(array $filters, Team $team, int $perPage = 50, ?FoodAlchemistOutlet $outlet = null, bool $nurLane = false): LengthAwarePaginator
    {
        $status = $filters['status'] ?? SignalStatus::Offen->value;
        $typ = $filters['type'] ?? '';
        $exclude = $typ === '' ? ($filters['exclude_types'] ?? []) : [];

        return FoodAlchemistSignal::visibleToTeam($team)
            ->when($nurLane, fn ($q) => $q->lane($outlet))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($typ !== '', fn ($q) => $q->where('type', $typ))
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('type', $exclude))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function offeneCount(Team $team, ?FoodAlchemistOutlet $outlet = null, bool $nurLane = false): int
    {
        return FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->when($nurLane, fn ($q) => $q->lane($outlet))
            ->count();
    }

    /**
     * @return array<string,int> offene Signale je Typ. `$nurLane` (Ebene 2): auf die
     *                           Betriebsbrille eingrenzen (Betriebs-Lane + Team-Core-Lane).
     */
    public function offeneNachTyp(Team $team, ?FoodAlchemistOutlet $outlet = null, bool $nurLane = false): array
    {
        return FoodAlchemistSignal::visibleToTeam($team)->offen()
            ->when($nurLane, fn ($q) => $q->lane($outlet))
            ->selectRaw('type, COUNT(*) as c')->groupBy('type')->pluck('c', 'type')->all();
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
