<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M3-09/10: GP-Modal — Neuanlage über den Naming-Builder (GL-12 Render-First,
 * AUTO-SYNC-Vorschau für Name/Slug/gp_key), Edit für Klassifikation + KI-Felder.
 *
 * KI-Felder (GL-07-Lebenszyklus, M3-10): `zustand` + `tags` mit ki-header-Baustein —
 * ai_* holt einen Vorschlag (persistiert nichts), accept_* schreibt Wert + Lineage
 * (Override-First: manuelle Quelle wird nie still überschrieben), clear_* setzt
 * Wert + Lineage zurück, manual_* markiert den aktuellen Wert als manuell gepflegt.
 */
class GpModal extends Component
{
    private const BUILDER_LEER = [
        'hauptzutat' => '', 'zustand' => '', 'verarbeitung' => '', 'form' => '',
        'portion' => '', 'pflichtangabe' => '',
        'bio' => false, 'vegan' => false, 'glutenfrei' => false, 'laktosefrei' => false,
        'warengruppe_code' => '', 'sub_kategorie' => '',
        'is_derivat' => false, 'derivat_von_gp_id' => null,
    ];

    public ?int $gpId = null;

    public array $builder = self::BUILDER_LEER;

    /** Manueller Namens-Override — leer = AUTO-SYNC aus dem Builder (I4). */
    public string $manuellerName = '';

    public bool $force = false;

    public ?string $fehler = null;

    /** ✨-Kopf-Button (Neuanlage): Roh-Bezeichnung für gp.suggest. */
    public string $kiRohtext = '';

    /** @var array<string, array{werte: array, confidence: float, begruendung: ?string}> transiente GL-07-Vorschläge */
    public array $kiVorschlag = [];

    /** @var array<string, string> Tri-State je TAG_FIELD: '' = unbewertet, '1' = ja, '0' = nein */
    public array $tags = [];

    public string $derivatSuche = '';

    #[On('gp-modal.oeffnen')]
    public function oeffnen(?int $id = null): void
    {
        $this->reset('fehler', 'force', 'kiVorschlag', 'kiRohtext', 'manuellerName', 'derivatSuche');
        $this->gpId = $id;
        $this->builder = self::BUILDER_LEER;
        $this->tags = array_fill_keys(FoodAlchemistGp::TAG_FIELDS, '');

        if ($id !== null && ($gp = $this->gp()) !== null) {
            $this->manuellerName = $gp->name;
            $this->builder = array_merge(self::BUILDER_LEER, [
                'zustand' => $gp->zustand ?? '',
                'warengruppe_code' => $gp->warengruppe_code ?? '',
                'sub_kategorie' => $gp->sub_kategorie ?? '',
                'is_derivat' => (bool) $gp->is_derivat,
                'derivat_von_gp_id' => $gp->derivat_von_gp_id,
            ]);
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                $wert = $gp->getAttribute("tag_{$tag}");
                $this->tags[$tag] = $wert === null ? '' : ($wert ? '1' : '0');
            }
        }

