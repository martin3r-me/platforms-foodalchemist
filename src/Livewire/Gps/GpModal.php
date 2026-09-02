<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\FavoriteGpService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M3-09/10: GP-Modal — Neuanlage über den Naming-Builder (GL-12 Render-First,
 * AUTO-SYNC-Vorschau für Name/Slug/gp_key), Edit für Klassifikation + KI-Felder.
 *
 * KI-Felder (GL-07-Lebenszyklus, M3-10): `condition` + `tags` mit ki-header-Baustein —
 * ai_* holt einen Vorschlag (persistiert nichts), accept_* schreibt Wert + Lineage
 * (Override-First: manuelle Quelle wird nie still überschrieben), clear_* setzt
 * Wert + Lineage zurück, manual_* markiert den aktuellen Wert als manuell gepflegt.
 */
class GpModal extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    private const BUILDER_LEER = [
        'hauptzutat' => '', 'condition' => '', 'processing' => '', 'form' => '',
        'portion' => '', 'pflichtangabe' => '',
        'bio' => false, 'vegan' => false, 'glutenfrei' => false, 'laktosefrei' => false,
        'commodity_group_code' => '', 'sub_category' => '',
        'is_derivat' => false, 'derivat_von_gp_id' => null,
    ];

    public ?int $gpId = null;

    public array $builder = self::BUILDER_LEER;

    /** Manueller Namens-Override — leer = AUTO-SYNC aus dem Builder (I4). */
    public string $manuellerName = '';

    /** Kalkulations-Defaults (GL-02) — direkt persistiert, nur im Edit-Modus (Phase 2). */
    public array $defaults = ['cooking_loss_default_pct' => '', 'trimming_loss_default_pct' => '', 'piece_default_g' => ''];

    public bool $force = false;

    public ?string $fehler = null;

    /** ✨-Kopf-Button (Neuanlage): Roh-Bezeichnung für gp.suggest. */
    public string $kiRohtext = '';

    /** Optionaler realer Einkaufsartikel, aus dem das GP entsteht (LA-first). */
    public string $laSuche = '';

    public ?int $supplierItemId = null;

    /** Der LA-first-Editor ist offen; der automatische gp.suggest-Lauf folgt separat. */
    public bool $autoSuggestPending = false;

    /** Optional: das neue LA-first-GP direkt als Ersatz des Quellbausteins katalogisieren. */
    public ?string $equivalentSourceKind = null;

    public ?int $equivalentSourceId = null;

    public ?string $equivalentReason = null;

    public ?float $equivalentConfidence = null;

    /** @var array<string, array{werte: array, confidence: float, reasoning: ?string}> transiente GL-07-Vorschläge */
    public array $kiVorschlag = [];

    /** @var array<string, string> Tri-State je TAG_FIELD: '' = unbewertet, '1' = ja, '0' = nein */
    public array $tags = [];

    public string $derivatSuche = '';

    /** #8 (2026-08-27): „GP in allen Rezepten tauschen" — aus dem Detail-Panel in den Editor gezogen. */
    public string $tauschSuche = '';

    /** Erfolgs-Hinweis (z.B. nach GP-Tausch) — transient, wie $fehler. */
    public ?string $hinweis = null;

    /** #9 (2026-08-28): Naturaleinheit-Formen — Eingabe für „Form hinzufügen". */
    public string $formNeuSlug = 'stk';

    public string $formNeuGramm = '';

    /** Namensvorschlag aus der Lead-LA (Override-First: erst Vorschlag, dann Übernehmen). */
    public ?string $nameVorschlag = null;

    /** Laufender Bulk-Autopilot-Run (Zustand+Tags+Allergene+Nährwerte in einem Rutsch). */
    public ?int $bulkRunId = null;

    #[On('gp-modal.oeffnen')]
    public function oeffnen(
        ?int $id = null,
        ?int $laId = null,
        bool $autoSuggest = false,
        ?string $equivalentSourceKind = null,
        ?int $equivalentSourceId = null,
        ?string $equivalentReason = null,
        ?float $equivalentConfidence = null,
    ): void
    {
        $this->reset('fehler', 'force', 'kiVorschlag', 'kiRohtext', 'laSuche', 'supplierItemId', 'autoSuggestPending', 'manuellerName', 'derivatSuche', 'nameVorschlag', 'bulkRunId', 'equivalentSourceKind', 'equivalentSourceId', 'equivalentReason', 'equivalentConfidence');
        $this->gpId = $id;
        $this->builder = self::BUILDER_LEER;
        $this->tags = array_fill_keys(FoodAlchemistGp::TAG_FIELDS, '');

        if ($id === null && $laId !== null && $this->team() !== null) {
            $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($this->team())->with('structure')->find($laId);
            if ($la !== null && $la->structure?->gp_id === null) {
                $this->supplierItemId = (int) $la->id;
                $this->kiRohtext = (string) $la->designation;
                $this->laSuche = (string) $la->designation;
                $this->builder['bio'] = (bool) ($la->is_organic ?? false);
                $this->builder['vegan'] = (bool) ($la->is_vegan ?? false);
                $this->autoSuggestPending = $autoSuggest;
            }
        }

        if ($id === null && in_array($equivalentSourceKind, ['gp', 'recipe'], true) && $equivalentSourceId !== null) {
            $this->equivalentSourceKind = $equivalentSourceKind;
            $this->equivalentSourceId = $equivalentSourceId;
            $this->equivalentReason = $equivalentReason;
            $this->equivalentConfidence = $equivalentConfidence !== null
                ? min(1, max(0, $equivalentConfidence)) : null;
        }

        if ($id !== null && ($gp = $this->gp()) !== null) {
            $this->manuellerName = $gp->name;
            $this->builder = array_merge(self::BUILDER_LEER, [
                'condition' => $gp->condition ?? '',
                'commodity_group_code' => $gp->commodity_group_code ?? '',
                'sub_category' => $gp->sub_category ?? '',
                'is_derivat' => (bool) $gp->is_derivat,
                'derivat_von_gp_id' => $gp->derivat_von_gp_id,
            ]);
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                $wert = $gp->getAttribute("tag_{$tag}");
                $this->tags[$tag] = $wert === null ? '' : ($wert ? '1' : '0');
            }
            $this->defaults = [
                'cooking_loss_default_pct' => $gp->cooking_loss_default_pct !== null ? (string) (float) $gp->cooking_loss_default_pct : '',
                'trimming_loss_default_pct' => $gp->trimming_loss_default_pct !== null ? (string) (float) $gp->trimming_loss_default_pct : '',
                'piece_default_g' => $gp->piece_default_g !== null ? (string) (float) $gp->piece_default_g : '',
            ];
        }

        $this->dispatch('modal.open', name: 'gp-modal');
        if ($this->autoSuggestPending && $this->supplierItemId !== null) {
            // Zweiter Livewire-Request: das Modal wird sofort sichtbar; der Provider-Call
            // blockiert nicht das Oeffnen und bleibt als Ladezustand nachvollziehbar.
            $this->dispatch('gp-modal.auto-suggest', laId: $this->supplierItemId)->to(self::class);
        }
    }

    #[On('gp-modal.auto-suggest')]
    public function autoSuggestFromSupplierItem(int $laId, AiGatewayService $ki): void
    {
        if (! $this->autoSuggestPending || $this->supplierItemId !== $laId || $this->gpId !== null) {
            return;
        }

        try {
            $this->kiVorschlagNaming($ki);
        } finally {
            $this->autoSuggestPending = false;
        }
    }

    #[On('modal.closed')]
    public function geschlossen(string $name): void
    {
        if ($name === 'gp-modal') {
            $this->reset('gpId', 'builder', 'manuellerName', 'defaults', 'fehler', 'force', 'kiVorschlag', 'kiRohtext', 'laSuche', 'supplierItemId', 'autoSuggestPending', 'equivalentSourceKind', 'equivalentSourceId', 'equivalentReason', 'equivalentConfidence');
        }
    }

    /** Kalkulations-Defaults direkt persistieren (Phase 2; nur Edit). Leer ⇒ NULL. */
    private function speichereDefaults(FoodAlchemistGp $gp): void
    {
        $num = fn ($v) => trim((string) $v) === '' ? null : max(0, (float) str_replace(',', '.', (string) $v));
        $gp->update([
            'cooking_loss_default_pct' => $num($this->defaults['cooking_loss_default_pct'] ?? ''),
            'trimming_loss_default_pct' => $num($this->defaults['trimming_loss_default_pct'] ?? ''),
            'piece_default_g' => $num($this->defaults['piece_default_g'] ?? ''),
        ]);
    }

    public function speichern(GpNamingService $naming): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        try {
            $in = [...$this->builder, 'name' => trim($this->manuellerName)];
            if ($this->gpId === null) {
                $gp = \Illuminate\Support\Facades\DB::transaction(function () use ($naming, $team, $in) {
                    $gp = $naming->createGp($team, $in, $this->force);
                    if ($this->supplierItemId !== null) {
                        app(LeadLaService::class)->verknuepfen($team, $gp, $this->supplierItemId);
                    }
                    if ($this->equivalentSourceKind !== null && $this->equivalentSourceId !== null) {
                        $this->assertEquivalentSourceWritable($team);
                        app(\Platform\FoodAlchemist\Services\ComponentEquivalentService::class)->verknuepfe(
                            $team,
                            $this->equivalentSourceKind,
                            $this->equivalentSourceId,
                            'gp',
                            (int) $gp->id,
                            notes: $this->equivalentReason,
                            matchConfidence: $this->equivalentConfidence,
                        );
                    }
                    return $gp;
                });
            } else {
                $gp = $this->gp();
                if ($gp === null) {
                    return;
                }
                if (! Curate::canCurate(Auth::user(), $gp)) {
                    $this->fehler = 'Geerbtes Katalog-GP — Pflege nur durchs Besitzer-Team (D1).';

                    return;
                }
                $gp = $naming->updateGp($team, $gp, $in);
                $this->speichereTags($gp);
                $this->speichereDefaults($gp);
            }

            $this->dispatch('modal.close', name: 'gp-modal');
            $this->dispatch('gp-gespeichert');
            if ($this->equivalentSourceId === null) {
                $this->dispatch('gp-selected', id: $gp->id);
            }
            $this->savedToast('Grundprodukt gespeichert');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function assertEquivalentSourceWritable(\Platform\Core\Models\Team $team): void
    {
        if ($this->equivalentSourceKind === 'gp') {
            $source = FoodAlchemistGp::visibleToTeam($team)->find($this->equivalentSourceId);
            if ($source === null || ! Curate::canCurate(Auth::user(), $source)) {
                throw new \RuntimeException('Das Quell-GP darf nicht als Ersatzkatalog bearbeitet werden.');
            }

            return;
        }
        if ($this->equivalentSourceKind === 'recipe') {
            $source = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)
                ->where('team_id', $team->id)->find($this->equivalentSourceId);
            if ($source === null) {
                throw new \RuntimeException('Das Quell-Rezept darf nicht als Ersatzkatalog bearbeitet werden.');
            }

            return;
        }

        throw new \RuntimeException('Ungültige Ersatz-Quelle.');
    }

    /** Status-Regler im Modal-Kopf (Kurations-Pflege, D1-Gate). */
    public function statusSetzen(GpService $gps, string $status): void
    {
        $this->fehler = null;
        $gp = $this->gp();
        if ($gp === null) {
            return;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Status ist Katalog-Pflege — nur fürs Besitzer-Team (D1).';

            return;
        }
        $fall = GpStatus::tryFrom($status);
        if ($fall === null) {
            return;
        }
        try {
            $gps->setStatus($gp, $fall);
            $this->dispatch('gp-gespeichert'); // Browser-Tabelle (Status-Spalte) aktualisieren
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * #8 (Dominique 2026-08-27): „GP in allen Rezepten tauschen" — aus dem Detail-Panel in den Editor
     * gezogen. Hängt ALLE Rezept-Zeilen dieses GP auf $zielId um (Vorstufe zum Löschen), Rezepte werden
     * neu berechnet. Globale Katalog-Aktion → nur fürs Besitzer-Team (Curate-Gate).
     */
    public function gpErsetzen(int $zielId): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        $gp = $this->gp();
        if ($gp === null) {
            return;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'GP-Tausch ist Katalog-Pflege — nur fürs Besitzer-Team (D1).';

            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        $ziel = $team !== null ? FoodAlchemistGp::visibleToTeam($team)->find($zielId) : null;
        if ($ziel === null) {
            $this->fehler = 'Ziel-GP nicht gefunden.';

            return;
        }
        try {
            $ergebnis = app(GpService::class)->ersetzeInRezepten($gp, $ziel);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->tauschSuche = '';
        $this->hinweis = "{$ergebnis['zeilen']} Zeile(n) in {$ergebnis['rezepte']} Rezept(en) auf „{$ziel->name}“ umgehängt — Rezepte neu berechnet.";
        $this->dispatch('gp-gespeichert');
    }

    // ── #9 (2026-08-28): Naturaleinheit-Formen (Gramm je Form) ─────────────────

    /** Form aus den Eingabefeldern setzen/aktualisieren. */
    public function formSetzen(\Platform\FoodAlchemist\Services\GpFormService $forms): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        if ($this->gpId === null) {
            return;
        }
        $gramm = (float) str_replace(',', '.', trim($this->formNeuGramm));
        try {
            $forms->setForm($this->team(), $this->gpId, $this->formNeuSlug, $gramm, 'manual');
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->formNeuGramm = '';
        $this->dispatch('gp-gespeichert');
    }

    public function formEntfernen(\Platform\FoodAlchemist\Services\GpFormService $forms, string $slug): void
    {
        $this->fehler = null;
        if ($this->gpId === null) {
            return;
        }
        try {
            $forms->removeForm($this->team(), $this->gpId, $slug);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->dispatch('gp-gespeichert');
    }

    /** ✨ KI schätzt die anwendbaren Formen + Gramm (manuelle bleiben, Override-First). */
    public function formenKiSchaetzen(\Platform\FoodAlchemist\Services\GpFormService $forms): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        if ($this->gpId === null) {
            return;
        }
        try {
            $n = $forms->estimateKi($this->team(), $this->gpId);
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException) {
            $this->fehler = 'KI derzeit nicht verfügbar.';

            return;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->hinweis = $n > 0 ? "{$n} Form(en) per KI geschätzt." : 'Keine zusätzlichen Formen ableitbar (oder alles manuell gepflegt).';
        $this->dispatch('gp-gespeichert');
    }

    // ── 06·H4b: Favorit direkt am GP pinnen (zweiter Andockpunkt neben dem
    //           Favoriten-Screen — landet in derselben Liste, Feld is_favorite).
    //           D1-Gate; jeder approved GP ist favoritisierbar (kein §4-Zwang). ──

    public function favoriteToggle(): void
    {
        $this->fehler = null;
        $gp = $this->gp();
        if ($gp === null) {
            return;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Favorit ist Katalog-Pflege — nur fürs Besitzer-Team (D1).';

            return;
        }
        $svc = app(FavoriteGpService::class);
        $gp->is_favorite ? $svc->exclude($gp) : $svc->pin($gp);
        $this->dispatch('gp-gespeichert');               // Screen/Browser können reagieren
    }

    // ── ✨ Alles anreichern (GP-Bulk-Autopilot: Zustand+Tags+Allergene+Nährwerte) ──

    public function allesAnreichern(): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $gp = $this->gp();
        if ($team === null || $gp === null) {
            return;
        }
        if (! Curate::canCurate(Auth::user(), $gp)) {
            $this->fehler = 'Anreichern ist Katalog-Pflege — nur fürs Besitzer-Team (D1).';

            return;
        }
        try {
            $this->bulkRunId = app(BulkEnrichService::class)->starteGp($team, [$gp->id]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();                          // sync-Queue (demo) ohne Provider → graceful
        }
    }

    public function bulkAlleUebernehmen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team !== null && $this->bulkRunId !== null) {
            app(BulkEnrichService::class)->alleUebernehmenGp($team, $this->bulkRunId);
            $this->bulkRunId = null;
            $this->oeffnen($this->gpId);                              // Werte neu laden (Builder/Tags)
            $this->dispatch('gp-gespeichert');
        }
    }

    public function bulkVerwerfen(): void
    {
        $this->bulkRunId = null;                                      // Run-Box schließen; Vorschläge bleiben offen (verwerfbar via Review)
    }

    // ── M3-10: GL-07-Lebenszyklus condition ───────────────────────────────

    public function ai_zustand(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $gp = $this->gp();
        try {
            $vorschlag = $ki->propose('gp.condition', [
                'name' => $gp?->name ?? $this->vorschauName(),
                'condition' => $this->builder['condition'] ?: null,
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->kiVorschlag['condition'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),     // GL-07 Confidence-Clamp
            'reasoning' => $vorschlag->reasoning,
        ];
    }

    public function accept_zustand(GpNamingService $naming): void
    {
        $gp = $this->gp();
        $vorschlag = $this->kiVorschlag['condition'] ?? null;
        if ($gp === null || $vorschlag === null) {
            return;
        }
        if ($gp->condition_source === 'manual') {                              // GL-07 Override-First
            $this->fehler = 'condition ist manuell gepflegt — erst Reset (clear), dann KI übernehmen.';

            return;
        }
        $wert = $naming->normalisiereZustand($vorschlag['werte']['condition'] ?? null);
        if ($wert === null || ! in_array($wert, GpNamingService::ZUSTAND_VOCAB, true)) {
            $this->fehler = 'KI-Vorschlag enthält keinen gültigen §9-Zustand.';

            return;
        }
        $gp->update([
            'condition' => $wert,
            'condition_source' => 'ki',
            'condition_ai_confidence' => $vorschlag['confidence'],
            'condition_ai_reasoning' => $vorschlag['reasoning'],
        ]);
        $this->builder['condition'] = $wert;
        unset($this->kiVorschlag['condition']);
    }

    public function clear_zustand(): void
    {
        $this->gp()?->update([
            'condition' => null, 'condition_source' => null,
            'condition_ai_confidence' => null, 'condition_ai_reasoning' => null,
        ]);
        $this->builder['condition'] = '';
        unset($this->kiVorschlag['condition']);
    }

    public function manual_zustand(GpNamingService $naming): void
    {
        $gp = $this->gp();
        $wert = $naming->normalisiereZustand($this->builder['condition'] ?: null);
        if ($gp === null || $wert === null) {
            return;
        }
        $gp->update([
            'condition' => $wert, 'condition_source' => 'manual',
            'condition_ai_confidence' => null, 'condition_ai_reasoning' => null,
        ]);
    }

    // ── M3-10: GL-07-Lebenszyklus tags ──────────────────────────────────

    public function ai_tags(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $gp = $this->gp();
        try {
            $vorschlag = $ki->propose('gp.tags', [
                'name' => $gp?->name ?? $this->vorschauName(),
                'tags' => collect($this->tags)->filter(fn ($v) => $v !== '')->map(fn ($v) => $v === '1')->all(),
            ]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->kiVorschlag['tags'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'reasoning' => $vorschlag->reasoning,
        ];
    }

    public function accept_tags(): void
    {
        $gp = $this->gp();
        $vorschlag = $this->kiVorschlag['tags'] ?? null;
        if ($gp === null || $vorschlag === null) {
            return;
        }
        if ($gp->tag_source === 'manual') {                                  // GL-07 Override-First
            $this->fehler = 'Tags sind manuell gepflegt — erst Reset (clear), dann KI übernehmen.';

            return;
        }
        $update = [];
        $tagWerte = $vorschlag['werte']['tags'] ?? $vorschlag['werte'];      // Fake-Echo packt sie unter 'tags'
        foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
            if (array_key_exists($tag, $tagWerte)) {
                $update["tag_{$tag}"] = (bool) $tagWerte[$tag];
                $this->tags[$tag] = $tagWerte[$tag] ? '1' : '0';
            }
        }
        if ($update === []) {
            $this->fehler = 'KI-Vorschlag enthält keine bekannten Tags.';

            return;
        }
        $gp->update([...$update,
            'tag_source' => 'ki',
            'tag_ai_confidence' => $vorschlag['confidence'],
            'tag_ai_reasoning' => $vorschlag['reasoning'],
            'tag_aggregated_at' => now(),
        ]);
        unset($this->kiVorschlag['tags']);
    }

    public function clear_tags(): void
    {
        $gp = $this->gp();
        if ($gp === null) {
            return;
        }
        $reset = [];
        foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
            $reset["tag_{$tag}"] = null;
            $this->tags[$tag] = '';
        }
        $gp->update([...$reset, 'tag_source' => null, 'tag_ai_confidence' => null, 'tag_ai_reasoning' => null]);
        unset($this->kiVorschlag['tags']);
    }

    public function manual_tags(): void
    {
        $this->speichereTags($this->gp(), source: 'manual');
    }

    // ── ✨ Kopf-Button: Naming-Builder aus Roh-Bezeichnung (NEUE GPs) ────

    public function kiVorschlagNaming(AiGatewayService $ki): void
    {
        if (trim($this->kiRohtext) === '') {
            return;
        }
        $this->fehler = null;
        try {
            $team = $this->team();
            $vocab = app(VocabularyService::class);
            $warengruppen = $team !== null ? $vocab->listWarengruppen($team) : collect();
            $taxonomie = $warengruppen->map(fn ($wg) => [
                'code' => (string) $wg->code,
                'name' => (string) $wg->name,
                'sub_categories' => $vocab->listSubCategories($team, (string) $wg->code)->pluck('sub_category')->values()->all(),
            ])->values()->all();
            $kontext = [
                'label' => trim($this->kiRohtext),
                'taxonomie' => $taxonomie,
                'regel' => 'commodity_group_code und sub_category ausschließlich aus taxonomie wählen',
            ];
            $quelle = $this->supplierItemContext();
            if ($this->supplierItemId !== null && $quelle === null) {
                $this->fehler = 'Der ausgewählte Lieferantenartikel ist nicht mehr verfügbar oder bereits einem GP zugeordnet.';

                return;
            }
            if ($quelle !== null) {
                $kontext['quell_lieferantenartikel'] = $quelle;
            }
            $vorschlag = $ki->propose('gp.suggest', $kontext);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        foreach (['hauptzutat', 'condition', 'processing', 'form', 'pflichtangabe'] as $feld) {
            if (! empty($vorschlag->werte[$feld]) && is_string($vorschlag->werte[$feld])) {
                $this->builder[$feld] = $vorschlag->werte[$feld];
            }
        }
        $code = trim((string) ($vorschlag->werte['commodity_group_code'] ?? ''));
        $wg = $warengruppen->first(fn ($x) => (string) $x->code === $code)
            ?? $warengruppen->first(fn ($x) => mb_strtolower((string) $x->name) === mb_strtolower($code));
        if ($wg !== null) {
            $this->builder['commodity_group_code'] = (string) $wg->code;
            $sub = trim((string) ($vorschlag->werte['sub_category'] ?? ''));
            $erlaubt = $vocab->listSubCategories($team, (string) $wg->code)->pluck('sub_category');
            $treffer = $erlaubt->first(fn ($x) => mb_strtolower((string) $x) === mb_strtolower($sub));
            $this->builder['sub_category'] = $treffer !== null ? (string) $treffer : '';
        }
        if ($this->supplierItemId === null) {
            $this->laSuche = trim($this->kiRohtext);
        }
    }

    /**
     * Ausgewaehlter LA als grounding fuer gp.suggest. Verpackungsdaten bleiben bewusst
     * draussen: sie duerfen nach Regelwerk §7.1 nie Teil des GP-Namens werden.
     */
    private function supplierItemContext(): ?array
    {
        $team = $this->team();
        if ($team === null || $this->supplierItemId === null) {
            return null;
        }
        $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure'])
            ->find($this->supplierItemId);
        if ($la === null || $la->structure?->gp_id !== null) {
            return null;
        }

        return [
            'id' => (int) $la->id,
            'bezeichnung' => (string) $la->designation,
            'regulierter_name' => $la->regulated_name,
            'marketing_name' => $la->marketing_name,
            'zutatenangabe' => $la->ingredients_supplier,
            'marke' => $la->brand,
            'hersteller' => $la->manufacturer,
            'herkunft' => $la->origin ?: $la->origin_country,
            'lieferant' => $la->supplier?->name,
            'bio' => $la->is_organic,
            'vegan' => $la->is_vegan,
            'vegetarisch' => $la->is_vegetarian,
            'alkohol' => $la->is_alcohol,
        ];
    }

    public function supplierItemWaehlen(int $id): void
    {
        $team = $this->team();
        $la = $team !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)->with('structure')->find($id)
            : null;
        if ($la === null || $la->structure?->gp_id !== null) {
            $this->fehler = 'Lieferantenartikel ist nicht verfügbar oder bereits einem GP zugeordnet.';
            return;
        }
        $this->supplierItemId = (int) $la->id;
        $this->laSuche = (string) $la->designation;
        if ($this->kiRohtext === '') {
            $this->kiRohtext = (string) $la->designation;
        }
    }

    public function supplierItemLoesen(): void
    {
        $this->supplierItemId = null;
    }

    // ── Name aus Lead-LA ableiten (Wording kommt aus dem Lieferantenartikel) ──

    public function nameAusLeadLa(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $this->nameVorschlag = null;
        $gp = $this->gp();
        if ($gp === null) {
            return;
        }

        $designation = $gp->lead_la_supplier_item_id !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::find($gp->lead_la_supplier_item_id)?->designation
            : null;
        if ($designation === null) {                                   // Fallback: irgendeine verknüpfte LA
            $designation = $gp->structures()->with('item')->get()->pluck('item.designation')->filter()->first();
        }
        if ($designation === null || trim($designation) === '') {
            $this->fehler = 'Kein verknüpfter Lieferantenartikel — kein Namens-Quelltext vorhanden.';

            return;
        }

        try {
            $vorschlag = $ki->propose('gp.suggest', ['label' => trim($designation)]);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $builder = $this->builder;
        foreach (['hauptzutat', 'condition', 'processing', 'form', 'pflichtangabe'] as $feld) {
            if (! empty($vorschlag->werte[$feld]) && is_string($vorschlag->werte[$feld])) {
                $builder[$feld] = $vorschlag->werte[$feld];
            }
        }
        $name = trim(app(GpNamingService::class)->renderGpName($builder));
        if ($name === '') {
            $this->fehler = 'KI lieferte keinen verwertbaren Namensvorschlag aus der LA-Bezeichnung.';

            return;
        }
        $this->nameVorschlag = $name;
    }

    /** Vorschlag übernehmen = der EINE Schreib-Moment (Override-First, GL-07). */
    public function nameVorschlagUebernehmen(): void
    {
        if ($this->nameVorschlag !== null) {
            $this->manuellerName = $this->nameVorschlag;
            $this->nameVorschlag = null;
        }
    }

    public function nameVorschlagVerwerfen(): void
    {
        $this->nameVorschlag = null;
    }

    public function render(GpNamingService $naming, VocabularyService $vocab, PriceService $preise, GpAggregateService $aggregate)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $this->gp();
        $name = $this->vorschauName();
        $slug = $naming->slugify($this->builder['hauptzutat'] ?: ($gp->main_ingredient_slug ?? ''));
        $pruefung = $naming->validateGpName($name, [...$this->builder, 'hauptzutat' => $this->builder['hauptzutat'] ?: $name]);
        // R20: Drift (I4) ist nur aussagekräftig, wenn die strukturierten Felder gepflegt sind —
        // Bestands-GPs ohne Builder-Pflege meldeten sonst IMMER Drift (Falsch-Positiv).
        if (trim($this->builder['hauptzutat'] ?? '') === '') {
            $pruefung['warnings'] = array_values(array_filter($pruefung['warnings'], fn ($w) => ! str_starts_with($w, 'Drift:')));
        }

        // Spec 28 / E3.1: Kennzahlen für den KPI-Kopf des Voll-Editors. Bewusst DIESELBEN
        // Größen, die das GP-Cockpit im Detail-Panel führt (Lead-Preis · n LAs ·
        // Allergen-Konfidenz) — nichts neu erfunden, nur im Editor sichtbar gemacht.
        // Zwei zusätzliche Reads, nur bei geladenem GP; die Tab-Panels dieses Modals mounten
        // ohnehin schwerere Detail-Panel-Kinder.
        $leadLa = $gp?->leadLa;
        $leadPreis = $leadLa !== null ? $preise->activeFor($leadLa->id) : null;

        // Layer: Mutter-GP-Name fürs Derivat-Feld team-gescopet im Component laden
        // (Blade zeigt nur die Variable, statt selbst ::find() ungescopet auszuführen).
        $derivatVonId = $this->builder['derivat_von_gp_id'] ?? null;
        $derivatMutterName = $derivatVonId !== null && $team !== null
            ? FoodAlchemistGp::visibleToTeam($team)->whereKey($derivatVonId)->value('name')
            : null;

        return view('foodalchemist::livewire.gps.gp-modal', [
            'gp' => $gp,
            'neu' => $this->gpId === null,
            'leadLa' => $leadLa,
            'leadPreis' => $leadPreis,
            'allergenKonfidenz' => $gp !== null ? $aggregate->allergenKonfidenz($gp) : null,
            'vorschauName' => $name,
            'vorschauSlug' => $slug,
            'vorschauKey' => $this->gpId === null
                ? $naming->buildGpKey($slug, $this->builder['processing'] ?: null, $this->builder['form'] ?: null)
                : ($gp->gp_key ?? ''),
            'warnungen' => $pruefung['warnings'],
            'liveFehler' => $pruefung['errors'],
            'warengruppen' => $team !== null ? $vocab->listWarengruppen($team) : collect(),
            // Punkt C: WG-gescopetes Sub-Kategorie-Dropdown (verwaltet + GP-Freitext gemerged, #371)
            'subKategorien' => $team !== null && ($this->builder['commodity_group_code'] ?? '') !== ''
                ? $vocab->listSubCategories($team, $this->builder['commodity_group_code'])
                : collect(),
            'supplierItem' => $this->supplierItemId !== null && $team !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)->with('supplier:id,name')->find($this->supplierItemId)
                : null,
            'supplierItemKandidaten' => $this->gpId === null && $team !== null && $this->supplierItemId === null && trim($this->laSuche) !== ''
                ? app(LeadLaService::class)->sucheVerknuepfbare($team, $this->laSuche, 8)
                : collect(),
            'statusFaelle' => [GpStatus::Approved, GpStatus::Tentative, GpStatus::Rejected],
            'bulkRun' => $this->bulkRunId !== null && $team !== null ? app(BulkEnrichService::class)->status($team, $this->bulkRunId) : null,
            'bulkOffen' => $this->bulkRunId !== null && $team !== null ? app(BulkEnrichService::class)->offeneGpVorschlaege($team, $this->bulkRunId) : 0,
            'zustandVocab' => GpNamingService::ZUSTAND_VOCAB,
            'derivatKandidaten' => $this->derivatSuche !== '' && $team !== null
                ? \Platform\FoodAlchemist\Support\Suche::like(FoodAlchemistGp::visibleToTeam($team), 'name', $this->derivatSuche)->orderBy('name')->limit(6)->get()
                : collect(),
            'derivatMutterName' => $derivatMutterName,
            // #8: Tausch-Ziele („GP in allen Rezepten tauschen") — Namensfilter, keine merged/rejected/Platzhalter, nicht der GP selbst.
            'tauschKandidaten' => $gp !== null && $team !== null && $this->tauschSuche !== ''
                ? \Platform\FoodAlchemist\Support\Suche::like(FoodAlchemistGp::visibleToTeam($team), 'name', $this->tauschSuche)
                    ->whereNotIn('status', ['merged', 'rejected'])->where('id', '!=', $gp->id)->where('is_platzhalter', false)
                    ->orderBy('name')->limit(8)->get(['id', 'name', 'status'])
                : collect(),
            // #9: Naturaleinheit-Formen (Gramm je Form) + wählbare Form-Slugs für „Form hinzufügen".
            'formen' => $gp !== null ? app(\Platform\FoodAlchemist\Services\GpFormService::class)->list($gp->id) : collect(),
            'formSlugs' => \Platform\FoodAlchemist\Services\GpFormService::FORM_SLUGS,
            'sensorik' => $this->gpId !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerGp($this->gpId) : null,
            'pairing' => $this->gpId !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->panelGp($this->gpId) : null,
        ]);
    }

    private function team(): ?\Platform\Core\Models\Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    private function gp(): ?FoodAlchemistGp
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($this->gpId === null || $team === null) {
            return null;
        }

        return FoodAlchemistGp::visibleToTeam($team)->find($this->gpId);
    }

    private function vorschauName(): string
    {
        if (trim($this->manuellerName) !== '') {
            return trim($this->manuellerName);                               // Override → Drift-Warning (I4)
        }

        return app(GpNamingService::class)->renderGpName($this->builder);
    }

    private function speichereTags(?FoodAlchemistGp $gp, string $source = 'manual'): void
    {
        if ($gp === null) {
            return;
        }
        $update = [];
        $geaendert = false;
        foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
            $neu = $this->tags[$tag] === '' ? null : $this->tags[$tag] === '1';
            $update["tag_{$tag}"] = $neu;
            $alt = $gp->getAttribute("tag_{$tag}");
            $geaendert = $geaendert || ($alt === null ? $neu !== null : $neu !== (bool) $alt);
        }
        if ($geaendert) {
            $gp->update([...$update, 'tag_source' => $source, 'tag_ai_confidence' => null, 'tag_ai_reasoning' => null]);
        }
    }
}
