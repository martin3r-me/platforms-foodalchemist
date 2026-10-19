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
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65

    /** Spec 65: Sperre gilt dem GP — dasselbe Ziel wie die eingebetteten GP-Detail-Karteien und die Detailspalte. */
    protected function sperrZiel(): ?array
    {
        return $this->gpId !== null ? ['gp', $this->gpId] : null;
    }

    protected function sperrModalName(): ?string
    {
        return 'gp-modal';
    }

    /**
     * Spec 65 · ohne Sperre erlaubt — schreibt nichts: Schließen-Handler, Auto-Vorschlag der Neuanlage (Ziel null),
     * Anreicherungs-Box schließen, Namensvorschlag verwerfen.
     */
    protected function sperrFreiExtra(): array
    {
        return ['geschlossen', 'autoSuggestFromSupplierItem', 'bulkVerwerfen', 'nameVorschlagVerwerfen'];
    }

    /** Abbrechen: ungespeicherten Formularstand verwerfen, GP frisch laden. */
    protected function nachAbbrechen(): void
    {
        if ($this->gpId !== null) {
            $this->oeffnen($this->gpId);
        }
    }

    private const BUILDER_LEER = [
        'hauptzutat' => '', 'condition' => '', 'processing' => '', 'form' => '',
        'portion' => '', 'pflichtangabe' => '',
        'bio' => false, 'vegan' => false, 'glutenfrei' => false, 'laktosefrei' => false,
        'commodity_group_code' => '', 'sub_category' => '',
        'is_derivat' => false, 'derivat_von_gp_id' => null,
    ];

    public ?int $gpId = null;

    // ── Spec 53 Paket J: Aroma-Anker (Sensorik & Pairing) ─────────────────

    public string $gpAnkerSuche = '';

    public string $gpAnkerRolle = 'kern';

    public ?string $gpAnkerFehler = null;

    public array $builder = self::BUILDER_LEER;

    /** Manueller Namens-Override — leer = AUTO-SYNC aus dem Builder (I4). */
    public string $manuellerName = '';

    /** Kalkulations-Defaults (GL-02) — direkt persistiert, nur im Edit-Modus (Phase 2). */
    public array $defaults = ['cooking_loss_default_pct' => '', 'trimming_loss_default_pct' => '', 'piece_default_g' => '', 'diameter_cm' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'volume_ml' => '', 'serving_vehicle_vocab_id' => ''];

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

    /** Rückmeldung des KI-Knopfs „Einordnung vorschlagen" (2026-10-13), steht im Abschnitt Einordnung. */
    public ?string $einordnungHinweis = null;

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
        $this->reset('fehler', 'einordnungHinweis', 'force', 'kiVorschlag', 'kiRohtext', 'laSuche', 'supplierItemId', 'autoSuggestPending', 'manuellerName', 'derivatSuche', 'nameVorschlag', 'bulkRunId', 'equivalentSourceKind', 'equivalentSourceId', 'equivalentReason', 'equivalentConfidence');
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
                // Spec 80 (Paket 11): die strukturierten Felder auch beim Bearbeiten laden.
                'hauptzutat' => (string) ($gp->main_ingredient_display ?: strstr((string) $gp->name . ':', ':', true)),
                'processing' => (string) ($gp->processing ?? ''),
                'form' => (string) ($gp->form ?? ''),
                'bio' => mb_strtolower((string) $gp->bio) === 'bio',
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
                // Maße (nur Warengruppe 16): gespeichert in mm, im Formular in cm bzw. ml wie im Betrieb gesagt
                'diameter_cm' => $gp->diameter_mm !== null ? \Platform\FoodAlchemist\Support\NonFoodMasse::cm((float) $gp->diameter_mm) : '',
                'length_cm' => $gp->length_mm !== null ? \Platform\FoodAlchemist\Support\NonFoodMasse::cm((float) $gp->length_mm) : '',
                'width_cm' => $gp->width_mm !== null ? \Platform\FoodAlchemist\Support\NonFoodMasse::cm((float) $gp->width_mm) : '',
                'height_cm' => $gp->height_mm !== null ? \Platform\FoodAlchemist\Support\NonFoodMasse::cm((float) $gp->height_mm) : '',
                'volume_ml' => $gp->volume_ml !== null ? rtrim(rtrim(number_format((float) $gp->volume_ml, 1, ',', ''), '0'), ',') : '',
                'serving_vehicle_vocab_id' => $gp->serving_vehicle_vocab_id !== null ? (string) $gp->serving_vehicle_vocab_id : '',
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
        $this->sperreBeiSchliessen($name);   // Spec 65: eigene Sperre frei — VOR dem Reset (braucht gpId)
        if ($name === 'gp-modal') {
            $this->reset('gpId', 'builder', 'manuellerName', 'defaults', 'fehler', 'einordnungHinweis', 'force', 'kiVorschlag', 'kiRohtext', 'laSuche', 'supplierItemId', 'autoSuggestPending', 'equivalentSourceKind', 'equivalentSourceId', 'equivalentReason', 'equivalentConfidence');
        }
    }

    /** Größe aus den eingegebenen Maßen in den Namen übernehmen (Regelwerk §6.5: Produkt: Eigenschaft, Größe) — überschreibt die Größe-Angabe. */
    public function masseInNamen(): void
    {
        $groesse = app(\Platform\FoodAlchemist\Services\GpMasseService::class)->groesseAusEingabe($this->defaults);
        if ($groesse !== null) {
            $this->builder['pflichtangabe'] = $groesse;
        }
    }

    /** Maße (nur Warengruppe 16) persistieren: Eingabe in cm/ml, gespeichert in mm. */
    private function speichereMasse(FoodAlchemistGp $gp): void
    {
        if ($gp->istNonFood()) {
            $masse = app(\Platform\FoodAlchemist\Services\GpMasseService::class);
            $masse->setze($gp, array_intersect_key($this->defaults, \Platform\FoodAlchemist\Support\NonFoodMasse::EINGABE));
            $masse->setzeServierTyp($gp, ($this->defaults['serving_vehicle_vocab_id'] ?? '') === '' ? null : (int) $this->defaults['serving_vehicle_vocab_id']);
        }
    }

    /** Kalkulations-Defaults direkt persistieren (Phase 2; nur Edit). Leer ⇒ NULL. */
    private function speichereDefaults(FoodAlchemistGp $gp): void
    {
        app(\Platform\FoodAlchemist\Services\GpService::class)->setzeKalkDefaults($this->teamOderFehler(), (int) $gp->id, $this->defaults);   // Spec 84 C5
    }

    public function speichern(GpNamingService $naming): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }

        try {
            // Non-Food (Warengruppe 16): ist keine Größe angegeben, kommt sie aus den Maßen (Ø 27 cm, 30 x 20 x 5 cm, 212 ml)
            if (($this->builder['commodity_group_code'] ?? '') === \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE && trim((string) ($this->builder['pflichtangabe'] ?? '')) === '') {
                $this->masseInNamen();
            }
            $in = [...$this->builder, 'name' => trim($this->manuellerName)];
            if ($this->gpId === null) {
                $gp = \Illuminate\Support\Facades\DB::transaction(function () use ($naming, $team, $in) {
                    $gp = $naming->createGp($team, $in, $this->force);
                    $this->speichereMasse($gp);
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
                $this->speichereMasse($gp);
            }

            if ($this->gpId === null) {
                // Neuanlage (LA-first, Ersatz-Katalog …): schließt wie bisher — die Aufrufer warten darauf.
                $this->dispatch('modal.close', name: 'gp-modal');
            } else {
                // Spec 65 (Dominique 2026-10-07): Speichern beendet die Bearbeitung, der Editor BLEIBT offen im Lesemodus.
                $this->bearbeitenBeenden();
                $this->manuellerName = $gp->name;   // ggf. vom Naming-Service normalisiert
            }
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
    /**
     * KI-Knopf „Verluste schätzen" (Dominique 2026-10-12): Gar-/Putzverlust und Stückgewicht als Vorgabe
     * des Grundprodukts. Füllt nur LEERE Felder des Formulars (Override-First), gespeichert wird mit
     * dem Speichern-Knopf — der Vorschlag allein schreibt nichts.
     */
    public function verlusteKiSchaetzen(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        $team = $this->team();
        if ($team === null || $this->gpId === null) {
            return;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($this->gpId);
        if ($gp === null) {
            return;
        }
        try {
            $v = $ki->propose('gp.verluste', [
                'name' => $gp->name,
                'condition' => $gp->condition,
                'commodity_group' => $gp->commodity_group?->name,
                'aktuell' => array_filter($this->defaults, fn ($w) => $w !== '' && $w !== null),
            ], ['target_table' => 'foodalchemist_gps', 'target_id' => $gp->id]);
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException) {
            $this->fehler = 'KI derzeit nicht verfügbar.';

            return;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $gesetzt = [];
        $label = ['cooking_loss_default_pct' => 'Garverlust', 'trimming_loss_default_pct' => 'Putzverlust', 'piece_default_g' => 'Stückgewicht'];
        foreach ($label as $feld => $name) {
            $wert = $v->werte[$feld] ?? null;
            if (! is_numeric($wert) || (float) $wert < 0 || (str_ends_with($feld, '_pct') && (float) $wert > 100)) {
                continue;
            }
            if (($this->defaults[$feld] ?? '') !== '') {
                continue;                                              // gepflegt = Entscheidung, nicht überschreiben
            }
            $this->defaults[$feld] = (string) round((float) $wert, 1);
            $gesetzt[] = $name;
        }
        $this->hinweis = $gesetzt === []
            ? 'KI lieferte keinen verwertbaren Vorschlag oder alle Felder sind schon gepflegt.'
            : 'Vorschlag im Formular: '.implode(', ', $gesetzt).'. Speichern übernimmt.';
    }

    /**
     * KI-Knopf „Anker vorschlagen" (Dominique 2026-10-12): Kern-Anker aus dem Vokabular, sofort gesetzt
     * (wie das Verknüpfen von Hand), aber mit ehrlicher Provenienz `ai_inferred` + Konfidenz —
     * Handarbeit (manual) bleibt geschützt, der Vorschlag ersetzt sie nicht.
     */
    public function ankerKiVorschlagen(BulkEnrichService $bulk, \Platform\FoodAlchemist\Services\PairingService $pairing): void
    {
        $this->gpAnkerFehler = null;
        $this->hinweis = null;
        $team = $this->team();
        if ($team === null || $this->gpId === null) {
            return;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($this->gpId);
        if ($gp === null) {
            return;
        }
        try {
            $v = $bulk->gpAnkerVorschlag($team, $gp);
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException) {
            $this->gpAnkerFehler = 'KI derzeit nicht verfügbar.';

            return;
        } catch (\Throwable $e) {
            $this->gpAnkerFehler = $e->getMessage();

            return;
        }
        $slug = $v['value']['anchor_slug'] ?? null;
        if ($slug === null) {
            $this->gpAnkerFehler = 'KI fand keinen passenden Anker im Vokabular.';

            return;
        }
        if ($slug === 'neutral') {
            $this->hinweis = 'KI: kein Aroma-Träger (neutral) — kein Anker gesetzt.';

            return;
        }
        $ankerId = \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_pairing_anchors')
            ->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($ankerId === null) {
            $this->gpAnkerFehler = "Anker „{$slug}\" ist nicht im Vokabular.";

            return;
        }
        try {
            $pairing->setGpAnkerInference($team, $gp->id, (int) $ankerId, (float) ($v['confidence'] ?? 0.5),
                'GP-Editor: KI-Vorschlag aus dem Anker-Vokabular'.(($v['reasoning'] ?? '') !== '' ? ' — '.$v['reasoning'] : ''));
        } catch (\RuntimeException $e) {
            $this->gpAnkerFehler = $e->getMessage();

            return;
        }
        $this->hinweis = "Anker „{$slug}\" per KI gesetzt (Konfidenz ".round((float) ($v['confidence'] ?? 0) * 100).' %).';
    }

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

    // ── Spec 53 Paket J: Aroma-Anker (Sensorik & Pairing) — mehrere je GP,
    //    role kern|neben, sofort gespeichert (kein Speichern-Knopf, wie am Rezept). ──

    public function gpAnkerVerknuepfen(int $ankerId): void
    {
        $this->gpAnkerFehler = null;
        $team = $this->team();
        if ($team === null || $this->gpId === null) {
            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\PairingService::class)
                ->setGpAnker($team, $this->gpId, $ankerId, $this->gpAnkerRolle);
            $this->gpAnkerSuche = '';
        } catch (\RuntimeException $e) {
            $this->gpAnkerFehler = $e->getMessage();
        }
    }

    public function gpAnkerLoesen(int $ankerId): void
    {
        $this->gpAnkerFehler = null;
        $team = $this->team();
        if ($team !== null && $this->gpId !== null) {
            app(\Platform\FoodAlchemist\Services\PairingService::class)->removeGpAnker($team, $this->gpId, $ankerId);
        }
    }

    // ── 06·H4b: Favorit direkt am GP pinnen (zweiter Andockpunkt neben dem Favoriten-Screen — dieselbe Liste).
    //           Spec 85 FAV-1: Liste des aktuellen Teams (Kuratieren), kein Besitz am GP nötig. ──

    public function favoriteToggle(): void
    {
        $this->fehler = null;
        $gp = $this->gp();
        if ($gp === null) {
            return;
        }
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $svc = app(FavoriteGpService::class);
        try {
            isset(\Platform\FoodAlchemist\Support\FavoritenOverlay::fuer($team)[(int) $gp->id]) ? $svc->exclude($team, $gp) : $svc->pin($team, $gp);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
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

    public function accept_zustand(): void
    {
        $vorschlag = $this->kiVorschlag['condition'] ?? null;
        if ($vorschlag === null) {
            return;
        }
        $gp = $this->gpSchreiben(fn ($svc, $team, $id) => $svc->setzeZustand($team, $id, 'ki', $vorschlag['werte']['condition'] ?? null, $vorschlag['confidence'], $vorschlag['reasoning'] ?? null));
        if ($gp !== null) {
            $this->builder['condition'] = $gp->condition;
            unset($this->kiVorschlag['condition']);
        }
    }

    public function clear_zustand(): void
    {
        if ($this->gpSchreiben(fn ($svc, $team, $id) => $svc->setzeZustand($team, $id, null)) !== null) {
            $this->builder['condition'] = '';
            unset($this->kiVorschlag['condition']);
        }
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
        $vorschlag = $this->kiVorschlag['tags'] ?? null;
        if ($vorschlag === null) {
            return;
        }
        $tagWerte = $vorschlag['werte']['tags'] ?? $vorschlag['werte'];      // Fake-Echo packt sie unter 'tags'
        $tags = array_map(fn ($v) => (bool) $v, array_intersect_key((array) $tagWerte, array_flip(FoodAlchemistGp::TAG_FIELDS)));
        $ok = $this->gpSchreiben(fn ($svc, $team, $id) => $svc->setzeTags($team, $id, 'ki', $tags, $vorschlag['confidence'], $vorschlag['reasoning'] ?? null));
        if ($ok !== null) {
            foreach ($tags as $tag => $wert) {
                $this->tags[$tag] = $wert ? '1' : '0';
            }
            unset($this->kiVorschlag['tags']);
        }
    }

    public function clear_tags(): void
    {
        if ($this->gpSchreiben(fn ($svc, $team, $id) => $svc->setzeTags($team, $id, null)) !== null) {
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                $this->tags[$tag] = '';
            }
            unset($this->kiVorschlag['tags']);
        }
    }

    /**
     * Spec 84 C5 · Schreibweg des Modals über GpService (Eigentümer-Riegel + Override-First im Service, gleiche Regel
     * für jeden Aufrufer). Abweisungen landen als Meldung im Modal. null = abgewiesen bzw. kein GP.
     *
     * @param  callable(\Platform\FoodAlchemist\Services\GpService, \Platform\Core\Models\Team, int): mixed  $schreiben
     */
    private function gpSchreiben(callable $schreiben): mixed
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->gpId === null) {
            return null;
        }
        try {
            return $schreiben(app(\Platform\FoodAlchemist\Services\GpService::class), $team, (int) $this->gpId) ?? true;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
    }

    private function teamOderFehler(): \Platform\Core\Models\Team
    {
        return Auth::user()?->currentTeamRelation ?? throw new \RuntimeException('Kein Team im Kontext.');
    }

    // ── ✨ Kopf-Button: Naming-Builder aus Roh-Bezeichnung (NEUE GPs) ────

    /**
     * KI-Knopf „Einordnung vorschlagen" (Dominique 2026-10-13): der bisherige KI-Vorschlag im Abschnitt Einordnung
     * betraf nur den Zustand. Hier schlägt die KI Warengruppe UND Unterkategorie (streng aus der eigenen Taxonomie)
     * sowie den Zustand vor — über denselben Prompt wie die Neuanlage (gp.suggest), geerdet am GP-Namen und am
     * Hauptartikel. Landet im Formular, Speichern übernimmt; die Meldung nennt, was vorher stand.
     */
    public function einordnungKiVorschlagen(AiGatewayService $ki): void
    {
        $this->fehler = null;
        $this->einordnungHinweis = null;
        $team = $this->team();
        $gp = $team !== null && $this->gpId !== null ? FoodAlchemistGp::visibleToTeam($team)->find($this->gpId) : null;
        if ($gp === null) {
            return;
        }
        $vocab = app(VocabularyService::class);
        $warengruppen = $vocab->listWarengruppen($team);
        $taxonomie = $warengruppen->map(fn ($wg) => [
            'code' => (string) $wg->code,
            'name' => (string) $wg->name,
            'sub_categories' => $vocab->listSubCategories($team, (string) $wg->code)->pluck('sub_category')->values()->all(),
        ])->values()->all();
        $kontext = [
            'label' => (string) $gp->name,
            'taxonomie' => $taxonomie,
            'regel' => 'commodity_group_code und sub_category ausschließlich aus taxonomie wählen',
        ];
        $lead = $gp->lead_la_supplier_item_id !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::find($gp->lead_la_supplier_item_id) : null;
        if ($lead !== null) {
            $kontext['quell_lieferantenartikel'] = ['bezeichnung' => $lead->designation];
        }
        try {
            $v = $ki->propose('gp.suggest', $kontext, ['target_table' => 'foodalchemist_gps', 'target_id' => $gp->id]);
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException) {
            $this->fehler = 'KI derzeit nicht verfügbar.';

            return;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $vorher = [$this->builder['commodity_group_code'] ?? '', $this->builder['sub_category'] ?? '', $this->builder['condition'] ?? ''];
        $gesetzt = [];
        $code = trim((string) ($v->werte['commodity_group_code'] ?? ''));
        $wg = $warengruppen->first(fn ($x) => (string) $x->code === $code)
            ?? $warengruppen->first(fn ($x) => mb_strtolower((string) $x->name) === mb_strtolower($code));
        if ($wg !== null) {
            $this->builder['commodity_group_code'] = (string) $wg->code;
            $sub = trim((string) ($v->werte['sub_category'] ?? ''));
            $treffer = self::subKategorieTreffer($vocab->listSubCategories($team, (string) $wg->code)->pluck('sub_category')->all(), $sub);
            $this->builder['sub_category'] = $treffer !== null ? (string) $treffer : '';
            $gesetzt[] = 'Warengruppe '.$wg->codedLabel().($treffer !== null ? ' › '.$treffer : ' (keine passende Unterkategorie)');
        }
        $zustand = $v->werte['condition'] ?? null;
        if (is_string($zustand) && in_array($zustand, GpNamingService::ZUSTAND_VOCAB, true)) {
            $this->builder['condition'] = $zustand;
            $gesetzt[] = 'Zustand '.$zustand;
        }
        if ($gesetzt === []) {
            $this->einordnungHinweis = 'KI fand keine passende Einordnung in deiner Taxonomie.';

            return;
        }
        $alt = trim(implode(' › ', array_filter([$vorher[0], $vorher[1]])).($vorher[2] !== '' ? ', '.$vorher[2] : ''), ' ,');
        $this->einordnungHinweis = 'Vorschlag im Formular: '.implode(' · ', $gesetzt)
            .($alt !== '' ? ' (bisher: '.$alt.')' : '').'. Speichern übernimmt, Abbrechen verwirft.';
    }

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
        $naming = app(GpNamingService::class);
        foreach (['hauptzutat', 'condition', 'processing', 'form', 'pflichtangabe'] as $feld) {
            // „unbekannt" & Co. sind kein Wert: das Feld bleibt leer und gelangt nie in Name oder Kennung (Regelwerk GP §6, §12)
            $wert = $naming->bereinigeFeld($vorschlag->werte[$feld] ?? null);
            if ($wert !== '') {
                $this->builder[$feld] = $wert;
            }
        }
        // Nachlauf (Praxisfall „Delice Schokoladen-Mousse mit Cashew-Crème"): Hauptzutat nur belegte Kernwörter, Zustand hart aus der
        // Haltbarkeit, Form/Verarbeitung ohne Dublette zur Hauptzutat
        $quellTexte = $quelle !== null
            ? array_filter([$quelle['bezeichnung'] ?? null, $quelle['marketing_name'] ?? null, $quelle['regulierter_name'] ?? null, $quelle['zutatenangabe'] ?? null])
            : [trim($this->kiRohtext)];
        if (trim((string) ($this->builder['hauptzutat'] ?? '')) !== '') {
            $haupt = $naming->bereinigeHauptzutat((string) $this->builder['hauptzutat'], array_values($quellTexte));
            if ($haupt['wert'] !== '') {
                $this->builder['hauptzutat'] = $haupt['wert'];
            }
            if ($haupt['entfernt'] !== []) {
                $this->hinweis = 'Hauptzutat gekürzt (nicht im Artikeltext belegt oder Zusatz): '.implode(' ', $haupt['entfernt']);
            }
        }
        foreach (['form', 'processing'] as $feld) {
            $this->builder[$feld] = $naming->dedupliziereMitHauptzutat((string) $this->builder['hauptzutat'], (string) ($this->builder[$feld] ?? ''));
        }
        $this->zusatzAusQuellenSetzen();
        $h = $quelle['haltbarkeit'] ?? [];
        $hart = $naming->zustandAusHaltbarkeit($h['gekuehlt_tage'] ?? null, $h['tiefgekuehlt_tage'] ?? null, $h['trocken_tage'] ?? null);
        if ($hart !== null) {
            $this->builder['condition'] = $hart;
        }
        $code = trim((string) ($vorschlag->werte['commodity_group_code'] ?? ''));
        $wg = $warengruppen->first(fn ($x) => (string) $x->code === $code)
            ?? $warengruppen->first(fn ($x) => mb_strtolower((string) $x->name) === mb_strtolower($code));
        if ($wg !== null) {
            $this->builder['commodity_group_code'] = (string) $wg->code;
            $erlaubt = $vocab->listSubCategories($team, (string) $wg->code)->pluck('sub_category');
            $treffer = self::subKategorieTreffer($erlaubt->all(), (string) ($vorschlag->werte['sub_category'] ?? ''));
            $this->builder['sub_category'] = $treffer ?? '';
        }
        if ($this->supplierItemId === null) {
            $this->laSuche = trim($this->kiRohtext);
        }
    }

    /**
     * Unterkategorie der KI gegen die erlaubte Liste der Warengruppe abgleichen: exakt, dann ohne Umlaut/Satzzeichen, dann Teilwort.
     * Kein Treffer = null (leer lassen, nie raten).
     *
     * @param  list<string>  $erlaubt
     */
    private static function subKategorieTreffer(array $erlaubt, string $vorschlag): ?string
    {
        $norm = fn (string $x) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtr(mb_strtolower($x), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => ' und '])));
        $v = $norm($vorschlag);
        if ($v === '') {
            return null;
        }
        foreach ($erlaubt as $e) {
            if ($norm((string) $e) === $v) {
                return (string) $e;
            }
        }
        $teil = array_values(array_filter($erlaubt, fn ($e) => ($n = $norm((string) $e)) !== '' && (str_contains($n, $v) || str_contains($v, $n))));

        return count($teil) === 1 ? (string) $teil[0] : null;
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
            ->with(['supplier:id,name', 'structure', 'allergens', 'nutritionals'])
            ->find($this->supplierItemId);
        if ($la === null || $la->structure?->gp_id !== null) {
            return null;
        }
        $allergene = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $key => $label) {
            $wert = $la->allergens?->{'allergen_'.$key} ?? null;
            if (in_array($wert, ['enthalten', 'spuren'], true)) {
                $allergene[] = $label.' ('.$wert.')';
            }
        }
        $naehrwerte = $la->nutritionals !== null
            ? array_filter($la->nutritionals->only(['energy_kcal', 'protein', 'fat', 'carbs_absorbable']), fn ($v) => $v !== null)
            : [];

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
            // Nur zur Einordnung (Form, Zustand, Sortiment), NIE Teil des GP-Namens (§7.1): Gebinde und Einheit
            // Haltbarkeit je Lagerart (Stammdaten bzw. Produktpass): tiefgekühlt → Zustand TK
            'haltbarkeit' => array_filter(['gekuehlt_tage' => $la->shelf_life_chilled_days, 'tiefgekuehlt_tage' => $la->shelf_life_frozen_days, 'trocken_tage' => $la->shelf_life_dry_days], fn ($t) => $t !== null),
            'gebinde_hinweis' => array_filter(['menge' => $la->qty !== null ? (float) $la->qty : null, 'einheit' => $la->unit_code, 'verpackung' => $la->packaging_unit]),
            'allergene' => $allergene,
            'naehrwerte_je_100' => $naehrwerte,
            'aehnliche_artikel_mit_gp' => $this->aehnlicheArtikelMitGp($la),
        ];
    }

    /**
     * Bereits zugeordnete Lieferantenartikel mit gleichem Anfangswort der Bezeichnung und deren GP-Namen: reale Vorbilder
     * für Hauptzutat, Zustand und Form (z. B. Sortenname → Mischung). Höchstens 5, nur sichtbare Artikel.
     */
    private function aehnlicheArtikelMitGp(\Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem $la): array
    {
        $team = $this->team();
        $erstes = trim((string) preg_split('/[\s,]+/u', trim((string) $la->designation), 2)[0]);
        if ($team === null || mb_strlen($erstes) < 4) {
            return [];
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)
            ->where('foodalchemist_supplier_items.id', '!=', $la->id)
            ->where('designation', 'like', $erstes.'%')
            ->whereHas('structure', fn ($q) => $q->whereNotNull('gp_id'))
            ->with('structure.gp:id,name')
            ->limit(5)->get()
            ->map(fn ($x) => ['bezeichnung' => (string) $x->designation, 'gp' => (string) ($x->structure?->gp?->name ?? '')])
            ->filter(fn ($z) => $z['gp'] !== '')->values()->all();
    }

    /** Zusatz (Bio/Vegan/Glutenfrei/Laktosefrei) nur aus ausdrücklichen Quellaussagen setzen; Widersprüche als Hinweis, nie aus fehlenden Allergenen. */
    private function zusatzAusQuellenSetzen(): void
    {
        $team = $this->team();
        $la = $team !== null && $this->supplierItemId !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)->with('allergens')->find($this->supplierItemId)
            : null;
        if ($la === null) {
            return;
        }
        $allergene = [];
        foreach (array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE) as $key) {
            $allergene[$key] = $la->allergens?->{'allergen_'.$key};
        }
        $z = app(GpNamingService::class)->zusatzAusQuellen([
            'bezeichnung' => $la->designation, 'marketing_name' => $la->marketing_name, 'regulierter_name' => $la->regulated_name,
            'zutaten' => $la->ingredients_supplier, 'is_organic' => $la->is_organic, 'is_vegan' => $la->is_vegan,
            'bio_kontrollnummer' => $la->organic_control_number, 'allergene' => $allergene,
        ]);
        foreach ($z['flags'] as $flag => $an) {
            if ($an) {
                $this->builder[$flag] = true;       // nur auf ausdrückliche Quellaussage
            }
        }
        foreach ($z['widerspruch'] as $flag) {
            // Handeingabe wird nie abgeschaltet, AUSSER bei echtem Widerspruch zu „enthalten" (z. B. vegan trotz Milch): dann false + Hinweis
            $this->builder[$flag] = false;
        }
        if ($z['hinweise'] !== []) {
            $this->hinweis = trim(($this->hinweis ?? '').' '.implode(' ', $z['hinweise']));
        }
    }

    /** Gebindemenge des verknüpften bzw. gewählten Lieferantenartikels in Gramm (kg/g), sonst null. */
    private function gebindeGramm(?\Platform\FoodAlchemist\Models\FoodAlchemistGp $gp): ?float
    {
        $id = $this->supplierItemId ?? $gp?->lead_la_supplier_item_id;
        $la = $id !== null ? \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::find($id) : null;
        if ($la === null || $la->qty === null) {
            return null;
        }

        return match (mb_strtolower((string) $la->unit_code)) {
            'kg' => (float) $la->qty * 1000,
            'g' => (float) $la->qty,
            default => null,
        };
    }

    /** Wert des Felds „Gewicht oder Portion" in Gramm („180 g", „0,66 kg"), sonst null. */
    private function portionGramm(): ?float
    {
        if (! preg_match('/^\s*([0-9]+(?:[.,][0-9]+)?)\s*(kg|g)?\s*$/iu', (string) ($this->builder['portion'] ?? ''), $m)) {
            return null;
        }
        $zahl = (float) str_replace(',', '.', $m[1]);

        return mb_strtolower($m[2] ?? 'g') === 'kg' ? $zahl * 1000 : $zahl;
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
            $wert = app(GpNamingService::class)->bereinigeFeld($vorschlag->werte[$feld] ?? null);
            if ($wert !== '') {
                $builder[$feld] = $wert;
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
        // Verpackungsangaben gehören nie in den GP-Namen (§7.1): „Portion" gleich der Gebindemenge des verknüpften LA ist fast sicher die Packungsgröße
        $gebinde = $this->gebindeGramm($gp);
        if ($gebinde !== null && ($p = $this->portionGramm()) !== null && abs($p - $gebinde) < 0.5) {
            $pruefung['warnings'][] = '§7.1: „Gewicht oder Portion“ ('.trim((string) $this->builder['portion']).') entspricht der Gebindemenge des Lieferantenartikels. Packungsgrößen gehören nie in den GP-Namen. Das Feld nur für die Portionsgrammatur pro Stück nutzen.';
        }
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
        $leadPreis = $leadLa !== null ? $preise->activeFor($leadLa->id, Auth::user()?->currentTeamRelation) : null; // Spec 82 3a

        // Layer: Mutter-GP-Name fürs Derivat-Feld team-gescopet im Component laden
        // (Blade zeigt nur die Variable, statt selbst ::find() ungescopet auszuführen).
        $derivatVonId = $this->builder['derivat_von_gp_id'] ?? null;
        $derivatMutterName = $derivatVonId !== null && $team !== null
            ? FoodAlchemistGp::visibleToTeam($team)->whereKey($derivatVonId)->value('name')
            : null;

        return view('foodalchemist::livewire.gps.gp-modal', [
            'servierTypen' => ($this->builder['commodity_group_code'] ?? '') === '16' && $team !== null
                ? \Platform\FoodAlchemist\Support\TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_vocab_serving_vehicles')->whereNull('deleted_at')->where('is_inactive', false), 'team_id', $team)->orderBy('group_name')->orderBy('sort_order')->pluck('name', 'id')->all()
                : [],
            'sperr' => $this->sperrZustand(),   // Spec 65
            'gp' => $gp,
            // Spec 85 FAV-1: Favorit des aktuellen Teams (Rang) und ob es seine Liste pflegen darf (Kuratieren)
            'favorit' => $gp !== null && $team !== null ? (\Platform\FoodAlchemist\Support\FavoritenOverlay::fuer($team)[(int) $gp->id] ?? null) : null,
            'darfFavoriten' => $team !== null && Auth::user() !== null
                && app(\Platform\FoodAlchemist\Services\FaRechte::class)->darf(Auth::user(), $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren),
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
            'formSlugs' => \Platform\FoodAlchemist\Services\GpFormService::formSlugs(Auth::user()?->currentTeamRelation),
            'sensorik' => $this->gpId !== null ? app(\Platform\FoodAlchemist\Services\SensorikService::class)->fuerGp($this->gpId) : null,
            'pairing' => $this->gpId !== null ? app(\Platform\FoodAlchemist\Services\PairingService::class)->panelGp($this->gpId) : null,
            // Spec 53 Paket J: Aroma-Anker editierbar (kern + neben) — panelGp()/pairing.blade.php
            // bleiben read-only (liefern nur 'kern', ohne id/role); dieser Block ist die Schreib-Fläche.
            'gpAnker' => $this->gpId !== null
                ? app(\Platform\FoodAlchemist\Services\PairingService::class)->gpAnkerAlle($this->gpId)
                : collect(),
            'gpAnkerKandidaten' => $this->gpAnkerSuche !== '' && $team !== null
                ? \Platform\FoodAlchemist\Support\TeamScope::applyVisible(
                    \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at'),
                    'team_id', $team,
                )
                    ->where(function ($w) {
                        $like = '%'.mb_strtolower($this->gpAnkerSuche).'%';
                        $w->whereRaw('LOWER(slug) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(display_de) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(display_en) LIKE ?', [$like]);
                    })
                    ->orderBy('display_de')->limit(8)->get(['id', 'slug', 'display_de', 'display_en', 'category'])
                : collect(),
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

    /** Spec 80 (Paket 11): Name nach §6 aus den Feldern neu bilden (nach einer Feldänderung). */
    public function nameAusFeldern(GpNamingService $naming): void
    {
        $name = trim($naming->renderGpName($this->builder));
        if ($name !== '') {
            $this->manuellerName = $name;
        }
    }

    /**
     * Spec 80 (Paket 11): Bestands-GP — Felder einmalig aus dem bisherigen Namen übernehmen (nur leere Felder;
     * Gepflegtes bleibt). Danach gilt das Feld; der Name lässt sich mit {@see nameAusFeldern} neu bilden.
     */
    public function felderAusName(GpNamingService $naming): void
    {
        foreach ($naming->felderAusName($this->manuellerName) as $feld => $wert) {
            if ($feld === 'bio') {
                $this->builder['bio'] = (bool) ($this->builder['bio'] ?? false) || $wert;

                continue;
            }
            if (trim((string) ($this->builder[$feld] ?? '')) === '' && $wert !== '') {
                $this->builder[$feld] = $wert;
            }
        }
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
        $tags = [];
        foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
            $tags[$tag] = $this->tags[$tag] === '' ? null : $this->tags[$tag] === '1';
        }
        // Spec 84 C5: über GpService (Eigentümer-Riegel; schreibt nur bei Änderung)
        $this->gpSchreiben(fn ($svc, $team, $id) => $svc->setzeTags($team, (int) $gp->id, $source, $tags));
    }
}
