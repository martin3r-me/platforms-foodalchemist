<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec-42-Vollzug S3a — Buch-Ebenen-Planung eines Foodbooks IN der Leitstelle. Migriert die Planungs-
 * Inputs aus dem Foodbook-Kontext-Tab: Leitplanken (Schreibstil/Kundentyp/Niveau), Briefing/Einleitung
 * und Leitidee-Canvas. Setter (`FoodbookService::update`) + Canvas (`ManagesCanvas`) sind team-generisch
 * und owner-adressiert — hier nur die dünnen Wrapper (owner_type=foodbook, owner_id=foodbookId).
 *
 * Der KI-Kundentext-Vorschlag (kiEinleitung) bleibt vorerst im Foodbook-Modul; er ist ein gekoppelter
 * 6-Methoden-Flow (kiText*), der sauber als eigener Concern extrahiert werden sollte (eigener Nachzug).
 */
class FoodbookKontextRail extends Component
{
    use ManagesCanvas;
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public int $foodbookId;

    /** Briefing/Einleitung (Kundentext) — blur-persistiert. */
    public string $beschreibung = '';

    /** KI-Kundentext-Vorschau (noch nicht übernommen) — schreibt nie direkt ins Feld. */
    public ?string $kiVorschau = null;

    public ?float $kiConfidence = null;

    public ?string $kiHinweis = null;

    public function mount(): void
    {
        $this->canvasInit('foodbook', 'foodbook', $this->foodbookId);
        $this->beschreibung = (string) ($this->fb()?->description ?? '');
    }

    /** Schreibstil-Override (leer = Default-Kaskade Team-DNA → Kunde-DNA). */
    public function tonalitaetSetzen($styleId, FoodbookService $svc): void
    {
        $svc->update($this->team(), $this->foodbookId, [
            'writing_style_id' => ($styleId === '' || $styleId === null) ? null : (int) $styleId,
        ]);
        $this->savedToast('Schreibstil gesetzt');
    }

    /** Eine kreative Leitplanke (kundentyp | default_niveau | default_convenience); leer = erben. */
    public function leitplankeSetzen(string $feld, $wert, FoodbookService $svc): void
    {
        if (! in_array($feld, ['kundentyp', 'default_niveau', 'default_convenience'], true)) {
            return;
        }
        $svc->update($this->team(), $this->foodbookId, [
            $feld => ($wert === '' || $wert === null) ? null : (string) $wert,
        ]);
        $this->savedToast('Leitplanke gesetzt');
    }

    /** Briefing/Einleitung persistieren (feuert beim blur-sync von `beschreibung`; Lifecycle-Hook → keine DI). */
    public function updatedBeschreibung(): void
    {
        app(FoodbookService::class)->update($this->team(), $this->foodbookId, [
            'description' => trim($this->beschreibung) !== '' ? $this->beschreibung : null,
        ]);
    }

    /**
     * KI-Kundentext-Vorschlag holen (Spec 03 · L2) — landet NUR in der Vorschau, nie direkt im Feld.
     * Typisierte KI-Ausfälle werden zu genau einer Hinweis-Zeile (wie im Foodbook-Modul).
     */
    public function kiEinleitung(FoodbookService $svc): void
    {
        $this->kiVorschau = null;
        $this->kiConfidence = null;
        $this->kiHinweis = null;
        try {
            $r = $svc->kiKundentextVorschlag($this->team(), $this->foodbookId);
            $this->kiVorschau = $r['text'];
            $this->kiConfidence = $r['confidence'] ?? null;
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            $this->kiHinweis = 'KI ist für dieses Team deaktiviert (Einstellungen → Food DNA / KI).';
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException $e) {
            $this->kiHinweis = 'Kein KI-Provider gebunden — der Kundentext braucht ein aktives Modell (demo).';
        } catch (\RuntimeException $e) {
            $this->kiHinweis = $e->getMessage();
        }
    }

    /** Vorschlag übernehmen: ins Feld + persistieren, Vorschau leeren. */
    public function kiUebernehmen(FoodbookService $svc): void
    {
        if ($this->kiVorschau === null) {
            return;
        }
        $this->beschreibung = $this->kiVorschau;
        $svc->update($this->team(), $this->foodbookId, ['description' => $this->beschreibung]);
        $this->kiVerwerfen();
    }

    public function kiVerwerfen(): void
    {
        $this->kiVorschau = null;
        $this->kiConfidence = null;
        $this->kiHinweis = null;
    }

    public function render()
    {
        return view('foodalchemist::livewire.planung.foodbook-kontext-rail', [
            'fb' => $this->fb(),
            'schreibstile' => $this->canvasSchreibstile(),
            'kundentypen' => TeamSettingsService::KUNDENTYPEN,
            'niveauLabels' => TeamSettingsService::NIVEAU_LABEL,
        ]);
    }

    private function fb(): ?FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::visibleToTeam($this->team())->find($this->foodbookId);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
