<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * M4-14: ✨ Basisrezept-Generator — Beschreibung + Richtungs-Parameter
 * (Convenience/Frische/Bio/Niveau/Sektor/Diät-hart), Bestand-Hybrid-Resolver.
 * Aus-Foto/PDF blockiert auf die Martin-Vision-Frage (Hinweis im Modal).
 */
class GeneratorModal extends Component
{
    public string $beschreibung = '';

    /**
     * R5 (Dominique, Ist-App-Vorbild «Richtung (optional)»): Pill-Gruppen statt
     * Selects — '' = (egal); diaet_hart ist MULTI und hart erzwungen; bestand
     * steuert den Hybrid-Resolver-Hinweis; bio_praeferenz dreifach statt bool.
     */
    public array $parameter = [
        'convenience' => '', 'frische' => 'frisch', 'bestand' => 'hybrid',
        'bio_praeferenz' => 'konventionell', 'niveau' => '', 'sektor' => '',
        'diaet_hart' => [], 'aroma' => '',
    ];

    /** Pill-Gruppen fürs View (NICHT als @php-Block — Blade-Raw-Block-Falle mit @php(...)-Einzeilern). */
    public const RICHTUNGEN = [
        ['feld' => 'convenience', 'label' => 'Convenience (Eigenleistung)', 'optionen' => ['' => '(egal)', 'from_scratch' => 'From Scratch', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Keine Vorgabe', 'from_scratch' => 'alles selbst — Pool dreht auf Roh/Sub-Rezepte', 'teil_convenience' => 'Halbfabrikate erlaubt', 'voll_convenience' => 'Fertigprodukte bevorzugt']],
        ['feld' => 'niveau', 'label' => 'Niveau', 'optionen' => ['' => '(egal)', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Keine Vorgabe']],
        ['feld' => 'bestand', 'label' => 'Bestand-Nutzung', 'optionen' => ['hybrid' => 'Hybrid', 'nur_bestand' => 'Nur Bestand', 'komplett_neu' => 'Komplett neu'], 'hint' => ['hybrid' => 'Default — Bestand zuerst reusen, Neues nur für echte Lücken (agentischer Resolver entscheidet)', 'nur_bestand' => 'ausschließlich vorhandene GPs/Rezepte', 'komplett_neu' => 'Bestand ignorieren']],
        ['feld' => 'bio_praeferenz', 'label' => 'Bio-Präferenz', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Egal'], 'hint' => ['konventionell' => 'Standard — konventionelle Ware, KEIN Bio in den Treffern (Default)', 'bio' => 'Bio bevorzugt (4.4r: nur auf Ansage)', 'egal' => 'keine Präferenz']],
        ['feld' => 'frische', 'label' => 'Frische-Hook', 'optionen' => ['frisch' => 'Frisch', 'tk' => 'Alles aus TK', 'konserve' => 'Konserve/haltbar'], 'hint' => ['frisch' => 'fresh_first (Default)']],
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
    public function oeffnen(): void
    {
        $this->reset('fehler', 'ergebnis', 'beschreibung');
        $this->dispatch('modal.open', name: 'generator-modal');
    }

    public function generieren(RecipeGeneratorService $generator): void
    {
        $this->fehler = null;
        $this->ergebnis = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->beschreibung) === '') {
            $this->fehler = 'Beschreibung ist Pflicht.';

            return;
        }

        try {
            // Hook-Mapping: der Service kennt bio als bool (4.4r) — die dreifache
            // Präferenz geht zusätzlich als Prompt-Kontext mit (egal ≠ bio erzwingen)
            $parameter = $this->parameter;
            $parameter['bio'] = $parameter['bio_praeferenz'] === 'bio';
            $resultat = $generator->generiere($team, trim($this->beschreibung), $parameter);
            $this->ergebnis = [
                'recipe_id' => $resultat['recipe']->id,
                'name' => $resultat['recipe']->name,
                'statistik' => $resultat['statistik'],
                'offene' => $resultat['offene'],
            ];
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $resultat['recipe']->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.recipes.generator-modal');
    }
}
