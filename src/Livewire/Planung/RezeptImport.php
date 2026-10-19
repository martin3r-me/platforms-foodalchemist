<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Planungs-Leitstelle · Reiter „Import“ (Spec 85 C1 PR-1, reine Verschiebung aus {@see Index}).
 *
 * Bestehende Rezeptur (Rohtext, Web-Kopie, PDF, Scan, Foto) TREU extrahieren → editierbare Vorschau →
 * GEERDET anlegen; danach übernimmt der Worker die Anreicherung. Der Parent bleibt Herr über Session und
 * Lauf: `sessionId` kommt reaktiv herein, nach der Anlage meldet das Kind `planung.import-angelegt`, der
 * Parent lädt daraufhin den neuen Lauf. `recipe-gespeichert` geht wie bisher an alle.
 */
class RezeptImport extends Component
{
    use WithFileUploads;

    /** Aktive Planungs-Session des Parents (reaktiv: wechselt der Parent die Session, zieht das Kind mit). */
    #[Reactive]
    public ?int $sessionId = null;

    /** Freitext-/Web-Copy-Eingabe. */
    public string $importText = '';
    /** Optionaler Upload: Text-PDF, Scan-PDF oder Foto (WithFileUploads). */
    public $importPdf = null;
    /** Woher der Extrakt kam: text | pdf | scan | bild — Vorschau warnt bei scan/bild (Mengen prüfen). */
    public string $importQuelle = 'text';
    /** Ziel-Typ (aus dem Extrakt vorgeschlagen, per Toggle überschreibbar). */
    public string $importTyp = 'basisrezept';
    /** Schritt: eingabe → vorschau → fertig. */
    public string $importStep = 'eingabe';
    /** Editierbare Extrakt-Vorschau {typ,name,zutaten[],preparation,komponenten[]}. */
    public ?array $importVorschau = null;
    /** Ergebnis nach Anlage {recipe_id,name,offen,sub_recipes}. */
    public ?array $importErgebnis = null;
    /** Fehler-/Statusmeldung im Import-Tab. */
    public string $importMeldung = '';