        $this->dispatch('modal.open', name: 'gp-modal');
    }

    #[On('modal.closed')]
    public function geschlossen(string $name): void
    {
        if ($name === 'gp-modal') {
            $this->reset('gpId', 'builder', 'manuellerName', 'fehler', 'force', 'kiVorschlag', 'kiRohtext');
        }
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
            }

            $this->dispatch('modal.close', name: 'gp-modal');
            $this->dispatch('gp-gespeichert');
            $this->dispatch('gp-selected', id: $gp->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── M3-10: GL-07-Lebenszyklus zustand ───────────────────────────────

    public function ai_zustand(AiGatewayService $ki): void
    {
        $gp = $this->gp();
        $vorschlag = $ki->propose('gp.zustand', [
            'name' => $gp?->name ?? $this->vorschauName(),
            'zustand' => $this->builder['zustand'] ?: null,
        ]);
        $this->kiVorschlag['zustand'] = [
            'werte' => $vorschlag->werte,
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),     // GL-07 Confidence-Clamp
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_zustand(GpNamingService $naming): void
    {
        $gp = $this->gp();
        $vorschlag = $this->kiVorschlag['zustand'] ?? null;
        if ($gp === null || $vorschlag === null) {
            return;
        }
        if ($gp->zustand_quelle === 'manual') {                              // GL-07 Override-First
            $this->fehler = 'zustand ist manuell gepflegt — erst Reset (clear), dann KI übernehmen.';

            return;
        }
        $wert = $naming->normalisiereZustand($vorschlag['werte']['zustand'] ?? null);
        if ($wert === null || ! in_array($wert, GpNamingService::ZUSTAND_VOCAB, true)) {
            $this->fehler = 'KI-Vorschlag enthält keinen gültigen §9-Zustand.';

            return;
        }
        $gp->update([
            'zustand' => $wert,
            'zustand_quelle' => 'ki',
            'zustand_ai_confidence' => $vorschlag['confidence'],
            'zustand_ai_begruendung' => $vorschlag['begruendung'],
        ]);
        $this->builder['zustand'] = $wert;
        unset($this->kiVorschlag['zustand']);
    }

    public function clear_zustand(): void
    {
        $this->gp()?->update([
            'zustand' => null, 'zustand_quelle' => null,
            'zustand_ai_confidence' => null, 'zustand_ai_begruendung' => null,
        ]);
        $this->builder['zustand'] = '';
        unset($this->kiVorschlag['zustand']);
    }

    public function manual_zustand(GpNamingService $naming): void
    {
        $gp = $this->gp();
        $wert = $naming->normalisiereZustand($this->builder['zustand'] ?: null);
        if ($gp === null || $wert === null) {
            return;
        }
        $gp->update([
            'zustand' => $wert, 'zustand_quelle' => 'manual',
            'zustand_ai_confidence' => null, 'zustand_ai_begruendung' => null,
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
            'begruendung' => $vorschlag->begruendung,
        ];
    }

    public function accept_tags(): void
    {
        $gp = $this->gp();
        $vorschlag = $this->kiVorschlag['tags'] ?? null;
        if ($gp === null || $vorschlag === null) {
            return;
        }
        if ($gp->tag_quelle === 'manual') {                                  // GL-07 Override-First
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
            'tag_quelle' => 'ki',
            'tag_ai_confidence' => $vorschlag['confidence'],
            'tag_ai_begruendung' => $vorschlag['begruendung'],
            'tag_aggregiert_am' => now(),
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
        $gp->update([...$reset, 'tag_quelle' => null, 'tag_ai_confidence' => null, 'tag_ai_begruendung' => null]);
        unset($this->kiVorschlag['tags']);
    }

    public function manual_tags(): void
    {
        $this->speichereTags($this->gp(), quelle: 'manual');
    }

    // ── ✨ Kopf-Button: Naming-Builder aus Roh-Bezeichnung (NEUE GPs) ────

    public function kiVorschlagNaming(AiGatewayService $ki): void
    {
        if (trim($this->kiRohtext) === '') {
            return;
        }
        $vorschlag = $ki->propose('gp.suggest', ['bezeichnung' => trim($this->kiRohtext)]);
        foreach (['hauptzutat', 'zustand', 'verarbeitung', 'form', 'pflichtangabe'] as $feld) {
            if (! empty($vorschlag->werte[$feld]) && is_string($vorschlag->werte[$feld])) {
                $this->builder[$feld] = $vorschlag->werte[$feld];
            }
        }
    }

    public function render(GpNamingService $naming, VocabularyService $vocab)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $this->gp();
        $name = $this->vorschauName();
        $slug = $naming->slugify($this->builder['hauptzutat'] ?: ($gp->hauptzutat_slug ?? ''));
        $pruefung = $naming->validateGpName($name, [...$this->builder, 'hauptzutat' => $this->builder['hauptzutat'] ?: $name]);

        return view('foodalchemist::livewire.gps.gp-modal', [
            'gp' => $gp,
            'neu' => $this->gpId === null,
            'vorschauName' => $name,
            'vorschauSlug' => $slug,
            'vorschauKey' => $this->gpId === null
                ? $naming->buildGpKey($slug, $this->builder['verarbeitung'] ?: null, $this->builder['form'] ?: null)
                : ($gp->gp_key ?? ''),
            'warnungen' => $pruefung['warnings'],
            'liveFehler' => $pruefung['errors'],
            'warengruppen' => $team !== null ? $vocab->listWarengruppen($team) : collect(),
            'zustandVocab' => GpNamingService::ZUSTAND_VOCAB,
            'derivatKandidaten' => $this->derivatSuche !== '' && $team !== null
                ? FoodAlchemistGp::visibleToTeam($team)->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($this->derivatSuche) . '%'])->orderBy('name')->limit(6)->get()
                : collect(),
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

    private function speichereTags(?FoodAlchemistGp $gp, string $quelle = 'manual'): void
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
            $gp->update([...$update, 'tag_quelle' => $quelle, 'tag_ai_confidence' => null, 'tag_ai_begruendung' => null]);
        }
    }
}
