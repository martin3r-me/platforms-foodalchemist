<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Concerns\HatGeneratorLauf;
use Platform\FoodAlchemist\Livewire\Concerns\HatHardstopAktionen;

/**
 * M4-14: ✨ Basisrezept-Generator — Beschreibung + Richtungs-Parameter
 * (Convenience/Frische/Bio/Niveau/Sektor/Diät-hart), Bestand-Hybrid-Resolver.
 * Aus-Foto/PDF blockiert auf die Martin-Vision-Frage (Hinweis im Modal).
 *
 * Lauf-Mechanik (Queue-Dispatch, Poll, One-Shot-Toggle) liegt seit L7b im
 * geteilten Trait `HatGeneratorLauf` — dieselbe Strecke wie im VK-Generator.
 */
class GeneratorModal extends Component
{
    use HatGeneratorLauf, HatHardstopAktionen;

    public string $description = '';

    /**
     * R5 (Dominique, Ist-App-Vorbild «Richtung (optional)»): Pill-Gruppen statt
     * Selects — '' = (egal); diaet_hart ist MULTI und hart erzwungen; bestand
     * steuert den Hybrid-Resolver-Hinweis; bio_praeferenz dreifach statt bool.
     */
    public array $parameter = [
        'convenience' => '', 'frische' => 'frisch', 'bestand' => 'hybrid',
        'bio_praeferenz' => 'konventionell', 'level' => '', 'sektor' => '',
        'diaet_hart' => [], 'aroma' => '',
    ];

    /** 06·H4: opt-in Favoriten-Modus (Default aus → keine Versteifung). */
    public bool $useFavoritesList = false;

    /** 06·H4b: Favoriten-Block auf Convenience-getaggte verengen (nur bei aktivem Favoriten-Modus). */
    public bool $favoritesConvenienceOnly = false;

    /** Planungs-Ebene: gesetzt, wenn das Modal aus einem „Go" kommt — Lineage-Träger (verknuepfeArtefakt). */
    public ?int $planningSessionId = null;

    /** Pill-Gruppen fürs View (NICHT als @php-Block — Blade-Raw-Block-Falle mit @php(...)-Einzeilern). */
    public const RICHTUNGEN = [
        ['field' => 'convenience', 'label' => 'Eigenleistung', 'optionen' => ['' => 'Keine Vorgabe', 'from_scratch' => 'Alles selbst', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Die KI wählt passend zur Beschreibung.', 'from_scratch' => 'Rohware und eigene Basisrezepte, keine Fertigprodukte.', 'teil_convenience' => 'Halbfabrikate sind erlaubt.', 'voll_convenience' => 'Fertigprodukte werden bevorzugt.']],
        ['field' => 'level', 'label' => 'Niveau', 'optionen' => ['' => 'Keine Vorgabe', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Die KI wählt passend zur Beschreibung.']],
        ['field' => 'bestand', 'label' => 'Bestand nutzen', 'optionen' => ['hybrid' => 'Gemischt', 'nur_bestand' => 'Nur Bestand', 'komplett_neu' => 'Komplett neu'], 'hint' => ['hybrid' => 'Voreinstellung: zuerst vorhandene Grundprodukte und Rezepte, Neues nur für echte Lücken.', 'nur_bestand' => 'Ausschließlich vorhandene Grundprodukte und Rezepte.', 'komplett_neu' => 'Bestand nicht berücksichtigen.']],
        ['field' => 'bio_praeferenz', 'label' => 'Bio', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Keine Präferenz'], 'hint' => ['konventionell' => 'Voreinstellung: Bio wird nicht erzwungen.', 'bio' => 'Bio-Ware wird bevorzugt.', 'egal' => 'Bio und konventionell gleichrangig.']],
        ['field' => 'frische', 'label' => 'Frische', 'optionen' => ['frisch' => 'Frisch', 'tk' => 'Alles aus TK', 'konserve' => 'Konserve und haltbar'], 'hint' => ['frisch' => 'Voreinstellung: frische Ware zuerst.']],
    ];

    public function togglePill(string $feld, string $wert): void
    {
        if ($feld === 'diaet_hart') {                                 // Multi-Select (hart erzwungen)
            $this->parameter['diaet_hart'] = in_array($wert, $this->parameter['diaet_hart'], true)
                ? array_values(array_diff($this->parameter['diaet_hart'], [$wert]))
                : [...$this->parameter['diaet_hart'], $wert];

            return;
        }
        if (array_key_exists($feld, $this->parameter)) {
            $this->parameter[$feld] = $wert;
        }
    }

    public ?string $fehler = null;

    public ?array $ergebnis = null;

    #[On('generator-modal.oeffnen')]
    public function oeffnen(?string $description = null, ?int $planningSessionId = null): void
    {
        $this->reset('fehler', 'ergebnis', 'description', 'planningSessionId', 'laeuft', 'runId', 'anreicherung', 'hinweis', 'fortschritt', 'hardstopMeldung', 'hardstopOffenIndex', 'freigegeben');
        // Planungs-„Go"-Handoff: Brief vorbefüllen + Session als Lineage-Träger mitführen.
        $this->description = $description ?? '';
        $this->planningSessionId = $planningSessionId;
        $this->dispatch('modal.open', name: 'generator-modal');
    }

    /**
     * Async statt inline: der synchrone Call (LLM ~25 s + Nachbearbeitung) riss den
     * nginx-fastcgi-Timeout → 502. Wir dispatchen in die database-Queue und pollen
     * das Ergebnis (pruefeErgebnis) aus dem Cache. Kein Web-Timeout mehr.
     */
    public function generieren(): void
    {
        $this->fehler = null;
        $this->ergebnis = null;
        $this->anreicherung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->description) === '') {
            $this->fehler = 'Beschreibung ist Pflicht.';

            return;
        }

        // Hook-Mapping: der Service kennt bio als bool (4.4r) — die dreifache
        // Präferenz geht zusätzlich als Prompt-Kontext mit (egal ≠ bio erzwingen)
        $parameter = $this->parameter;
        $parameter['bio'] = $parameter['bio_praeferenz'] === 'bio';
        $parameter['use_favorites_list'] = $this->useFavoritesList; // 06·H4 opt-in
        $parameter['favorites_convenience_only'] = $this->useFavoritesList && $this->favoritesConvenienceOnly; // H4b
        if ($this->planningSessionId !== null) {                       // Planungs-„Go": Lineage-Durchreichung
            $parameter['planning_session_id'] = $this->planningSessionId;
        }

        $this->starteLauf($team->id, trim($this->description), $parameter, vkModus: false);
    }

    protected function auswahlEvent(): string
    {
        return 'recipe-selected';
    }

    public function render()
    {
        return view('foodalchemist::livewire.recipes.generator-modal');
    }
}
