<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 80 Teil D — Fortschritt als Baum · Gericht-Cluster · Rezept (abgenommen am Mockup 09.10.).
 *
 * Links die Gliederung wie in der Ausgabe angelegt (Foodbook-Kapitel, Rahmen-Slot = Rubrik/Gang/Station,
 * Concept), in der Mitte je Gericht ein Cluster mit seinen Basisrezepten als kompakte Zeilen, rechts der gewählte
 * Eintrag: die bewährte Ergebniskarte (alle Aktionen) plus die Rezeptansicht (Zutaten, Zubereitung, „Woher das
 * kommt"). Reine Anzeige-Logik; die Aktionen bleiben die der Komponente (gibFrei, neuGenerieren, …).
 */
trait FortschrittAnsicht
{
    /** Gewählter Eintrag rechts (Step-ID). */
    public ?int $fortschrittAuswahl = null;

    /** Gewählter Baum-Knoten (Schlüssel aus fortschrittBaum) — null = alle. */
    public ?string $fortschrittKnoten = null;

    /** alle | pruefen | fehler | bestand */
    public string $fortschrittFilter = 'alle';

    public function waehleSchritt(int $stepId): void
    {
        $this->fortschrittAuswahl = $stepId;
    }

    public function waehleKnoten(?string $knoten = null): void
    {
        $this->fortschrittKnoten = $knoten === '' ? null : $knoten;
    }

    public function setzeFortschrittFilter(string $filter): void
    {
        $this->fortschrittFilter = in_array($filter, ['alle', 'pruefen', 'fehler', 'bestand'], true) ? $filter : 'alle';
    }

    /** Spec 80 D7: Eingaben der Plan-Karte je Step: [stepId => ['name' => …, 'menge' => …, 'mengen' => [idx => …]]]. */
    public array $planEingabe = [];

    /** Spec 80 D7: Plan-Karte — Aktion auf eine Komponente des Plans (entfernen · menge · bestand_ablehnen · hinzufuegen). */
    public function planAendern(int $stepId, string $aktion, ?int $index = null): void
    {
        $cascade = app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class);
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $e = (array) ($this->planEingabe[$stepId] ?? []);
        try {
            $cascade->aenderePlan($team, $stepId, $aktion, [
                'index' => $index,
                'menge' => $aktion === 'menge' ? ($e['mengen'][$index] ?? '') : ($e['menge'] ?? ''),
                'name' => $e['name'] ?? '',
            ]);
            if ($aktion === 'hinzufuegen') {
                $this->planEingabe[$stepId] = ['name' => '', 'menge' => ''];
            }
            $this->fehler = null;
        } catch (\RuntimeException $ex) {
            $this->fehler = $ex->getMessage();
        }
    }

    /** Cluster-Fuß: Gericht + seine Basisrezepte anreichern und freigeben (Spec 80 H3, nur dieser Cluster). */
    public function clusterFreigeben(int $kopfId, \Platform\FoodAlchemist\Services\PlanningCascadeService $cascade): void
    {
        $team = $this->team();
        if ($team === null || $this->laufId === null) {
            return;
        }
        $lauf = $cascade->lauf($team, $this->laufId);
        $kopf = $lauf?->steps->firstWhere('id', $kopfId);
        if ($lauf === null || $kopf === null) {
            return;
        }
        $ids = [$kopfId];
        foreach ($this->fortschrittDaten($lauf)['alleCluster'] as $c) {
            if ((int) $c['kopf']->id === $kopfId) {
                $ids = [...$ids, ...array_map(fn ($z) => (int) $z->id, $c['zeilen'])];
            }
        }
        $r = $cascade->gibNeueFrei($team, $this->laufId, $ids);
        $this->meldung = $r['freigegeben'] . ' werden angereichert und danach freigegeben.'
            . ($r['uebersprungen'] > 0 ? ' ' . $r['uebersprungen'] . ' mit offenem Regelwerk-Befund bleiben stehen.' : '');
        $this->refreshLaeuft($cascade);
    }

    /**
     * Alles, was die Ansicht braucht, in einem Durchgang aus den Steps des Laufs.
     *
     * @return array{baum: array, cluster: list<array>, knotenHeads: array<string, list<int>>, pfad: list<string>}
     */
    public function fortschrittDaten(FoodAlchemistCascadeRun $lauf): array
    {
        $steps = $lauf->steps->keyBy('id');
        $kinder = [];
        foreach ($steps as $s) {
            $kinder[(int) ($s->parent_step_id ?? 0)][] = $s;
        }
        foreach ($kinder as &$liste) {
            usort($liste, fn ($a, $b) => [(int) $a->depth, (int) $a->sort, (int) $a->id] <=> [(int) $b->depth, (int) $b->sort, (int) $b->id]);
        }
        unset($liste);

        // Cluster-Köpfe: jedes Gericht; ein Basisrezept ohne Gericht/Basisrezept darüber (Basisrezept-Lauf);
        // ein Concept, unter dem (noch) kein Gericht hängt.
        $istKopf = function (FoodAlchemistCascadeRunStep $s) use ($steps, $kinder): bool {
            if ($s->kind === 'gericht') {
                return true;
            }
            $eltern = $s->parent_step_id !== null ? $steps->get((int) $s->parent_step_id) : null;
            if ($s->kind === 'rezept') {
                return $eltern === null || ! in_array($eltern->kind, ['rezept', 'gericht'], true);
            }
            if ($s->kind === 'concept') {
                return collect($kinder[(int) $s->id] ?? [])->where('kind', 'gericht')->isEmpty();
            }

            return false;
        };
        $nachfahren = function (int $id) use (&$nachfahren, $kinder): array {
            $out = [];
            foreach ($kinder[$id] ?? [] as $k) {
                if ($k->kind === 'rezept') {
                    $out[] = $k;
                    $out = array_merge($out, $nachfahren((int) $k->id));
                }
            }

            return $out;
        };

        // Gliederung: Pfad je Kopf über die Vorfahren (Kapitel, Rahmen-Slot, Concept).
        $slotIds = $steps->pluck('slot_id')->filter()->unique()->values()->all();
        $slots = $slotIds === [] ? collect() : \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::query()
            ->whereIn('id', $slotIds)->get(['id', 'label', 'slot_type'])->keyBy('id');
        $kapitelIds = $steps->pluck('chapter_id')->filter()->unique()->values()->all();
        $kapitel = $kapitelIds === [] ? collect() : \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::query()
            ->whereIn('id', $kapitelIds)->get(['id', 'title', 'consumer_title'])->keyBy('id');
        $slotArt = ['gang' => 'Gang', 'station' => 'Station', 'kapitel' => 'Kapitel'];

        $pfadFuer = function (FoodAlchemistCascadeRunStep $s) use ($steps, $slots, $kapitel, $slotArt, $lauf): array {
            $kette = [];
            for ($x = $s; $x !== null; $x = $x->parent_step_id !== null ? $steps->get((int) $x->parent_step_id) : null) {
                $kette[] = $x;
            }
            $kette = array_reverse($kette);
            $pfad = [];
            foreach ($kette as $x) {
                if ($x->chapter_id !== null && ($k = $kapitel->get((int) $x->chapter_id)) !== null) {
                    $pfad['kapitel:' . $k->id] = ['art' => 'Kapitel', 'label' => (string) ($k->title ?: $k->consumer_title)];
                }
                if ($x->slot_id !== null && ($sl = $slots->get((int) $x->slot_id)) !== null) {
                    $art = $lauf->source_owner_type === 'speisekarte' ? 'Rubrik' : ($slotArt[$sl->slot_type] ?? 'Abschnitt');
                    $pfad['slot:' . $sl->id] = ['art' => $art, 'label' => (string) $sl->label];
                }
                if ($x->kind === 'concept' && $x->id !== $s->id) {
                    $pfad['concept:' . $x->id] = ['art' => 'Konzept', 'label' => (string) $x->label];
                }
            }

            return $pfad;
        };

        $cluster = [];
        $baum = ['kinder' => []];
        foreach ($steps as $s) {
            if (! $istKopf($s)) {
                continue;
            }
            $zeilen = $nachfahren((int) $s->id);
            $alle = [$s, ...$zeilen];
            $cluster[] = [
                'kopf' => $s,
                'zeilen' => $zeilen,
                'offen' => collect($alle)->whereIn('status', ['done', 'geplant'])->count(),
                'fehler' => collect($alle)->where('status', 'failed')->count(),
                'pfad' => $pfadFuer($s),
            ];
            // In den Baum einhängen.
            $knoten = &$baum;
            foreach ($cluster[array_key_last($cluster)]['pfad'] as $key => $info) {
                $knoten['kinder'][$key] ??= ['key' => $key, 'art' => $info['art'], 'label' => $info['label'], 'heads' => [], 'offen' => 0, 'fehler' => 0, 'kinder' => []];
                $knoten = &$knoten['kinder'][$key];
                $knoten['heads'][] = (int) $s->id;
                $knoten['offen'] += $cluster[array_key_last($cluster)]['offen'];
                $knoten['fehler'] += $cluster[array_key_last($cluster)]['fehler'];
            }
            unset($knoten);
        }

        // Spec 80 F4: Struktur-Elemente des Rahmens an ihrer Stelle zeigen (ruhig, nicht klickbar) und die
        // Abschnitte in Rahmen-Reihenfolge ordnen — so entspricht der Baum der Ausgabe.
        if (in_array($lauf->source_owner_type, \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::STRUKTUR_OWNER, true)) {
            $frame = app(\Platform\FoodAlchemist\Services\PlanningFrameService::class)->find((string) $lauf->source_owner_type, (int) $lauf->source_owner_id);
            if ($frame !== null) {
                $rang = [];
                $strukturArt = ['titel' => 'Titel', 'titel_preis' => 'Titel mit Preis', 'freitext' => 'Freitext', 'leerzeile' => 'Leerzeile'];
                foreach ($frame->slots()->orderBy('position')->get() as $pos => $fs) {
                    $rang['slot:' . $fs->id] = $pos;
                    if ($fs->istStruktur()) {
                        $baum['kinder']['struktur:' . $fs->id] = ['key' => 'struktur:' . $fs->id, 'art' => $strukturArt[$fs->slot_type],
                            'label' => $fs->slot_type === 'leerzeile' ? '—' : (string) $fs->label, 'struktur' => true,
                            'heads' => [], 'offen' => 0, 'fehler' => 0, 'kinder' => []];
                        $rang['struktur:' . $fs->id] = $pos;
                    }
                }
                uksort($baum['kinder'], fn ($a, $b) => ($rang[$a] ?? PHP_INT_MAX) <=> ($rang[$b] ?? PHP_INT_MAX));
            }
        }

        // Knoten-Auswahl + Filter auf die Cluster anwenden.
        $knotenHeads = [];
        $sammle = function (array $kn) use (&$sammle, &$knotenHeads): void {
            foreach ($kn['kinder'] as $k) {
                $knotenHeads[$k['key']] = $k['heads'];
                $sammle($k);
            }
        };
        $sammle($baum);
        $erlaubt = $this->fortschrittKnoten !== null ? ($knotenHeads[$this->fortschrittKnoten] ?? []) : null;
        $passt = fn ($st) => match ($this->fortschrittFilter) {
            'pruefen' => in_array($st->status, ['done', 'geplant'], true),
            'fehler' => $st->status === 'failed',
            'bestand' => $st->status === 'skipped',
            default => true,
        };
        $sichtbar = [];
        foreach ($cluster as $c) {
            if ($erlaubt !== null && ! in_array((int) $c['kopf']->id, $erlaubt, true)) {
                continue;
            }
            if ($this->fortschrittFilter !== 'alle') {
                $c['zeilen'] = array_values(array_filter($c['zeilen'], $passt));
                if ($c['zeilen'] === [] && ! $passt($c['kopf'])) {
                    continue;
                }
            }
            $sichtbar[] = $c;
        }

        return [
            'baum' => $baum,
            'cluster' => $sichtbar,
            'alleCluster' => $cluster,
            'pfad' => $this->fortschrittKnoten !== null ? $this->knotenPfad($baum, $this->fortschrittKnoten) : [],
        ];
    }

    /** Beschriftungen vom Wurzel- bis zum gewählten Knoten. @return list<string> */
    private function knotenPfad(array $baum, string $ziel): array
    {
        $suche = function (array $kn, array $weg) use (&$suche, $ziel): ?array {
            foreach ($kn['kinder'] as $k) {
                $w = [...$weg, $k['label']];
                if ($k['key'] === $ziel) {
                    return $w;
                }
                if (($r = $suche($k, $w)) !== null) {
                    return $r;
                }
            }

            return null;
        };

        return $suche($baum, []) ?? [];
    }

    /**
     * Rezeptansicht rechts: Zutaten mit Herkunft, Zubereitung, „Woher das kommt" aus dem Lauf-Snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function fortschrittRezept(?FoodAlchemistCascadeRunStep $step): ?array
    {
        if ($step === null || $step->ref_type !== 'recipe' || $step->ref_id === null) {
            return null;
        }
        $r = FoodAlchemistRecipe::query()->withTrashed()
            ->with(['ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name,status', 'ingredients.unit:id,slug'])
            ->find((int) $step->ref_id);
        if ($r === null) {
            return null;
        }
        $schritte = method_exists($r, 'steps')
            ? $r->steps()->orderBy('position')->limit(20)->pluck('text')->all()
            : [];
        $snap = is_array($step->context_snapshot) ? $step->context_snapshot : [];

        return [
            'name' => (string) $r->name,
            'ansatz' => $r->yield_kg_manual ?? $r->yield_kg,
            'zutaten' => $r->ingredients->sortBy('position')->map(fn ($z) => [
                'menge' => (float) $z->quantity,
                'einheit' => (string) ($z->unit?->slug ?? ''),
                'text' => (string) ($z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name ?? $z->raw_text),
                'verweis' => $z->referenced_recipe_id !== null,
                'herkunft' => $z->referenced_recipe_id !== null
                    ? (($z->referencedRecipe?->status?->value ?? null) === 'approved' ? 'Bestand #' . $z->referenced_recipe_id : 'Unterrezept #' . $z->referenced_recipe_id)
                    : ($z->gp_id !== null ? 'Grundprodukt' : 'offen'),
                'notiz' => (string) ($z->note ?? ''),
            ])->values()->all(),
            'zubereitung' => $schritte !== [] ? $schritte : array_values(array_filter(preg_split('/\R+/u', (string) $r->preparation) ?: [])),
            'woher' => [
                'suchbegriffe' => (array) ($snap['suchbegriffe'] ?? []),
                'wissen' => array_values(array_unique([...(array) ($snap['kanon_files'] ?? []), ...(array) ($snap['knowledge_files'] ?? [])])),
                'pairing' => (array) (($snap['pairing'] ?? [])['anker'] ?? []),
                'ohne_anker' => (array) (($snap['pairing'] ?? [])['ohne_anker'] ?? []),
                'abgelehnt' => (array) ($snap['bestand_abgelehnt'] ?? []),
                'korrigiert' => (array) ($snap['regelwerk_korrigiert'] ?? []),
            ],
        ];
    }

    /** Kopf-Leiste: je Stufe fertig/gesamt (Gerichte, Basisrezepte, Freigegeben). @return list<array{label: string, fertig: int, gesamt: int}> */
    public function fortschrittStufenleiste(Collection $steps): array
    {
        $out = [];
        foreach (['concept' => 'Konzepte', 'gericht' => 'Gerichte', 'rezept' => 'Basisrezepte'] as $kind => $label) {
            $g = $steps->where('kind', $kind);
            if ($g->isEmpty()) {
                continue;
            }
            $out[] = ['label' => $label, 'fertig' => $g->whereIn('status', ['done', 'freigegeben', 'skipped'])->count(), 'gesamt' => $g->count()];
        }
        $relevant = $steps->whereIn('kind', ['gericht', 'rezept'])->whereNotIn('status', ['verworfen']);
        $out[] = ['label' => 'Freigegeben', 'fertig' => $relevant->whereIn('status', ['freigegeben', 'skipped'])->count(), 'gesamt' => $relevant->count()];

        return $out;
    }
}
