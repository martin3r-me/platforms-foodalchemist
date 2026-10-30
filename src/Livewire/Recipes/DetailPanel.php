<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\TauschtRezept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\Hochrechnung;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M4-05 / P-1: Rezept-DetailPanel (rechte Page-Sidebar) — KPI-Karte
 * (EK/kg·EK·Yield·Konfidenz), Beschreibung, Zutaten read-only mit GP-Links +
 * EK je Zeile + Lineage kursiv (Nachtrag 13_REFERENZ), Diät-&-Spezifikations-
 * Sektion (spec_*-Flags), Eignungs- + Equipment-Chips.
 * Verwandte-Rezepte/Kohäsion folgen mit M5 (GL-10-Daten).
 */
class DetailPanel extends Component
{
    use TauschtRezept;   // Verwaltungs-Block: tauschen + löschen — identisch im Editor (RecipeModal)
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;   // #1003: am Handy nur lesen

    /** #1003: am Handy laufen nur Anzeige und Navigation. */
    public function handyLeseMethoden(): array
    {
        // Hochrechnen ist reine Ansicht (speichert nichts) — hochUebernehmen schreibt und steht bewusst NICHT hier.
        return ['zeige', 'nachSpeichern', 'pairingLaden', 'toggleSektion', 'hochNachZutat', 'hochZuruecksetzen'];
    }

    /** Spec 65: gleiche Sperre wie der Basisrezept-Editor (Ziel recipe). */
    protected function sperrZiel(): ?array
    {
        return $this->recipeId !== null ? ['recipe', $this->recipeId] : null;
    }

    /**
     * Ohne Sperre: Anzeige/Navigation, Pairing nachladen, Sektionen aufklappen, Kopie anlegen (ändert das Original nicht),
     * Kosten neu rechnen (abgeleitete Werte, keine Pflege).
     */
    protected function sperrFreiExtra(): array
    {
        return ['pairingLaden', 'zeige', 'nachSpeichern', 'toggleSektion', 'duplizieren', 'neuBerechnen', 'hochNachZutat', 'hochZuruecksetzen'];
    }

    public ?int $recipeId = null;

    /** Eingebettet als Editor-Kartei: blendet die im Editor redundante KPI/Beschreibung/Zutaten aus. */
    public bool $embedded = false;

    /** GP-Modal-Muster: section = genau EINE Kartei rendern (z.B. 'ersatz' als eigener Tab). */
    public ?string $section = null;

    /**
     * Pairing (Kombinationslogik + Netz) wird NACHGELADEN (Dominique 2026-10-07: Detailspalte lädt sehr langsam —
     * gemessen ~2–4 s nur fürs Pairing, bei jedem Klick). Der Platzhalter im Blade ruft pairingLaden() einmal je
     * Rezept (wire:key je Rezept, x-init). Ergebnis kurz gecacht, Schlüssel inkl. updated_at des Rezepts: Aktionen
     * in der Spalte (Eignung, Status …) rechnen es nicht neu; nach Speichern/Recompute ist der Schlüssel neu.
     * Achtung: Kombinationslogik::daten schreibt bei Basisrezepten das Rezept-Profil (Hash-Wechsel) — deshalb nur
     * einmal je Rezept anstoßen, nie pro Render.
     */
    public ?int $pairingFuer = null;

    public function pairingLaden(): void
    {
        $this->pairingFuer = $this->recipeId;
    }

    /** @return array{netz: array, kombination: ?array} */
    private function pairingDaten($team, FoodAlchemistRecipe $rezept): array
    {
        $schluessel = 'fa.detail.pairing.'.$team->id.'.'.$rezept->id.'.'.optional($rezept->updated_at)->timestamp;

        return \Illuminate\Support\Facades\Cache::remember($schluessel, 300, fn () => [
            'netz' => app(\Platform\FoodAlchemist\Services\PairingService::class)->pairingNetz($team, $rezept->id),
            'kombination' => app(\Platform\FoodAlchemist\Services\Pairing\Kombinationslogik::class)->daten($rezept),
        ]);
    }

    public function mount(?int $recipeId = null, bool $embedded = false, ?string $section = null): void
    {
        $this->recipeId = $recipeId;
        $this->embedded = $embedded;
        $this->section = $section;
        $this->hochVorbelegen();
    }

