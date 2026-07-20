<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * M6-06 / D-6 §4.3 + N3: ✨ VK-Generator v1 — der Pain-Point: Basisrezept→VK
 * automatisiert. D-5-Achsen + VK-Achsen Anlass/Serviceform/Kompositions-Stil
 * (Stil filtert den GL-13-Pairing-Block, Achse 10); Bestand-Hybrid-Resolver
 * mit Basisrezepten ZUERST; Accept setzt is_sales_recipe=true + Klasse/AK
 * aus dem Vorschlag (validiert, Lineage ki). Aus-Foto/PDF blockiert (Martin-
 * Vision-Frage). Bio-Default konventionell — Bio nie zufällig.
 *
 * Harmonisierung 2026-07-20 (#512, Dominique-Fund „hier sind sogar noch
 * Freitexte"): Niveau/Sektor/Diät/Convenience/Bio auf dieselbe strukturierte
 * Eingabe wie der Basisrezept-Generator gebracht — Pills statt Freitext/Selects,
 * diaet_hart MULTI (hart erzwungen) statt Einzel-String, bio dreiwertig statt
 * bool. Freitext blieb nur bei Aroma (legitim frei). Werte fließen als Prompt-
 * Kontext (nur convenience/frische/bio/kompositions_stil sind harte Resolver-Hooks).
 */
class VkGeneratorModal extends Component
{
    public string $description = '';

    public array $parameter = [
        'convenience' => '', 'frische' => 'frisch', 'bio_praeferenz' => 'konventionell',
        'level' => '', 'sektor' => '', 'diaet_hart' => [], 'aroma' => '',
        'occasion' => '', 'serviceform' => '', 'kompositions_stil' => '',
    ];

    /**
     * Pill-Gruppen (Parität zum Basisrezept-Generator, GeneratorModal::RICHTUNGEN):
     * '' = (egal); diaet_hart ist MULTI und wird separat im Blade gerendert;
     * bio_praeferenz dreiwertig statt bool. Anlass/Serviceform/Kompositions-Stil
     * bleiben Selects (VK-eigene Achsen).
     */
    public const RICHTUNGEN = [
        ['field' => 'convenience', 'label' => 'Convenience (Eigenleistung)', 'optionen' => ['' => '(egal)', 'from_scratch' => 'From Scratch', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Standard-Eigenleistung', 'from_scratch' => 'alles selbst — Pool dreht auf Roh/Sub-Rezepte', 'teil_convenience' => 'Halbfabrikate erlaubt', 'voll_convenience' => 'Fertigprodukte bevorzugt']],
        ['field' => 'level', 'label' => 'Niveau', 'optionen' => ['' => '(egal)', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Keine Vorgabe']],
        ['field' => 'frische', 'label' => 'Frische-Hook', 'optionen' => ['frisch' => 'Frisch', 'tk' => 'Alles aus TK', 'konserve' => 'Konserve/haltbar'], 'hint' => ['frisch' => 'fresh_first (Default)']],
        ['field' => 'bio_praeferenz', 'label' => 'Bio-Präferenz', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Egal'], 'hint' => ['konventionell' => 'Standard — kein Bio erzwungen (Default)', 'bio' => 'Bio bevorzugt (nur auf Ansage)', 'egal' => 'keine Präferenz']],
    ];

    /** 06·H4: opt-in Favoriten-Modus (Default aus → keine Versteifung). */
    public bool $useFavoritesList = false;

    /** 06·H4b: Favoriten-Block auf Convenience-getaggte verengen (nur bei aktivem Favoriten-Modus). */
    public bool $favoritesConvenienceOnly = false;

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

    #[On('vk-generator-modal.oeffnen')]
    public function oeffnen(): void
    {
        $this->reset('fehler', 'ergebnis', 'description');
        $this->dispatch('modal.open', name: 'vk-generator-modal');
    }

    public function generieren(RecipeGeneratorService $generator): void
    {
        $this->fehler = null;
        $this->ergebnis = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->description) === '') {
            $this->fehler = 'Beschreibung ist Pflicht.';

            return;
        }

        try {
            // Hook-Mapping wie im Basisrezept-Generator: der Service kennt bio als
            // bool (4.4r) — die dreiwertige Präferenz geht zusätzlich als Prompt-
            // Kontext mit (egal ≠ bio erzwingen).
            $parameter = $this->parameter;
            $parameter['bio'] = $parameter['bio_praeferenz'] === 'bio';
            // leere String-Hints strippen (diaet_hart-Array + bool bleiben erhalten)
            $parameter = array_filter($parameter, fn ($v) => $v !== '' && $v !== null);
            $parameter['use_favorites_list'] = $this->useFavoritesList; // 06·H4 opt-in (nach array_filter, sonst würde false gestrippt)
            $parameter['favorites_convenience_only'] = $this->useFavoritesList && $this->favoritesConvenienceOnly; // H4b
            $resultat = $generator->generiere($team, trim($this->description), $parameter, null, vkModus: true);
            $this->ergebnis = [
                'recipe_id' => $resultat['recipe']->id,
                'name' => $resultat['recipe']->name,
                'statistik' => $resultat['statistik'],
                'offene' => $resultat['offene'],
            ];
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('vk-recipe-selected', id: $resultat['recipe']->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.verkauf.vk-generator-modal');
    }
}
