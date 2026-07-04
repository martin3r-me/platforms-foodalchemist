<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpService;
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

    /** @var array<string, array{werte: array, confidence: float, reasoning: ?string}> transiente GL-07-Vorschläge */
    public array $kiVorschlag = [];

    /** @var array<string, string> Tri-State je TAG_FIELD: '' = unbewertet, '1' = ja, '0' = nein */
    public array $tags = [];

    public string $derivatSuche = '';

    /** Namensvorschlag aus der Lead-LA (Override-First: erst Vorschlag, dann Übernehmen). */
    public ?string $nameVorschlag = null;

    /** Laufender Bulk-Autopilot-Run (Zustand+Tags+Allergene+Nährwerte in einem Rutsch). */
    public ?int $bulkRunId = null;

    #[On('gp-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        $this->reset('fehler', 'force', 'kiVorschlag', 'kiRohtext', 'manuellerName', 'derivatSuche', 'nameVorschlag', 'bulkRunId');
        $this->gpId = $id;
        $this->builder = self::BUILDER_LEER;
        $this->tags = array_fill_keys(FoodAlchemistGp::TAG_FIELDS, '');

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
    }

    #[On('modal.closed')]
    public function geschlossen(string $name): void
    {
        if ($name === 'gp-modal') {
            $this->reset('gpId', 'builder', 'manuellerName', 'defaults', 'fehler', 'force', 'kiVorschlag', 'kiRohtext');
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
                $gp = $naming->createGp($team, $in, $this->force);
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
            $this->dispatch('gp-selected', id: $gp->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
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
        $this->bulkRunId = app(BulkEnrichService::class)->starteGp($team, [$gp->id]);
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
        $gp = $this->gp();
        $vorschlag = $ki->propose('gp.condition', [
            'name' => $gp?->name ?? $this->vorschauName(),
            'condition' => $this->builder['condition'] ?: null,
        ]);
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
        $gp = $this->gp();
        $vorschlag = $ki->propose('gp.tags', [
            'name' => $gp?->name ?? $this->vorschauName(),
            'tags' => collect($this->tags)->filter(fn ($v) => $v !== '')->map(fn ($v) => $v === '1')->all(),
        ]);
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
        $vorschlag = $ki->propose('gp.suggest', ['label' => trim($this->kiRohtext)]);
        foreach (['hauptzutat', 'condition', 'processing', 'form', 'pflichtangabe'] as $feld) {
            if (! empty($vorschlag->werte[$feld]) && is_string($vorschlag->werte[$feld])) {
                $this->builder[$feld] = $vorschlag->werte[$feld];
            }
        }
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

        $vorschlag = $ki->propose('gp.suggest', ['label' => trim($designation)]);
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

    public function render(GpNamingService $naming, VocabularyService $vocab)
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

        return view('foodalchemist::livewire.gps.gp-modal', [
            'gp' => $gp,
            'neu' => $this->gpId === null,
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
            'statusFaelle' => [GpStatus::Approved, GpStatus::Tentative, GpStatus::Rejected],
            'bulkRun' => $this->bulkRunId !== null && $team !== null ? app(BulkEnrichService::class)->status($team, $this->bulkRunId) : null,
            'bulkOffen' => $this->bulkRunId !== null && $team !== null ? app(BulkEnrichService::class)->offeneGpVorschlaege($team, $this->bulkRunId) : 0,
            'zustandVocab' => GpNamingService::ZUSTAND_VOCAB,
            'derivatKandidaten' => $this->derivatSuche !== '' && $team !== null
                ? FoodAlchemistGp::visibleToTeam($team)->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($this->derivatSuche) . '%'])->orderBy('name')->limit(6)->get()
                : collect(),
            'sensorik' => $this->gpId !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerGp($this->gpId) : null,
            'pairing' => $this->gpId !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->panelGp($this->gpId) : null,
        ]);
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