    /** @var array<string, bool> M5-04/05: lazy Pairing-Sektionen (Kontext-Erhalt beim Wechsel) */
    public array $offen = [];

    /** Ersatz-Logik: Suchtext für die Gegenseite (GP/Rezept) im Verknüpfen-Feld. */
    public string $ersatzSuche = '';

    #[On('recipe-selected')]
    public function zeige(int $id): void
    {
        if ($this->embedded) {
            return; // eingebettet als Editor-Kartei: bleibt auf dem Editor-Rezept, ignoriert Browser-Auswahl
        }
        if ($this->recipeId !== $id) {
            $this->sperreBeiWechselFreigeben();   // Spec 65: Wechsel gibt eine hier geholte Sperre frei
        }
        $this->recipeId = $id;
        $this->hochVorbelegen();
        $this->ersatzSuche = '';
        $this->tauschSuche = '';
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
    }

    /**
     * #511: nach jedem Save den Rezept-Kopf (EK/kg · EK · Yield · Allergene) neu
     * rendern — greift AUCH im embedded Editor-Kontext, wo zeige() (recipe-selected)
     * bewusst früh aussteigt und den Kopf sonst nur am generischen Re-Render hinge.
     * Re-render liest die frischen Aggregate aus der DB (render() → detail()).
     */
    #[On('recipe-gespeichert')]
    public function nachSpeichern(): void
    {
        // no-op: die Präsenz des Listeners löst das Re-Rendering des Panels aus.
    }

    // ── #1003 Hochrechnen (Basisrezept): Ansicht über Support\Hochrechnung, dieselbe Rechnung wie das Dokument ──

    /** Ziel in kg (Eingabe, vorbelegt mit dem Ansatz). */
    public string $hochZielKg = '';

    /** „Nach Zutat": gewählte Zeile und „davon habe ich" in deren Einheit. */
    public ?int $hochZutatId = null;

    public string $hochHabe = '';

