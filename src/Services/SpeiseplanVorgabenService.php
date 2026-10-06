<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanChip;

/**
 * Spec 59: Speiseplan-Vorgaben — Chip-Katalog (Einstellungen, je Team) + Vorgaben je Plan
 * (mind./höchstens je Woche, optional je Mahlzeit) + deren Auswertung.
 *
 * EINE Stelle für Normalisierung und Validierung: Settings-Seite, Editor und MCP
 * (`speiseplan_chips.*`, `speiseplaene.PUT` mit `vorgaben`) rufen dieselben Methoden —
 * keine zweite Validierung im Tool oder in der Komponente.
 *
 * Bewusst ein eigener Service neben SpeiseplanService: die Vorgaben sind ein abgeschlossenes
 * Thema (Katalog + Regel + Prüfung); SpeiseplanService liefert nur die Gericht-Merkmale der
 * Woche ({@see SpeiseplanService::wochenAbwechslung}) und reicht sie zur Prüfung hierher.
 */
class SpeiseplanVorgabenService
{
    /** Startsatz für „Standard-Chips anlegen“ — nur auf Knopfdruck, nie automatisch geseedet. */
    public const STANDARD_CHIPS = [
        ['label' => 'Vegan', 'key' => 'vegan'],
        ['label' => 'Vegetarisch', 'key' => 'vegetarisch'],
        ['label' => 'Fleisch', 'key' => 'fleisch'],
        ['label' => 'Fisch', 'key' => 'fisch'],
        ['label' => 'Schwein', 'key' => 'schwein'],
    ];

    // ── Katalog ──────────────────────────────────────────────────────────────

    /**
     * Sichtbarer Katalog (eigenes Team + Eltern-Kette, D1), sortiert.
     *
     * @return Collection<int, FoodAlchemistSpeiseplanChip>
     */
    public function katalog(Team $team, bool $nurAktive = false): Collection
    {
        return FoodAlchemistSpeiseplanChip::visibleToTeam($team)
            ->when($nurAktive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('label')->get();
    }

    public function chipAnlegen(Team $team, array $in): FoodAlchemistSpeiseplanChip
    {
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '') {
            throw new \RuntimeException('Bezeichnung ist Pflicht.');
        }
        $kriterien = $this->normKriterien($team, $in['kriterien'] ?? []);
        [$min, $max] = $this->normMinMax($in['default_min'] ?? null, $in['default_max'] ?? null);

        return FoodAlchemistSpeiseplanChip::create([
            'team_id' => $team->id,
            'label' => $label,
            'kriterien' => $kriterien,
            'default_min' => $min,
            'default_max' => $max,
            'sort_order' => array_key_exists('sort_order', $in) && $in['sort_order'] !== null && $in['sort_order'] !== ''
                ? max(0, (int) $in['sort_order'])
                : (int) FoodAlchemistSpeiseplanChip::where('team_id', $team->id)->max('sort_order') + 10,
            'is_active' => array_key_exists('is_active', $in) ? (bool) $in['is_active'] : true,
        ]);
    }

    /**
     * Felder eines EIGENEN Chips ändern (Allow-List). Geerbte Chips sind Vorlagen (D1).
     * Kein Löschen — siehe Model-Docblock (Lösch-Schutz).
     */
    public function chipAendern(Team $team, int $id, array $in): FoodAlchemistSpeiseplanChip
    {
        $chip = FoodAlchemistSpeiseplanChip::visibleToTeam($team)->find($id);
        if ($chip === null) {
            throw new \RuntimeException('Chip nicht gefunden.');
        }
        if (! $chip->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Chip — bearbeiten nur im Eltern-Team (D1).');
        }

        if (array_key_exists('label', $in)) {
            $label = trim((string) $in['label']);
            if ($label === '') {
                throw new \RuntimeException('Bezeichnung darf nicht leer sein.');
            }
            $chip->label = $label;
        }
        if (array_key_exists('kriterien', $in)) {
            $chip->kriterien = $this->normKriterien($team, $in['kriterien']);
        }
        if (array_key_exists('default_min', $in) || array_key_exists('default_max', $in)) {
            [$min, $max] = $this->normMinMax(
                array_key_exists('default_min', $in) ? $in['default_min'] : $chip->default_min,
                array_key_exists('default_max', $in) ? $in['default_max'] : $chip->default_max,
            );
            $chip->default_min = $min;
            $chip->default_max = $max;
        }
        if (array_key_exists('sort_order', $in)) {
            $chip->sort_order = max(0, (int) $in['sort_order']);
        }
        if (array_key_exists('is_active', $in)) {
            $chip->is_active = (bool) $in['is_active'];
        }
        $chip->save();

        return $chip->refresh();
    }

