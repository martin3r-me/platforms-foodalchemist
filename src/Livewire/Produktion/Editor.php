<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Support\Suche;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 18 — Produktionsauftrag-Editor (voller Modal, Karteien Stammdaten/Ziele/
 * Vorschau). Ziele leben lokal im Livewire-State — kein DB-Schreiben während der
 * Eingabe; die Vorschau ruft PlanungsblattService::produktionsblattFuerZiele()
 * direkt (derselbe unveränderte Rechenkern wie die bisherigen Planungsblätter).
 * Speichern persistiert Auftrag+Ziele+Zeilen in EINEM Rutsch.
 */
class Editor extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public ?int $orderId = null;

    public string $productionDate = '';

    public ?string $name = null;

    public ?string $reference = null;

    public ?string $note = null;

    /** Küchen-Manager: Überproduktions-/Puffer-% — skaliert Ansätze + Einkauf (0 = kein Puffer). */
    public float $puffer = 0;

    /** @var list<array{source_ref:string, concept_id?:int, recipe_id?:int, persons?:float, portions?:float, label?:string}> */
    public array $targets = [];

    /** concept | recipe (VK-Gericht) | basisrezept (P1) | kapitel (Foodbook-Kapitel, P2). */
    public string $zielTyp = 'concept';

    // Untyped (nicht ?int): Livewire kann leeren String "" aus Select/Input nicht in ?int hydrieren
    // (500 TypeError). Alle Verwendungen casten selbst per (int). Siehe auch auswahlChapterId/-Personen.
    public $auswahlConceptId = null;

    public ?int $auswahlRecipeId = null;

    public float $auswahlMenge = 100;

    /** Nur für zielTyp='basisrezept': Menge in Ansätzen oder Kilogramm (P1). */
    public string $basisEinheit = 'ansaetze';

    /** Nur für zielTyp='kapitel' (P2): Foodbook + Kapitel + Personenzahl + Varianten-Wahl. */
    public $auswahlFoodbookId = null;

    public $auswahlChapterId = null;

    public $auswahlPersonen = null;

    /** variant_group_id ⇒ gewählte block_id (Kapitel-Ziel). */
    public array $variantChoices = [];

    public string $suche = '';

    public ?array $vorschau = null;

    public ?string $fehler = null;

    /** Operatives Feedback (Einkauf & Status-Tab, aus DetailPanel gemergt). */
    public ?string $hinweis = null;

    #[On('produktion-editor.oeffnen')]
    public function oeffnenNeu(): void
    {
        $this->reset(['orderId', 'name', 'reference', 'note', 'puffer', 'targets', 'auswahlConceptId', 'auswahlRecipeId', 'suche', 'vorschau', 'fehler', 'basisEinheit', 'auswahlFoodbookId', 'auswahlChapterId', 'auswahlPersonen', 'variantChoices']);
        $this->productionDate = now()->toDateString();
        $this->auswahlMenge = 100;
        $this->dispatch('modal.open', name: 'produktion-editor');
    }

    #[On('produktion-editor.bearbeiten')]
    public function oeffnenBearbeiten(int $id, ProductionOrderService $svc): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $detail = $svc->detail($team, $id);
        $this->orderId = $id;
        $this->productionDate = (string) $detail['production_date'];
        $this->name = $detail['name'];
        $this->reference = $detail['reference'];
        $this->note = $detail['note'];
        $this->puffer = (float) ($detail['buffer_pct'] ?? 0);
        $this->targets = $detail['targets'];
        $this->fehler = null;
        $this->berechneVorschau();
        $this->dispatch('modal.open', name: 'produktion-editor');
    }

    public function updatedZielTyp(): void
    {
        $this->auswahlConceptId = null;
        $this->auswahlRecipeId = null;
        // Die kg/Ansätze-Umschaltung gehört zum Basisrezept-Ziel. Blieb sie auf „kg" stehen,
        // interpretierte der nächste Basisrezept-Griff die Menge stumm als Kilogramm (E0).
        $this->basisEinheit = 'ansaetze';
        $this->suche = '';
        $this->auswahlFoodbookId = null;
        $this->auswahlChapterId = null;
        $this->auswahlPersonen = null;
        $this->variantChoices = [];
        $this->fehler = null;
    }

    public function updatedAuswahlFoodbookId(): void
    {
        // Foodbook gewählt → Kapitel-/Varianten-Wahl zurücksetzen, Pax aus dem Foodbook vorbelegen.
        $this->auswahlChapterId = null;
        $this->variantChoices = [];
        $team = Auth::user()?->currentTeamRelation;
        $fb = ($team && $this->auswahlFoodbookId)
            ? FoodAlchemistFoodbook::visibleToTeam($team)->find((int) $this->auswahlFoodbookId)
            : null;
        $this->auswahlPersonen = $fb?->personen ?: ($this->auswahlPersonen ?: 10);
    }

    public function updatedAuswahlChapterId(): void
    {
        // Neues Kapitel → alte Varianten-Wahl verwerfen (gilt nur pro Kapitel-Scope).
        $this->variantChoices = [];
    }

    public function zielHinzufuegen(): void
    {
        if ($this->zielTyp === 'kapitel') {
            $this->kapitelZielHinzufuegen();

            return;
        }

        // Ein Angebot bringt seine eigene Pax mit und expandiert in mehrere Ziele — es hat
        // deshalb kein Mengenfeld und läuft ausschließlich über die Kandidatenliste.
        if ($this->zielTyp === 'angebot') {
            $this->fehler = 'Angebote werden über die Liste mit „+" übernommen — die Personenzahl kommt aus dem Angebot.';

            return;
        }

        if ($this->zielTyp === 'concept') {
            $ziel = ['concept_id' => $this->auswahlConceptId, 'persons' => $this->auswahlMenge];
        } elseif ($this->zielTyp === 'basisrezept' && $this->basisEinheit === 'kg') {
            // P1: Basisrezept nach Kilogramm (Service rechnet kg ÷ Basis-Yield → ganze Ansätze).
            $ziel = ['recipe_id' => $this->auswahlRecipeId, 'amount_kg' => $this->auswahlMenge];
        } elseif ($this->zielTyp === 'basisrezept') {
            // Basisrezept nach Ansätzen (portions trägt beim Basisrezept die Ansatz-Zahl).
            $ziel = ['recipe_id' => $this->auswahlRecipeId, 'portions' => $this->auswahlMenge];
        } else {
            $ziel = ['recipe_id' => $this->auswahlRecipeId, 'portions' => $this->auswahlMenge];
        }

        if (empty($ziel['concept_id'] ?? null) && empty($ziel['recipe_id'] ?? null)) {
            return;
        }

        $this->zielAblegen($ziel);   // #2: identitäts-dedupliziert — Re-Add ersetzt, statt zu duplizieren
        $this->auswahlConceptId = null;
        $this->auswahlRecipeId = null;
        $this->suche = '';
        $this->berechneVorschau();
    }

    /**
     * Concepter-/Foodbook-Muster: „+" direkt aus der Kandidatenliste — setzt die Auswahl je
     * nach Ziel-Typ und fügt mit der aktuellen Menge hinzu. Liste + Suche bleiben offen.
     */
    public function zielAusListe(int $id): void
    {
        if ($this->zielTyp === 'angebot') {
            $this->angebotZielHinzufuegen($id);

            return;
        }
        if ($this->zielTyp === 'concept') {
            $this->auswahlConceptId = $id;
        } else {
            $this->auswahlRecipeId = $id;
        }
        $this->zielHinzufuegen();
    }

    /**
     * Ziele-Browser: flache, serverseitig gefilterte Listen fuer den Alpine-Picker.
     * Das Browsen ist renderless; nur das Einfuegen veraendert den Auftrag.
     */
    #[Renderless]
    public function browseZiele(string $typ, array $filter = [], string $q = ''): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return ['items' => [], 'total' => 0];
        }

        $suche = mb_strtolower(trim($q));
        $typ = in_array($typ, ['concept', 'recipe', 'basisrezept', 'angebot'], true) ? $typ : 'concept';

        if ($typ === 'concept') {
            $query = FoodAlchemistConcept::visibleToTeam($team)
                ->konzepte()   // Kaskade: Produktion aus Konzepten, nicht Paketen
                ->when($suche !== '', fn ($w) => Suche::like($w, 'name', $suche));
            $total = (clone $query)->count();

            return [
                'items' => $query->orderBy('name')->limit(200)->get(['id', 'name'])
                    ->map(fn ($c) => ['type' => 'concept', 'id' => (int) $c->id, 'name' => $c->name, 'meta' => []])->values()->all(),
                'total' => $total,
            ];
        }

        if (in_array($typ, ['recipe', 'basisrezept'], true)) {
            $query = FoodAlchemistRecipe::visibleToTeam($team);
            $query = $typ === 'basisrezept' ? $query->basis() : $query->verkauf();
            $query
                ->when($suche !== '', fn ($w) => Suche::like($w, 'foodalchemist_recipes.name', $suche))
                ->when(($filter['hg'] ?? '') !== '', fn ($w) => $w->whereHas('category', fn ($k) => $k->where('main_group_id', (int) $filter['hg'])))
                ->when(($filter['kat'] ?? '') !== '', fn ($w) => $w->where('category_id', (int) $filter['kat']))
                ->when(($filter['niveau'] ?? '') !== '', fn ($w) => $w->whereHas('levelSuitabilities', fn ($n) => $n->where('level_slug', $filter['niveau'])));
            $total = (clone $query)->count();

            return [
                'items' => $query->with('levelSuitabilities:id,recipe_id,level_slug')->orderBy('name')->limit(200)->get(['id', 'name'])
                    ->map(fn ($r) => [
                        'type' => $typ,
                        'id' => (int) $r->id,
                        'name' => $typ === 'basisrezept' ? '↳ ' . $r->name : $r->name,
                        'meta' => ['niveaus' => $r->levelSuitabilities->pluck('level_slug')->values()->all()],
                    ])->values()->all(),
                'total' => $total,
            ];
        }

        $query = FoodAlchemistAngebot::visibleToTeam($team)
            ->when($suche !== '', fn ($w) => Suche::like($w, 'name', $suche));
        $total = (clone $query)->count();

        return [
            'items' => $query->orderByDesc('id')->limit(200)->get(['id', 'name', 'personen'])
                ->map(fn ($a) => [
                    'type' => 'angebot',
                    'id' => (int) $a->id,
                    'name' => $a->name,
                    'meta' => ['personen' => (int) ($a->personen ?: 0)],
                ])->values()->all(),
            'total' => $total,
        ];
    }

    public function zielEinfuegen(string $typ, int $id, $menge, ?string $einheit = null): void
    {
        $typ = in_array($typ, ['concept', 'recipe', 'basisrezept', 'angebot'], true) ? $typ : 'concept';
        $this->zielTyp = $typ;
        $this->fehler = null;

        if ($typ === 'angebot') {
            $this->angebotZielHinzufuegen($id);

            return;
        }

        $menge = (float) str_replace(',', '.', (string) $menge);
        if ($id <= 0 || $menge <= 0) {
            return;
        }

        $team = Auth::user()?->currentTeamRelation;
        $sichtbar = match ($typ) {
            'concept' => $team !== null && FoodAlchemistConcept::visibleToTeam($team)->whereKey($id)->exists(),
            'basisrezept' => $team !== null && FoodAlchemistRecipe::visibleToTeam($team)->basis()->whereKey($id)->exists(),
            default => $team !== null && FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->whereKey($id)->exists(),
        };
        if (! $sichtbar) {
            $this->fehler = 'Ziel nicht verfügbar.';

            return;
        }

        if ($typ === 'concept') {
            $ziel = ['concept_id' => $id, 'persons' => $menge];
        } elseif ($typ === 'basisrezept' && $einheit === 'kg') {
            $ziel = ['recipe_id' => $id, 'amount_kg' => $menge];
        } else {
            $ziel = ['recipe_id' => $id, 'portions' => $menge];
        }

        $this->zielAblegen($ziel);   // #2: identitäts-dedupliziert — Re-Add ersetzt, statt zu duplizieren
        $this->basisEinheit = 'ansaetze';
        $this->berechneVorschau();
    }

    /**
     * Angebot als Ziel: das ganze bestätigte/geplante Angebot → alle seine Concepts auf einmal
     * (jedes Concept expandiert in der Vorschau in seine Gerichte). Pax kommt aus dem Angebot.
     * source_ref „offer:<id>@…:c<idx>" — eingefroren wie Kapitel-Ziele.
     */
    private function angebotZielHinzufuegen(int $angebotId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($angebotId);
        if ($angebot === null) {
            return;
        }
        $concepts = app(AngebotService::class)->menueConcepts($angebot);
        if ($concepts->isEmpty()) {
            $this->fehler = 'Angebot hat keine Konzepte.';

            return;
        }
        $pax = (int) ($angebot->personen ?: 0) ?: 10;
        $angLabel = $angebot->name ?: ('Angebot #' . $angebot->id);
        $base = 'offer:' . $angebot->id . '@' . uniqid();
        foreach ($concepts->values() as $i => $concept) {
            $this->targets[] = [
                'concept_id' => (int) $concept->id,
                'persons' => $pax,
                'source_ref' => $base . ':c' . $i,
                'label' => $angLabel . ' › ' . ($concept->name ?? ('Konzept #' . $concept->id)) . ' (' . $pax . ' P.)',
            ];
        }
        $this->fehler = null;
        $this->berechneVorschau();
    }

    public function zielEntfernen(string $sourceRef): void
    {
        $this->targets = collect($this->targets)->reject(fn ($t) => ($t['source_ref'] ?? null) === $sourceRef)->values()->all();
        $this->berechneVorschau();
    }

    /**
     * P2 Kapitel-Ziel: Kapitel über kapitelZiele() in eingefrorene Einzel-Ziele expandieren
     * (V2 „kein Live-Bezug") — spiegelt production_orders.ADD_TARGET (source_ref-Suffix „:c<idx>").
     */
    private function kapitelZielHinzufuegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || empty($this->auswahlChapterId)) {
            return;
        }
        $personen = max(1, (int) ($this->auswahlPersonen ?? 0));
        $res = app(PlanungsblattService::class)->kapitelZiele($team, (int) $this->auswahlChapterId, $personen, $this->variantChoices);
        if (empty($res['ziele'])) {
            $this->fehler = 'Kapitel liefert keine auflösbaren Ziele (nur sichtbare Gericht-/Konzept-Blocks).';

            return;
        }
        $chapter = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $this->auswahlChapterId);
        $kapLabel = $chapter?->title ?? ('Kapitel #' . $this->auswahlChapterId);
        $base = 'chapter:' . $this->auswahlChapterId . '@' . uniqid();
        foreach ($res['ziele'] as $i => $ziel) {
            $this->targets[] = array_merge($ziel, [
                'source_ref' => $base . ':c' . $i,
                'label' => $kapLabel . ' › ' . $this->labelFuer($ziel),
            ]);
        }
        $this->auswahlChapterId = null;
        $this->variantChoices = [];
        $this->fehler = null;
        $this->berechneVorschau();
    }

    /**
     * P2 „Edit": ein Einzel-Ziel zurück in den Picker laden und aus der Liste nehmen (Re-Add
     * ersetzt es). Kapitel-expandierte Teil-Ziele (source_ref „…:c<idx>") sind eingefroren —
     * für sie nur Entfernen, kein Edit.
     */
    public function zielBearbeiten(string $sourceRef): void
    {
        if (str_contains($sourceRef, ':c')) {
            return;
        }
        $t = collect($this->targets)->firstWhere('source_ref', $sourceRef);
        if ($t === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        if (! empty($t['concept_id'])) {
            $this->zielTyp = 'concept';
            $this->auswahlConceptId = (int) $t['concept_id'];
            $this->auswahlMenge = (float) ($t['persons'] ?? 100);
        } else {
            $recipe = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find((int) $t['recipe_id']) : null;
            $istVerkauf = $recipe !== null && (bool) $recipe->is_sales_recipe;
            $this->zielTyp = $istVerkauf ? 'recipe' : 'basisrezept';
            $this->auswahlRecipeId = (int) $t['recipe_id'];
            $this->suche = $recipe?->name ?? '';
            if (isset($t['amount_kg'])) {
                $this->basisEinheit = 'kg';
                $this->auswahlMenge = (float) $t['amount_kg'];
            } else {
                $this->basisEinheit = 'ansaetze';
                $this->auswahlMenge = (float) ($t['portions'] ?? 100);
            }
        }
        $this->zielEntfernen($sourceRef);
    }

    /**
     * Ein Einzel-Ziel in die Liste legen — IDENTITÄTS-dedupliziert. Umsetzung des dokumentierten
     * „Re-Add ersetzt es" (siehe {@see zielBearbeiten}): ein erneutes Hinzufügen desselben
     * Rezepts/Concepts ÜBERSCHREIBT das bestehende Einzel-Ziel, statt es zu duplizieren.
     *
     * Vorher trug jedes Hinzufügen einen `@uniqid()`-Suffix im source_ref → die Dedup (die auf
     * exakten source_ref matcht) griff nie, dasselbe Gericht landete doppelt und `recomputeOrder`
     * verdoppelte die Menge. Jetzt ist der source_ref identitäts-stabil (`recipe:<id>` /
     * `concept:<id>`) → genau EIN Einzel-Ziel je Rezept/Concept. Kapitel-/Angebots-Teilziele
     * (source_ref „…:c<idx>") sind eingefroren und bleiben unangetastet.
     */
    private function zielAblegen(array $ziel): void
    {
        $rid = isset($ziel['recipe_id']) ? (int) $ziel['recipe_id'] : null;
        $cid = isset($ziel['concept_id']) ? (int) $ziel['concept_id'] : null;
        $sourceRef = $cid !== null ? 'concept:' . $cid : 'recipe:' . $rid;

        $this->targets = collect($this->targets)
            ->reject(fn ($t) => ! str_contains((string) ($t['source_ref'] ?? ''), ':c')
                && (($rid !== null && (int) ($t['recipe_id'] ?? 0) === $rid)
                    || ($cid !== null && (int) ($t['concept_id'] ?? 0) === $cid)))
            ->values()->all();

        $this->targets[] = array_merge($ziel, ['source_ref' => $sourceRef, 'label' => $this->labelFuer($ziel)]);
    }

    private function labelFuer(array $ziel): string
    {
        $team = Auth::user()?->currentTeamRelation;
        if (! empty($ziel['concept_id'])) {
            $name = $team ? FoodAlchemistConcept::visibleToTeam($team)->find($ziel['concept_id'])?->name : null;

            return ($name ?? '#' . $ziel['concept_id']) . ' (' . $this->zahl($ziel['persons']) . ' P.)';
        }
        $name = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find($ziel['recipe_id'])?->name : null;
        $anzeige = $name ?? '#' . $ziel['recipe_id'];
        // P1: kg-Ziel (Basisrezept) bzw. Ansätze (Basisrezept) vs. Portionen (VK-Gericht).
        if (isset($ziel['amount_kg'])) {
            return $anzeige . ' (' . $this->zahl((float) $ziel['amount_kg']) . ' kg)';
        }
        $einheit = $this->zielTyp === 'basisrezept' ? 'Ansätze' : 'Port.';

        return $anzeige . ' (' . $this->zahl((float) $ziel['portions']) . ' ' . $einheit . ')';
    }

    private function zahl(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
    }

    private function berechneVorschau(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->targets === []) {
            $this->vorschau = null;

            return;
        }
        $ziele = collect($this->targets)->map(fn ($t) => Arr::except($t, ['source_ref', 'label']))->values()->all();
        // Puffer-% spiegeln, damit die Live-Vorschau die spätere Explosion (recomputeOrder) zeigt.
        $pct = max(0.0, min(100.0, (float) $this->puffer));
        if ($pct > 0) {
            $faktor = 1 + $pct / 100;
            $ziele = array_map(function (array $z) use ($faktor) {
                foreach (['persons', 'portions', 'amount_kg'] as $k) {
                    if (isset($z[$k])) {
                        $z[$k] = (float) $z[$k] * $faktor;
                    }
                }

                return $z;
            }, $ziele);
        }
        $this->vorschau = app(PlanungsblattService::class)->produktionsblattFuerZiele($team, $ziele);
    }

    /** Puffer geändert → Live-Vorschau neu rechnen (Persistenz erst beim Speichern). */
    public function updatedPuffer(): void
    {
        $this->berechneVorschau();
    }

    public function speichern(ProductionOrderService $svc): void
    {
        $this->fehler = null;
        if ($this->productionDate === '' || trim((string) $this->name) === '' || $this->targets === []) {
            $this->fehler = 'Name, Datum und mindestens ein Ziel angeben.';

            return;
        }
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            // Beide Zweige rechnen die Explosion GENAU EINMAL — Puffer und Ziele gehen in
            // denselben Aufruf, statt eine erste Runde zu produzieren, die sofort wieder
            // weggeworfen wird (Spec 30 E0).
            if ($this->orderId === null) {
                $order = $svc->saveNew($team, $this->productionDate, trim((string) $this->name), $this->targets, $this->reference, $this->note, Auth::id(), (float) $this->puffer);
            } else {
                $order = $svc->updateHeader($team, $this->orderId, [
                    'name' => trim((string) $this->name),
                    'reference' => $this->reference,
                    'note' => $this->note,
                    'production_date' => $this->productionDate,
                    'buffer_pct' => $this->puffer,
                    'targets' => $this->targets,
                ]);
            }
            $this->dispatch('modal.close', name: 'produktion-editor');
            $this->dispatch('produktion-gespeichert', id: (int) $order->id);
            $this->savedToast('Produktionsauftrag gespeichert');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Operative Aktionen (Spec-29-Rollout: DetailPanel → Editor gemergt) — nur bestehender Auftrag ──

    public function setStatus(string $status, ProductionOrderService $svc): void
    {
        $ziel = ProductionOrderStatus::tryFrom($status);
        if ($ziel === null || $this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $svc->setStatus($team, $this->orderId, $ziel), 'Status gesetzt.');
        $this->dispatch('produktion-status-geaendert');
    }

    public function updateLineNote(int $lineId, string $note, ProductionOrderService $svc): void
    {
        $this->fuehreAus(fn ($team) => $svc->updateLine($team, $lineId, ['note' => $note]), 'Notiz gespeichert.');
    }

    // ── Spec 30 E2: Zeilen-Eingriff ─────────────────────────────────────────

    /** Formular der freien Position (Tab „Zeilen"). */
    public string $freiTitel = '';

    public string $freiZeit = '';

    /** Ansätze überschreiben; leeres Feld nimmt den Override zurück. */
    public function zeileAnsaetze(int $lineId, string $wert, ProductionOrderService $svc): void
    {
        $roh = trim(str_replace(',', '.', $wert));
        if ($roh !== '' && (! is_numeric($roh) || (float) $roh < 0)) {
            $this->fehler = 'Ansätze brauchen eine Zahl ≥ 0 (oder leer für den berechneten Wert).';

            return;
        }
        $this->fuehreAus(
            fn ($team) => $svc->setLineAnsaetze($team, $lineId, $roh === '' ? null : (float) $roh),
            $roh === '' ? 'Auf den berechneten Wert zurückgesetzt.' : 'Ansätze überschrieben.',
        );
    }

    public function zeileStreichen(int $lineId, bool $struck, ProductionOrderService $svc): void
    {
        $this->fuehreAus(
            fn ($team) => $svc->setLineStruck($team, $lineId, $struck),
            $struck ? 'Zeile gestrichen — sie zählt nicht mehr mit und kommt nicht auf den Zettel.' : 'Zeile wiederhergestellt.',
        );
    }

    /**
     * Spec 30 E6 — Zeile abhaken, zweiter Klick nimmt zurück. Kein Auto-Weiterschalten des
     * Auftragsstatus: „fertig melden" bleibt eine bewusste Entscheidung, kein Nebeneffekt
     * des letzten Hakens.
     */
    public function zeileAbhaken(int $lineId, ProductionOrderService $svc): void
    {
        $this->fuehreAus(function ($team) use ($lineId, $svc) {
            $zeile = FoodAlchemistProductionOrderLine::findOrFail($lineId);
            $svc->setLineStatus($team, $lineId, $zeile->line_status === ProductionLineStatus::Done
                ? ProductionLineStatus::Open
                : ProductionLineStatus::Done);
        }, null);
    }

    /** Spec 30 E7 — Auftrag löschen (nur geplant/storniert; der Guard sitzt im Service). */
    public function auftragLoeschen(ProductionOrderService $svc): void
    {
        if ($this->orderId === null) {
            return;
        }
        $id = $this->orderId;
        $this->fuehreAus(fn ($team) => $svc->deleteOrder($team, $id), null);
        if ($this->fehler === null) {
            $this->dispatch('modal.close', name: 'produktion-editor');
            $this->dispatch('produktion-geloescht');
        }
    }

    public function freiePositionAnlegen(ProductionOrderService $svc): void
    {
        if (trim($this->freiTitel) === '') {
            $this->fehler = 'Freie Position braucht einen Titel.';

            return;
        }
        $titel = $this->freiTitel;
        $zeit = $this->freiZeit;
        $this->fuehreAus(
            fn ($team) => $svc->addManualLine($team, (int) $this->orderId, ['titel' => $titel, 'arbeitszeit_min' => $zeit]),
            'Freie Position angelegt.',
        );
        if ($this->fehler === null) {
            $this->reset('freiTitel', 'freiZeit');
        }
    }

    public function freiePositionLoeschen(int $lineId, ProductionOrderService $svc): void
    {
        $this->fuehreAus(fn ($team) => $svc->removeManualLine($team, $lineId), 'Freie Position entfernt.');
    }

    /**
     * Zuteilung je Zeile: Posten · Verantwortlicher · Vorlauf-Tage.
     * Ein Feld pro Aufruf — der Service fasst nur übergebene Keys an.
     */
    public function zeileZuteilen(int $lineId, string $feld, string $wert, ProductionOrderService $svc): void
    {
        if (! in_array($feld, ['station_id', 'assignee', 'vorlauf_tage'], true)) {
            return;
        }
        $this->fuehreAus(
            fn ($team) => $svc->assignLine($team, $lineId, [$feld => $wert === '' && $feld === 'station_id' ? null : $wert]),
            'Zuteilung gespeichert.',
        );
    }

    /** Alle noch unverplanten Zeilen auf einen Posten — spart das Zeile-für-Zeile-Klicken. */
    public function alleUnverplantAufPosten(int $stationId, ProductionOrderService $svc): void
    {
        $this->fuehreAus(function ($team) use ($stationId, $svc) {
            $ids = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine::query()
                ->where('production_order_id', (int) $this->orderId)
                ->whereNull('station_id')->where('is_struck', false)
                ->pluck('id');
            foreach ($ids as $id) {
                $svc->assignLine($team, (int) $id, ['station_id' => $stationId]);
            }
        }, 'Unverplante Zeilen zugeteilt.');
    }

    public function materialbedarfFreigeben(ProductionOrderService $prod): void
    {
        $this->fuehreAus(fn ($team) => $prod->materialbedarfFreigeben($team, $this->orderId, Auth::id()), 'Materialbedarf für den Einkauf freigegeben.');
        $this->dispatch('produktion-status-geaendert');
    }

    private function fuehreAus(callable $fn, ?string $ok): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $fn($team);
            $this->hinweis ??= $ok;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(ProductionOrderService $svc)
    {
        $team = Auth::user()?->currentTeamRelation;

        // P2 Kapitel-Picker: Foodbooks, Kapitel-Baum (flach + Tiefe) und Wahl-Gruppen.
        $foodbooks = collect();
        $kapitelBaum = collect();
        $variantGroups = [];
        if ($team && $this->zielTyp === 'kapitel') {
            $foodbooks = FoodAlchemistFoodbook::visibleToTeam($team)->orderBy('label')->get(['id', 'label', 'personen']);
            if ($this->auswahlFoodbookId) {
                $kapitelBaum = $this->kapitelBaumFuer($team, (int) $this->auswahlFoodbookId);
            }
            if ($this->auswahlChapterId) {
                $variantGroups = app(PlanungsblattService::class)->kapitelVarianten($team, (int) $this->auswahlChapterId)['groups'];
            }
        }

        // Operative Daten (nur bestehender Auftrag) für den „Einkauf & Status"-Tab (aus DetailPanel gemergt).
        $ops = null;
        $erlaubteStatus = [];
        $verknuepfteOrders = collect();
        $zielUebergaben = [];
        if ($this->orderId !== null && $team !== null) {
            try {
                $ops = $svc->detail($team, $this->orderId);
                $aktuell = ProductionOrderStatus::from($ops['status']);
                foreach ([ProductionOrderStatus::InProgress, ProductionOrderStatus::Done, ProductionOrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
                $verknuepfteOrders = $svc->verknuepfteOrders($team, $this->orderId);
                $zielUebergaben = $svc->zielUebergaben($team, $this->orderId);
            } catch (\Throwable) {
                $ops = null;
            }
        }

        // Küchen-Manager: Diät-/Allergen-Übersicht über die ganze Produktion (Rollup der Vorschau-Rezepte).
        $allergenRollup = null;
        if ($team !== null && $this->vorschau !== null && ! empty($this->vorschau['rezepte'])) {
            $recipeIds = collect($this->vorschau['rezepte'])->pluck('recipe_id')->filter()->unique()->all();
            if ($recipeIds !== []) {
                $recipes = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $recipeIds)
                    ->get(['id', 'allergens_confidence', 'spec_is_vegan', 'spec_is_vegetarian', 'spec_is_halal', 'spec_is_gluten_free', 'spec_is_lactose_free', 'spec_contains_pork', 'spec_contains_beef']);
                $allergenRollup = app(ConcepterAggregateService::class)->allergenRollupFromGerichte($recipes);
            }
        }

        // Spec 30 E3: Posten-Auswahl + Auslastungs-Warnungen des Auftrags
        $postenListe = $team !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            : collect();
        $postenSummen = ($team !== null && $this->orderId !== null && $ops !== null)
            ? app(ProductionOrderService::class)->postenSummen($team, (int) $this->orderId) : [];
        $kapazitaetsWarnungen = ($team !== null && $this->orderId !== null && $ops !== null)
            ? app(\Platform\FoodAlchemist\Services\ProductionCapacityService::class)
                ->warnungenFuer($team, (int) $this->orderId) : [];

        return view('foodalchemist::livewire.produktion.editor', [
            'postenListe' => $postenListe,
            'postenSummen' => $postenSummen,
            'kapazitaetsWarnungen' => $kapazitaetsWarnungen,
            'allergenRollup' => $allergenRollup,
            'zielVokabular' => $this->browserVokabular($team),
            'foodbooks' => $foodbooks,
            'kapitelBaum' => $kapitelBaum,
            'variantGroups' => $variantGroups,
            'ops' => $ops,
            'erlaubteStatus' => $erlaubteStatus,
            'verknuepfteOrders' => $verknuepfteOrders,
            'zielUebergaben' => $zielUebergaben,
        ]);
    }

    private function browserVokabular($team): ?array
    {
        if ($team === null) {
            return null;
        }

        return [
            'hauptgruppen' => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_recipe_main_groups')
                ->whereNull('deleted_at'), 'team_id', $team)->orderBy('sort_order')->get(['id', 'label'])->all(),
            'kategorien' => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_recipe_categories')
                ->whereNull('deleted_at'), 'team_id', $team)->orderBy('label')->get(['id', 'label', 'main_group_id'])->all(),
            'niveaus' => [['slug' => 'haute_cuisine', 'label' => 'Haute'], ['slug' => 'gehoben', 'label' => 'Gehoben'], ['slug' => 'klassisch', 'label' => 'Klassisch']],
        ];
    }

    /**
     * Kapitel eines Foodbooks in Dokument-Reihenfolge mit Einrück-Tiefe (n-tiefer Baum via
     * parent_id) für das Picker-Select.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int, title:string, depth:int}>
     */
    private function kapitelBaumFuer($team, int $foodbookId)
    {
        $alle = FoodAlchemistFoodbookKapitel::visibleToTeam($team)
            ->where('foodbook_id', $foodbookId)
            ->orderBy('position')
            ->get(['id', 'parent_id', 'title', 'position']);

        $byParent = $alle->groupBy(fn ($k) => (int) ($k->parent_id ?? 0));
        $out = collect();
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, $out) {
            foreach ($byParent->get($parentId, collect()) as $k) {
                $out->push(['id' => (int) $k->id, 'title' => (string) $k->title, 'depth' => $depth]);
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }
}