    /** Feld = Ansatz, kein Faktor (beim Rezeptwechsel, Zurücksetzen und nach dem Übernehmen). */
    private function hochVorbelegen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null
            ? FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId, ['id', 'yield_kg', 'yield_kg_manual'])
            : null;
        $ansatz = $rezept !== null ? Hochrechnung::ansatzKg($rezept) : null;
        $this->hochZielKg = $ansatz !== null ? rtrim(rtrim(number_format($ansatz, 3, ',', ''), '0'), ',') : '';
        $this->hochZutatId = null;
        $this->hochHabe = '';
    }

    /** Eingabe in kg beendet „nach Zutat". */
    public function updatedHochZielKg(): void
    {
        $this->hochZutatId = null;
        $this->hochHabe = '';
    }

    public function hochNachZutat(int $zutatId): void
    {
        $this->hochVorbelegen();   // Feld zurück auf den Ansatz, bis „davon habe ich" einen Faktor ergibt
        $this->hochZutatId = $zutatId;
    }

    /** „Davon habe ich" → das kg-Feld zeigt die Menge, die es ergibt (sonst stünde dort ein alter Wert). */
    public function updatedHochHabe(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? app(RecipeService::class)->detail($team, $this->recipeId) : null;
        $ziel = $rezept !== null ? $this->hochrechnung($rezept)['ziel_kg'] : null;
        if ($ziel !== null) {
            $this->hochZielKg = rtrim(rtrim(number_format($ziel, 3, ',', ''), '0'), ',');
        }
    }

    public function hochZuruecksetzen(): void
    {
        $this->hochVorbelegen();
    }

    /**
     * Faktor und neue Mengen für die Ansicht. aktiv nur bei einem Faktor ≠ 1 — sonst zeigt die Liste die Rezeptmengen.
     *
     * @return array{aktiv: bool, faktor: ?float, faktor_text: ?string, hinweis: ?string, ansatz_kg: ?float, ziel_kg: ?float, zutat: mixed, mengen: array<int, string>, pruefen: array<int, string>}
     */
    private function hochrechnung(FoodAlchemistRecipe $rezept): array
    {
        $ansatz = Hochrechnung::ansatzKg($rezept);
        $zutat = $this->hochZutatId !== null ? $rezept->ingredients->firstWhere('id', $this->hochZutatId) : null;
        $r = $zutat !== null
            ? Hochrechnung::faktor(Hochrechnung::zahl($this->hochHabe), $zutat->quantity !== null && (float) $zutat->quantity > 0 ? (float) $zutat->quantity : null, Hochrechnung::OHNE_ZUTATMENGE)
            : Hochrechnung::faktor(Hochrechnung::zahl($this->hochZielKg), $ansatz);
        $f = $r['faktor'];
        $aktiv = $f !== null && abs($f - 1) >= 0.0005;

        $mengen = [];
        $pruefen = [];
        if ($aktiv) {
            foreach ($rezept->ingredients as $z) {
                $neu = Hochrechnung::mengeKurz($z->quantity, $z->unit, $f);
                if ($neu !== null && $z->quantity_max !== null) {
                    $neu .= ' – ' . Hochrechnung::mengeKurz($z->quantity_max, $z->unit, $f);
                }
                $mengen[$z->id] = $neu;
                if ($z->quantity !== null && Hochrechnung::speicherWert((float) $z->quantity * $f, $z->unit)['pruefen']) {
                    $pruefen[$z->id] = ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text) . ' '
                        . Hochrechnung::zahlText((float) $z->quantity) . ' → ' . Hochrechnung::zahlText(round((float) $z->quantity * $f, 4))
                        . ' ' . ($z->unit?->display_de ?? $z->unit?->slug);
                }
            }
        }

        // syncIngredients verlangt je Zeile eine Menge > 0 (wie der Zutaten-Editor) → solche Rezepte erst im Editor ergänzen
        $ohneMenge = $rezept->ingredients->filter(fn ($z) => $z->quantity === null || (float) $z->quantity <= 0)
            ->map(fn ($z) => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text)->values()->all();
        $zielKg = $aktiv && $ansatz !== null ? round($ansatz * $f, 3) : null;

        return [
            'aktiv' => $aktiv,
            'faktor' => $aktiv ? $f : null,
            'ohne_menge' => $ohneMenge,
            'bestaetigung' => $aktiv ? 'Alle Zutatmengen ' . Hochrechnung::faktorText($f)
                . ($zielKg !== null ? ' und Ansatz ' . Hochrechnung::zahlText($ansatz) . ' → ' . Hochrechnung::zahlText($zielKg) . ' kg' : '') . ' speichern?'
                . ($pruefen !== [] ? "\n\nBitte prüfen (nicht gerundet):\n– " . implode("\n– ", $pruefen) : '') : null,
            'faktor_text' => $aktiv ? Hochrechnung::faktorText($f) : null,
            'hinweis' => $r['hinweis'],
            'ansatz_kg' => $ansatz,
            'ziel_kg' => $zielKg,
            'zutat' => $zutat,
            'mengen' => $mengen,
            'pruefen' => $pruefen,
        ];
    }

    /**
     * „Als neuen Ansatz übernehmen" (nur am Computer, Kurator 30.10.): Zutatmengen × Faktor und — falls gepflegt —
     * die Ausbeute × Faktor speichern. Kein eigener Speicherweg: Zutaten über RecipeService::syncIngredients wie der
     * Zutaten-Editor (D1-Besitzer-Prüfung, Recompute inkl. Eltern), Ausbeute über RecipeService::update wie der
     * Rezept-Editor. Unterrezept-Zeilen ändern nur ihre Menge. Am Handy gesperrt (nicht in handyLeseMethoden),
     * mit Bearbeitungssperre nur nach „Bearbeiten" (nicht in sperrFreiExtra).
     */
    public function hochUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? app(RecipeService::class)->detail($team, $this->recipeId) : null;
        if ($rezept === null || $rezept->is_sales_recipe) {
            return;
        }
        $hoch = $this->hochrechnung($rezept);
        $f = $hoch['faktor'];
        if ($f === null || $f <= 0 || $hoch['ohne_menge'] !== []) {
            $this->dispatch('fa-saved', message: $f === null ? 'Kein Faktor — erst eine Zielmenge eingeben.' : 'Zeilen ohne Menge — bitte erst im Zutaten-Editor ergänzen.', type: 'error');

            return;
        }

        $zeilen = $rezept->ingredients->map(function ($z) use ($f) {
            $wert = fn ($m) => $m === null ? null : Hochrechnung::speicherWert((float) $m * $f, $z->unit)['wert'];

            return [
                'id' => $z->id, 'gp_id' => $z->gp_id, 'referenced_recipe_id' => $z->referenced_recipe_id,
                'raw_text' => $z->raw_text, 'display_name' => $z->display_name,
                'quantity' => $wert($z->quantity), 'quantity_max' => $wert($z->quantity_max),
                'unit_vocab_id' => $z->unit_vocab_id, 'cooking_loss_pct' => $z->cooking_loss_pct,
                'cooking_loss_source' => $z->cooking_loss_source, 'trimming_loss_pct' => $z->trimming_loss_pct,
                'is_optional' => $z->is_optional, 'note' => $z->note, 'role' => $z->role, 'role_source' => $z->role_source,
                'is_value_relevant' => $z->is_value_relevant,
                'auto_ground' => false,   // nur Mengen ändern — offene Zeilen nicht nebenbei neu zuordnen
            ];
        })->all();

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($team, $rezept, $zeilen, $f) {
                app(RecipeService::class)->syncIngredients($team, $rezept->id, $zeilen);
                if ($rezept->yield_kg_manual !== null) {
                    app(RecipeService::class)->update($team, $rezept->id, ['yield_kg_manual' => round((float) $rezept->yield_kg_manual * $f, 3)]);
                }
            });
        } catch (\RuntimeException $e) {
            $this->dispatch('fa-saved', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->hochVorbelegen();   // Feld = neuer Ansatz, kein alter Faktor
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('kosten-aktualisiert', recipe_id: $rezept->id, ids: app(RecipeRecomputeService::class)->betroffeneRezepte($rezept->id));
        $this->dispatch('fa-saved', message: 'Neuer Ansatz gespeichert');
    }

    // ── Ersatz-Logik (make-or-buy): dieses Rezept ↔ Fertig-GP / Alternativ-Rezept ──

    public function ersatzVerknuepfen(string $kind, int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)
                ->verknuepfe($team, 'recipe', $this->recipeId, $kind, $id);
            $this->ersatzSuche = '';
        } catch (\RuntimeException $e) {
            $this->fehlerAnker = $e->getMessage();
        }
    }

    public function ersatzLoesen(int $equivId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null) {
            app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)->loese($team, $equivId);
        }
    }

    public ?string $fehlerEignung = null;

    /** Eignung-Chips klickbar (M9-01k-Service): aktiv → entfernen, inaktiv → als manual setzen. */
    public function eignungToggle(string $typ, string $slug): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehlerEignung = null;
        $svc = app(\Platform\FoodAlchemist\Services\RecipeService::class);
        try {
            $spalte = $typ === 'level' ? 'level_slug' : 'sector_slug';
            $relation = $typ === 'level' ? 'niveauEignungen' : 'sektorEignungen';
            $aktiv = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($this->recipeId)
                ->{$relation}->pluck($spalte)->contains($slug);
            $aktiv
                ? $svc->entferneEignung($team, $this->recipeId, $typ, $slug)
                : $svc->setzeEignung($team, $this->recipeId, $typ, $slug);
        } catch (\RuntimeException $e) {
            $this->fehlerEignung = $e->getMessage();
        }
    }

    public function toggleSektion(string $sektion): void
    {
        if (in_array($sektion, ['anker', 'pairing', 'nachbarn'], true)) {
            $this->offen[$sektion] = ! ($this->offen[$sektion] ?? false);
        }
    }

    /** Fehler beim Ersatz-Verknüpfen (Name historisch). */
    public ?string $fehlerAnker = null;

    public function neuBerechnen(): void
    {
        if ($this->recipeId !== null) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate($this->recipeId);
            $this->dispatch('recipe-gespeichert');
        }
    }

    // ── M4-12: Workflow-Aktionen ─────────────────────────────────────────

    public function statusSetzen(string $status): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(RecipeService::class)->setStatus($team, $this->recipeId, $status);
        $this->dispatch('recipe-gespeichert');
    }

    public function duplizieren(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $original = app(RecipeService::class)->detail($team, $this->recipeId);
        if ($original === null) {
            return;
        }
        $kopie = app(RecipeService::class)->duplicate($team, $this->recipeId, $original->name . ' (Kopie)');
        $this->recipeId = $kopie->id;
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('recipe-selected', id: $kopie->id);
    }

    public function templateToggle(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        app(RecipeService::class)->setTemplate($team, $this->recipeId);
        $this->dispatch('recipe-gespeichert');
    }

    public function render(RecipeService $recipes)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null
            ? $recipes->detail($team, $this->recipeId)
            : null;

        // #1003: am Handy die Handbuch-Ansicht (nur das Standalone-Detail, nie die Editor-Karteien)
        if ($rezept !== null && $team !== null && ! $this->embedded && $this->section === null && \Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy()) {
            return $this->handyHandbuch($team, $rezept);
        }

        $equivSvc = app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class);

        return view('foodalchemist::livewire.recipes.detail-panel', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            // #1003 Hochrechnen; „Übernehmen" nur fürs Besitzer-Team außerhalb des Lesemodus (wie der Zutaten-Editor)
            'hoch' => $rezept !== null && ! $rezept->is_sales_recipe ? $this->hochrechnung($rezept) : null,
            'hochUebernehmbar' => $rezept !== null && $team !== null && (int) $rezept->team_id === (int) $team->id
                && ! in_array($this->sperrZustand()['modus'] ?? 'aus', ['lesen', 'fremd'], true),
            'rezept' => $rezept,
            // Spec 82 Stufe 3b (E4): Kosten aus Sicht des aktuellen Teams (Besitzer: Rezept-Spalten; sonst Team-Cache)
            'kosten' => $rezept !== null && $team !== null ? app(\Platform\FoodAlchemist\Services\KostenCacheService::class)->fuer($rezept, $team) : null,
            // Spec 82 §6: Machbarkeits-Check ohne KI gegen das Teamprofil DIESES Teams (warnt nur bei ausdrücklich „fehlt“).
            'machbarkeit' => $rezept !== null && $team !== null
                ? app(\Platform\FoodAlchemist\Services\MachbarkeitService::class)->pruefe(new \Platform\FoodAlchemist\Support\MachbarkeitKontext($team, Auth::user()), [$rezept->id])[0]
                : null,
            // KI-Kontext der Erstellung (2026-09-06): Call-Log-Zeile des Generators, ans Rezept gehängt.
            'kiKontext' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService::class)->fuerRezept($rezept) : null,
            'kiHistorie' => $rezept !== null ? app(\Platform\FoodAlchemist\Services\Ai\RecipeKiKontextService::class)->historieFuerRezept($rezept) : [],
            // Spec 43: Gericht-Foto als Mini-Bild im Detail-Panel (Basisrezepte + Gerichte).
            'rezeptBildUrl' => ($rezept !== null && ($rezept->image_context_file_id || $rezept->image_path))
                ? app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($rezept->image_context_file_id, $rezept->image_path)
                : null,
            // Ersatz-Logik: Äquivalenzen dieses Rezepts + Such-Kandidaten fürs Verknüpfen
            'ersatz' => $rezept !== null && $team !== null ? $equivSvc->fuer($team, 'recipe', $rezept->id) : collect(),
            'ersatzKandidaten' => $rezept !== null && $team !== null && $this->ersatzSuche !== ''
                ? $equivSvc->sucheZiele($team, $this->ersatzSuche, 'recipe', $rezept->id) : collect(),
            // Spec 27: die Anleitung als Schritte (Nummer + Text + verknüpfte Fotos)
            'schritte' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::where('recipe_id', $rezept->id)
                    ->with('photos')->orderBy('position')->orderBy('id')->get()
                : collect(),
            // Endprodukt-Bild („so soll es fertig aussehen") — steht ganz oben im Panel
            'endprodukt' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)
                    ->where('is_result', true)->first()
                : null,
            // Fotos ohne Schritt-Verknüpfung und ohne Endprodukt-Markierung = sonstige Rezept-Fotos
            'allgemeineFotos' => $rezept !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)
                    ->where('is_result', false)->whereDoesntHave('steps')
                    ->orderBy('sort_order')->orderBy('id')->get()
                : collect(),
            // Nachtrag 13_REFERENZ: EK je Zeile — dieselbe T3-Kaskade wie der Recompute (eine Regel-Stelle)
            'zeilenEk' => $rezept !== null ? app(RecipeRecomputeService::class)->zeilenKosten($rezept, $team) : [],
            // M4-10: ↑-Navigation („Verwendet in")
            'eltern' => $rezept !== null ? $recipes->getParents($team, $rezept->id) : collect(),
            // Verwaltungs-Block (tauschen + löschen) — nur im Standalone-Panel, wie beim GP
            'tauschBilanz' => $rezept !== null && $this->section === null ? $this->tauschBilanz() : null,
            'tauschKandidaten' => $rezept !== null && $this->section === null ? $this->tauschKandidaten() : collect(),
            'tauschReferenzen' => $rezept !== null && $this->section === null ? $this->tauschReferenzen() : null,
            // v3-Redesign: Standalone-Sidebar nicht mehr ausklappbar → Netz/Kohäsion/Pairings
            // direkt laden, aber NUR standalone (im Editor-Embed/nur-Sektion bleiben sie ungenutzt → gespart).
            // Layer: Pairing-Netz-Daten hier laden statt in der anonymen x-Komponente (gleiche Guard wie kohaesion/pairings = nur Standalone-Panel).
            // Spec 60 · P7: Kombinationslogik + Netz — nur Standalone-Panel, NACHGELADEN (pairingLaden).
            ...$this->pairingFuerView($team, $rezept, $rezept !== null && ! $this->embedded && $this->section === null),
        ]);
    }

    /** #1003 Handy: Kernwerte Ausbeute · Arbeitszeit · EK je kg (Kosten aus Sicht des Teams wie am Computer). */
    private function handyHandbuch($team, FoodAlchemistRecipe $rezept)
    {
        $k = app(\Platform\FoodAlchemist\Services\KostenCacheService::class)->fuer($rezept, $team);
        $ekKg = $k !== null ? $k['ek_per_kg'] : $rezept->ek_per_kg_eur;
        $vollstaendig = $k !== null ? ($k['n_total'] > 0 && $k['n_priced'] >= $k['n_total']) : ($rezept->ek_n_ingredients_total > 0 && $rezept->ek_n_ingredients_priced >= $rezept->ek_n_ingredients_total);
        $ausbeute = $rezept->yield_kg_manual ?? $rezept->yield_kg;

        return view('foodalchemist::livewire.handy.rezept-handbuch', [
            'art' => 'rezept',
            'rezept' => $rezept,
            'karte' => app(\Platform\FoodAlchemist\Services\HandyDetailService::class)->karte($team, $rezept),
            'formen' => collect(),
            'hoch' => $rezept->is_sales_recipe ? null : $this->hochrechnung($rezept),   // #1003 Hochrechnen (Ansicht)
            'kennzahlen' => [
                ['label' => 'Ausbeute', 'value' => $ausbeute !== null ? rtrim(rtrim(number_format((float) $ausbeute, 2, ',', '.'), '0'), ',') . ' kg' : 'fehlt', 'fehlt' => $ausbeute === null],
                ['label' => 'Arbeitszeit', 'value' => $rezept->work_time_min ? $rezept->work_time_min . ' min' : 'fehlt', 'fehlt' => ! $rezept->work_time_min],
                // Auflage 4: eine Teilsumme ist nie ein Preis — „ab …"
                ['label' => 'EK je kg', 'value' => $ekKg !== null ? ($vollstaendig ? '' : 'ab ') . number_format((float) $ekKg, 2, ',', '.') . ' €' : 'fehlt', 'fehlt' => $ekKg === null],
            ],
        ]);
    }

    /** View-Variablen fürs Pairing: leer, bis pairingLaden() für dieses Rezept gelaufen ist. */
    private function pairingFuerView($team, ?FoodAlchemistRecipe $rezept, bool $erlaubt): array
    {
        $bereit = $erlaubt && $rezept !== null && $team !== null && $this->pairingFuer === $rezept->id;
        $daten = $bereit ? $this->pairingDaten($team, $rezept) : ['netz' => ['nodes' => [], 'edges' => [], 'meta' => []], 'kombination' => null];

        return ['pairingBereit' => $bereit, 'pairingErlaubt' => $erlaubt] + $daten;
    }
}