    /** Startsatz anlegen — nur, wenn das Team noch KEINE eigenen Chips hat. Liefert die Anzahl. */
    public function standardChipsAnlegen(Team $team): int
    {
        if (FoodAlchemistSpeiseplanChip::where('team_id', $team->id)->exists()) {
            return 0;
        }
        foreach (self::STANDARD_CHIPS as $i => $c) {
            FoodAlchemistSpeiseplanChip::create([
                'team_id' => $team->id, 'label' => $c['label'],
                'kriterien' => [['art' => 'diaet', 'key' => $c['key']]],
                'sort_order' => ($i + 1) * 10, 'is_active' => true,
            ]);
        }

        return count(self::STANDARD_CHIPS);
    }

    /**
     * Kriterien normalisieren: Ernährungsform aus der festen Liste, Hauptgruppe muss für das
     * Team sichtbar sein. Doppelte Bedingungen fallen weg. Leer = Fehler (ein Chip ohne
     * Kriterium zählt nie etwas).
     *
     * @return list<array{art:string, key?:string, id?:int}>
     */
    public function normKriterien(Team $team, mixed $roh): array
    {
        if (! is_array($roh)) {
            throw new \RuntimeException('Kriterien müssen eine Liste sein.');
        }
        $out = [];
        foreach ($roh as $k) {
            if (! is_array($k)) {
                throw new \RuntimeException('Ungültiges Kriterium.');
            }
            $art = (string) ($k['art'] ?? '');
            if ($art === 'diaet') {
                $key = (string) ($k['key'] ?? '');
                if (! array_key_exists($key, FoodAlchemistSpeiseplanChip::DIAETEN)) {
                    throw new \RuntimeException('Unbekannte Ernährungsform „' . $key . '“ (erlaubt: ' . implode(', ', array_keys(FoodAlchemistSpeiseplanChip::DIAETEN)) . ').');
                }
                $out['diaet:' . $key] = ['art' => 'diaet', 'key' => $key];
            } elseif ($art === 'hauptgruppe') {
                $id = (int) ($k['id'] ?? 0);
                if ($id <= 0 || ! FoodAlchemistDishMainGroup::visibleToTeam($team)->whereKey($id)->exists()) {
                    throw new \RuntimeException('Hauptgruppe #' . $id . ' ist für dieses Team nicht sichtbar.');
                }
                $out['hauptgruppe:' . $id] = ['art' => 'hauptgruppe', 'id' => $id];
            } else {
                throw new \RuntimeException('Unbekannte Kriterien-Art „' . $art . '“ (erlaubt: diaet, hauptgruppe).');
            }
        }
        if ($out === []) {
            throw new \RuntimeException('Mindestens ein Kriterium wählen (Ernährungsform oder Hauptgruppe).');
        }

        return array_values($out);
    }

    // ── Vorgaben je Plan ─────────────────────────────────────────────────────