    /**
     * Schritt 1: Rohtext (Textarea oder Text-PDF) TREU extrahieren → editierbare Vorschau.
     * Bewusst synchron (EIN LLM-Call; das teure Erzeugen läuft weiterhin async über die Kaskade).
     */
    public function importExtrahieren(\Platform\FoodAlchemist\Services\RecipeExtractService $svc): void
    {
        $this->importMeldung = '';
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            // Foto/Scan (Dominique 2026-10-12): JPG/PNG/WebP gehen als Bild an die KI, PDFs zuerst als Text,
            // bei Scans als Datei — s. RecipeExtractService::ausBild/ausPdf. Text-Weg unverändert.
            if ($this->importPdf !== null) {
                $this->validate(['importPdf' => 'file|mimes:pdf,jpg,jpeg,png,webp,gif|max:20480']);
                $mime = (string) $this->importPdf->getMimeType();
                if (str_starts_with($mime, 'image/')) {
                    $this->importQuelle = 'bild';
                    $extrakt = $svc->ausBild($team, $this->importPdf->getRealPath(), $mime, trim($this->importText) ?: null);
                } else {
                    $ergebnis = $svc->ausPdf($team, $this->importPdf->getRealPath(), (string) $this->importPdf->getClientOriginalName());
                    $this->importQuelle = $ergebnis['quelle'];
                    $extrakt = $ergebnis['extrakt'];
                }
            } else {
                $rohText = trim($this->importText);
                if ($rohText === '') {
                    $this->importMeldung = 'Bitte Text einfügen oder ein PDF bzw. Foto hochladen.';

                    return;
                }
                $this->importQuelle = 'text';
                $extrakt = $svc->extrahiere($team, $rohText);
            }
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
            $this->importMeldung = 'KI ist für dieses Team deaktiviert — die Extraktion braucht sie.';

            return;
        } catch (\Throwable $e) {
            $this->importMeldung = $e->getMessage();

            return;
        }
        // #6 (Dominique 2026-08-28): die EXPLIZITE Vorauswahl des Users („Anlegen als …") gewinnt —
        // der Extrakt-Typ überschreibt sie NICHT mehr (früher sprang Basisrezept → Gericht). Im Vorschau-
        // Dropdown bleibt der Typ frei änderbar. Der KI-Vorschlag zählt nur, wenn der User nichts gewählt hat.
        if (! in_array($this->importTyp, ['basisrezept', 'gericht'], true)) {
            $this->importTyp = ($extrakt['typ'] ?? null) === 'gericht' ? 'gericht' : 'basisrezept';
        }
        $this->importVorschau = [
            'name' => (string) ($extrakt['name'] ?? ''),
            'preparation' => (string) ($extrakt['preparation'] ?? ''),
            'zutaten' => array_values(array_map(fn ($z) => [
                'text' => (string) ($z['text'] ?? $z['name'] ?? ''),
                'quantity' => (string) ($z['quantity'] ?? ''),
                'unit' => (string) ($z['unit'] ?? ''),
                'sub_rezept' => (bool) ($z['sub_rezept'] ?? false),
            ], array_values(array_filter((array) ($extrakt['zutaten'] ?? []), 'is_array')))),
            'komponenten' => array_values(array_map(fn ($k) => [
                'name' => (string) ($k['name'] ?? ''),
                'preparation' => (string) ($k['preparation'] ?? ''),
                'zutaten' => array_values(array_map(fn ($z) => [
                    'text' => (string) ($z['text'] ?? $z['name'] ?? ''),
                    'quantity' => (string) ($z['quantity'] ?? ''),
                    'unit' => (string) ($z['unit'] ?? ''),
                ], array_values(array_filter((array) ($k['zutaten'] ?? []), 'is_array')))),
            ], array_values(array_filter((array) ($extrakt['komponenten'] ?? []), 'is_array')))),
        ];
        $this->importStep = 'vorschau';
    }

    /** Schritt 2: die (evtl. editierte) Vorschau GEERDET anlegen (rekursiv, Sub-Rezepte verknüpft). */
    public function importAnlegen(\Platform\FoodAlchemist\Services\RecipeExtractService $svc): void
    {
        $this->importMeldung = '';
        $team = $this->team();
        if ($team === null || $this->importVorschau === null) {
            return;
        }
        // #6 (Dominique 2026-08-28): Quelltext ohne Titel → die Extraktion liefert (regelkonform:
        // „nichts erfinden") einen leeren Namen. Statt still ein namenloses Rezept anzulegen ("nicht
        // erfasst") hier ehrlich nachfassen — der Name ist oben im Vorschau-Feld editierbar.
        if (trim((string) ($this->importVorschau['name'] ?? '')) === '') {
            $this->importMeldung = 'Bitte oben einen Rezept-Namen vergeben — die Quelle enthielt keinen Titel (es wird nichts erfunden).';

            return;
        }
        $extrakt = [
            'typ' => $this->importTyp,
            'name' => (string) ($this->importVorschau['name'] ?? ''),
            'preparation' => (string) ($this->importVorschau['preparation'] ?? ''),
            'zutaten' => $this->importZutatenRein($this->importVorschau['zutaten'] ?? []),
            'komponenten' => array_values(array_map(fn ($k) => [
                'name' => (string) ($k['name'] ?? ''),
                'preparation' => (string) ($k['preparation'] ?? ''),
                'zutaten' => $this->importZutatenRein($k['zutaten'] ?? []),
            ], (array) ($this->importVorschau['komponenten'] ?? []))),
        ];
        try {
            $res = $svc->legeAn($team, $extrakt, $this->importTyp === 'gericht');
        } catch (\Throwable $e) {
            $this->importMeldung = $e->getMessage();

            return;
        }
        $recipe = $res['recipe'];
        $this->importErgebnis = [
            'recipe_id' => (int) $recipe->id,
            'name' => (string) $recipe->name,
            'offen' => (int) ($recipe->n_ingredients_unmapped ?? 0),
            'sub_recipes' => $res['sub_recipes'] ?? [],
        ];
        // #6/Import (Dominique 2026-08-28): NICHT mehr synchron minten — die Aufgabe an den Worker
        // übergeben. Voll-Anreicherung inkl. LA-First-GP-Mint der offenen Zutaten läuft async und wird
        // als Lauf im Worker-Tab sichtbar (deferred.enrich-Polling). Der Handoff darf den erfolgreichen
        // Import nicht kippen (fail-soft).
        try {
            app(PlanningCascadeService::class)->enrichBestehendesRezept(
                $team, (int) $recipe->id, $this->importTyp === 'gericht', $this->sessionId
            );
            if ($this->sessionId !== null) {
                $this->dispatch('planung.import-angelegt');   // Parent zieht den neuen Import-Lauf nach → Worker-Tab zeigt ihn
            }
            $this->importMeldung = '„' . $recipe->name . '" ist angelegt. Grundprodukte, Beschreibung und Pairings werden im Hintergrund ergänzt.';
        } catch (\Throwable $e) {
            $this->importMeldung = 'Angelegt, aber die Übergabe an die Hintergrund-Erstellung ist fehlgeschlagen: ' . $e->getMessage();
        }
        $this->importStep = 'fertig';
        $this->dispatch('recipe-gespeichert');
    }

    public function importReset(): void
    {
        $this->reset(['importText', 'importPdf', 'importVorschau', 'importErgebnis', 'importMeldung', 'importQuelle']);
        $this->importStep = 'eingabe';
        $this->importTyp = 'basisrezept';
    }

    /** Rohe Vorschau-Zeilen → saubere Zutaten-Struktur (leere raus; Menge nur wenn numerisch). */
    private function importZutatenRein(array $rows): array
    {
        $out = [];
        foreach ($rows as $z) {
            $text = trim((string) ($z['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $zeile = ['text' => $text];
            $q = trim((string) ($z['quantity'] ?? ''));
            if ($q !== '' && is_numeric(str_replace(',', '.', $q))) {
                $zeile['quantity'] = (float) str_replace(',', '.', $q);
            }
            $u = trim((string) ($z['unit'] ?? ''));
            if ($u !== '') {
                $zeile['unit'] = $u;
            }
            if (! empty($z['sub_rezept'])) {
                $zeile['sub_rezept'] = true;
            }
            $out[] = $zeile;
        }

        return $out;
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function render()
    {
        return view('foodalchemist::livewire.planung.rezept-import');
    }
}
