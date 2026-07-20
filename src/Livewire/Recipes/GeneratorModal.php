<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;

/**
 * M4-14: ✨ Basisrezept-Generator — Beschreibung + Richtungs-Parameter
 * (Convenience/Frische/Bio/Niveau/Sektor/Diät-hart), Bestand-Hybrid-Resolver.
 * Aus-Foto/PDF blockiert auf die Martin-Vision-Frage (Hinweis im Modal).
 */
class GeneratorModal extends Component
{
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

    /** Pill-Gruppen fürs View (NICHT als @php-Block — Blade-Raw-Block-Falle mit @php(...)-Einzeilern). */
    public const RICHTUNGEN = [
        ['field' => 'convenience', 'label' => 'Convenience (Eigenleistung)', 'optionen' => ['' => '(egal)', 'from_scratch' => 'From Scratch', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'], 'hint' => ['' => 'Keine Vorgabe', 'from_scratch' => 'alles selbst — Pool dreht auf Roh/Sub-Rezepte', 'teil_convenience' => 'Halbfabrikate erlaubt', 'voll_convenience' => 'Fertigprodukte bevorzugt']],
        ['field' => 'level', 'label' => 'Niveau', 'optionen' => ['' => '(egal)', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'], 'hint' => ['' => 'Keine Vorgabe']],
        ['field' => 'bestand', 'label' => 'Bestand-Nutzung', 'optionen' => ['hybrid' => 'Hybrid', 'nur_bestand' => 'Nur Bestand', 'komplett_neu' => 'Komplett neu'], 'hint' => ['hybrid' => 'Default — Bestand zuerst reusen, Neues nur für echte Lücken (agentischer Resolver entscheidet)', 'nur_bestand' => 'ausschließlich vorhandene GPs/Rezepte', 'komplett_neu' => 'Bestand ignorieren']],
        ['field' => 'bio_praeferenz', 'label' => 'Bio-Präferenz', 'optionen' => ['konventionell' => 'Konventionell', 'bio' => 'Bio', 'egal' => 'Egal'], 'hint' => ['konventionell' => 'Standard — konventionelle Ware, KEIN Bio in den Treffern (Default)', 'bio' => 'Bio bevorzugt (4.4r: nur auf Ansage)', 'egal' => 'keine Präferenz']],
        ['field' => 'frische', 'label' => 'Frische-Hook', 'optionen' => ['frisch' => 'Frisch', 'tk' => 'Alles aus TK', 'konserve' => 'Konserve/haltbar'], 'hint' => ['frisch' => 'fresh_first (Default)']],
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

    /** Async (2026-07-20): läuft während der Queue-Job rechnet; UI pollt über die Run-ID. */
    public bool $laeuft = false;

    public ?string $runId = null;

    #[On('generator-modal.oeffnen')]
    public function oeffnen(): void
    {
        $this->reset('fehler', 'ergebnis', 'description', 'laeuft', 'runId');
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

        $this->runId = (string) Str::uuid();
        Cache::put(GenerateRecipeJob::cacheKey($this->runId), ['status' => 'pending'], now()->addMinutes(15));
        GenerateRecipeJob::dispatch($this->runId, $team->id, (int) Auth::id(), trim($this->description), $parameter, false);
        $this->laeuft = true;
    }

    /** Poll-Ziel (wire:poll während $laeuft): liest den Job-Ausgang aus dem Cache. */
    public function pruefeErgebnis(): void
    {
        if ($this->runId === null) {
            return;
        }
        $stand = Cache::get(GenerateRecipeJob::cacheKey($this->runId));
        if (! is_array($stand) || ($stand['status'] ?? null) === 'pending') {
            return;   // noch am Rechnen → weiter pollen
        }

        $this->laeuft = false;
        if (($stand['status'] ?? null) === 'error') {
            $this->fehler = $stand['fehler'] ?? 'Generierung fehlgeschlagen.';

            return;
        }

        $this->ergebnis = [
            'recipe_id' => $stand['recipe_id'],
            'name' => $stand['name'],
            'statistik' => $stand['statistik'],
            'offene' => $stand['offene'],
        ];
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('recipe-selected', id: $stand['recipe_id']);
    }

    public function render()
    {
        return view('foodalchemist::livewire.recipes.generator-modal');
    }
}