    /**
     * Vorgaben normalisieren + validieren (UI und MCP): Chip muss für das Team sichtbar sein,
     * Mahlzeit null (= jede Mahlzeit einzeln) oder ein Schlüssel aus MAHLZEITEN, min/max
     * ganzzahlig ≥ 0, min ≤ max, mindestens eine Grenze, keine Dublette Chip × Mahlzeit.
     *
     * @return list<array{chip_id:int, mahlzeit:?string, min:?int, max:?int}>
     */
    public function normVorgaben(Team $team, mixed $roh): array
    {
        if ($roh === null || $roh === '') {
            return [];
        }
        if (! is_array($roh)) {
            throw new \RuntimeException('vorgaben muss eine Liste sein.');
        }
        $sichtbar = FoodAlchemistSpeiseplanChip::visibleToTeam($team)->pluck('label', 'id')->all();
        $out = [];
        $gesehen = [];
        foreach (array_values($roh) as $i => $v) {
            $nr = 'Vorgabe ' . ($i + 1);
            if (! is_array($v)) {
                throw new \RuntimeException($nr . ': ungültig.');
            }
            $chipId = (int) ($v['chip_id'] ?? 0);
            if (! array_key_exists($chipId, $sichtbar)) {
                throw new \RuntimeException($nr . ': Chip #' . $chipId . ' gehört nicht zu diesem Team.');
            }
            $mahlzeit = $v['mahlzeit'] ?? null;
            $mahlzeit = ($mahlzeit === null || $mahlzeit === '') ? null : (string) $mahlzeit;
            if ($mahlzeit !== null && ! array_key_exists($mahlzeit, SpeiseplanService::MAHLZEITEN)) {
                throw new \RuntimeException($nr . ': unbekannte Mahlzeit „' . $mahlzeit . '“.');
            }
            [$min, $max] = $this->normMinMax($v['min'] ?? null, $v['max'] ?? null, $nr . ' (' . $sichtbar[$chipId] . ')');
            if ($min === null && $max === null) {
                throw new \RuntimeException($nr . ' (' . $sichtbar[$chipId] . '): mind. oder höchstens angeben.');
            }
            $schluessel = $chipId . '|' . ($mahlzeit ?? '*');
            if (isset($gesehen[$schluessel])) {
                throw new \RuntimeException($nr . ': „' . $sichtbar[$chipId] . '“ ist für diese Mahlzeit schon vorgegeben.');
            }
            $gesehen[$schluessel] = true;
            $out[] = ['chip_id' => $chipId, 'mahlzeit' => $mahlzeit, 'min' => $min, 'max' => $max];
        }

        return $out;
    }

    /** Vorgaben eines EIGENEN Plans ersetzen (leer = keine Vorgaben). */
    public function setzeVorgaben(Team $team, int $planId, mixed $roh): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        if (! $plan->isOwnedBy($team)) {
            throw new \RuntimeException('Speiseplan gehört nicht diesem Team (D1).');
        }
        $vorgaben = $this->normVorgaben($team, $roh);
        $plan->update(['vorgaben' => $vorgaben === [] ? null : $vorgaben]);

        return $plan->refresh();
    }

    /**
     * Neue Vorgabe aus einem Chip: übernimmt dessen Standardwerte (ohne Standard: mind. 1).
     *
     * @return array{chip_id:int, mahlzeit:?string, min:?int, max:?int}
     */
    public function vorgabeAusChip(FoodAlchemistSpeiseplanChip $chip, ?string $mahlzeit = null): array
    {
        $min = $chip->default_min;
        $max = $chip->default_max;
        if ($min === null && $max === null) {
            $min = 1;
        }

        return ['chip_id' => (int) $chip->id, 'mahlzeit' => $mahlzeit ?: null, 'min' => $min, 'max' => $max];
    }

    // ── Auswertung ───────────────────────────────────────────────────────────

    /** Trifft eine der ODER-Bedingungen auf ein Gericht zu? */
    public function chipTrifft(array $kriterien, array $diaet, ?int $hauptgruppeId): bool
    {
        foreach ($kriterien as $k) {
            if (($k['art'] ?? null) === 'diaet' && in_array($k['key'] ?? null, $diaet, true)) {
                return true;
            }
            if (($k['art'] ?? null) === 'hauptgruppe' && $hauptgruppeId !== null && (int) ($k['id'] ?? 0) === $hauptgruppeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die anwendbaren Vorgaben (Mahlzeit null oder = gewählte) gegen die Gerichte der Woche
     * prüfen. Zählbasis = je Gericht-Vorkommen (ein Paket mit 3 Gerichten zählt 3).
     *
     * @param  list<array{entry_id:int, gericht_id:int, diaet:list<string>, hauptgruppe:?int}>  $paare
     * @return array{vorgaben: list<array{chip_id:int, label:string, mahlzeit:?string, ist:int, min:?int, max:?int, status:string, gericht_ids:list<int>, eintrag_ids:list<int>}>, treffer: array<int, list<int>>}
     */
    public function auswerten(FoodAlchemistSpeiseplan $plan, string $mahlzeit, array $paare): array
    {
        $vorgaben = array_values(array_filter(
            (array) ($plan->vorgaben ?? []),
            fn ($v) => is_array($v) && (($v['mahlzeit'] ?? null) === null || ($v['mahlzeit'] ?? null) === $mahlzeit),
        ));
        if ($vorgaben === []) {
            return ['vorgaben' => [], 'treffer' => []];
        }
        $chips = FoodAlchemistSpeiseplanChip::whereIn('id', array_map(fn ($v) => (int) ($v['chip_id'] ?? 0), $vorgaben))
            ->get()->keyBy('id');

        $out = [];
        $treffer = [];
        foreach ($vorgaben as $v) {
            $chip = $chips->get((int) ($v['chip_id'] ?? 0));
            if ($chip === null) {
                continue;   // Chip verschwunden (nur per DB möglich) — Vorgabe still überspringen statt zu werfen
            }
            $ist = 0;
            $gerichte = [];
            $eintraege = [];
            foreach ($paare as $p) {
                if ($this->chipTrifft((array) $chip->kriterien, $p['diaet'], $p['hauptgruppe'])) {
                    $ist++;
                    $gerichte[$p['gericht_id']] = true;
                    $eintraege[$p['entry_id']] = true;
                }
            }
            $min = isset($v['min']) ? (int) $v['min'] : null;
            $max = isset($v['max']) ? (int) $v['max'] : null;
            $status = match (true) {
                $min !== null && $ist < $min => 'zu_wenig',
                $max !== null && $ist > $max => 'zu_viel',
                default => 'ok',
            };
            $treffer[(int) $chip->id] = array_keys($eintraege);
            $out[] = [
                'chip_id' => (int) $chip->id, 'label' => (string) $chip->label, 'mahlzeit' => $v['mahlzeit'] ?? null,
                'ist' => $ist, 'min' => $min, 'max' => $max, 'status' => $status,
                'gericht_ids' => array_keys($gerichte), 'eintrag_ids' => array_keys($eintraege),
            ];
        }

        return ['vorgaben' => $out, 'treffer' => $treffer];
    }

    /**
     * min/max lesen: leer ⇒ null, sonst ganzzahlig ≥ 0, min ≤ max.
     *
     * @return array{0:?int, 1:?int}
     */
    private function normMinMax(mixed $min, mixed $max, string $kontext = 'Standardwert'): array
    {
        $lies = function (mixed $wert, string $feld) use ($kontext): ?int {
            if ($wert === null || (is_string($wert) && trim($wert) === '')) {
                return null;
            }
            if (! is_numeric($wert) || (float) $wert != (int) $wert) {
                throw new \RuntimeException($kontext . ': ' . $feld . ' muss eine ganze Zahl sein.');
            }
            if ((int) $wert < 0) {
                throw new \RuntimeException($kontext . ': ' . $feld . ' darf nicht negativ sein.');
            }

            return (int) $wert;
        };
        $mi = $lies($min, 'mind.');
        $ma = $lies($max, 'höchstens');
        if ($mi !== null && $ma !== null && $mi > $ma) {
            throw new \RuntimeException($kontext . ': mind. (' . $mi . ') ist größer als höchstens (' . $ma . ').');
        }

        return [$mi, $ma];
    }
}
