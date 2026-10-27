<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;
use Platform\FoodAlchemist\Services\Marken\MarkenUebersichtService;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Support\FaShell;

/**
 * „Lieferanten → Marken“ (Dominique 27.10.2026): alle Einträge aus supplier_marken auf einer Seite — Marke, Kürzel, Geltung, Position, Typ, Vorrang, GPs mit
 * der Marke in der Klammer, Artikel (auf Knopfdruck gezählt). Lesen für alle Teams (globale Stammdaten ohne Preise), Ändern nur Plattform-Admin/Master-Admin
 * über MarkenPflegeService. Nach dem Setzen eines Kürzels: Angebot, die passenden Marke-offen-GPs neu zu benennen (streng wie beim Zuordnen).
 */
class Marken extends Component
{
    #[Url(as: 'lieferant')]
    public ?int $lieferant = null;

    #[Url(as: 'typ')]
    public string $typ = '';

    #[Url(as: 'global')]
    public bool $nurGlobal = false;

    public string $suche = '';

    public int $seite = 1;

    /** @var array<int, int> Eintrag-ID → gezählte Artikel (auf Knopfdruck, nur aktuelle Seite) */
    public array $artikelZahlen = [];

    public ?int $bearbeitenId = null;

    public array $editForm = [];

    public bool $neuOffen = false;

    public array $neuForm = ['supplier_id' => '', 'kuerzel' => '', 'marke' => '', 'typ' => FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE, 'position' => 'anfang', 'vorrang' => 0];

    /** Nach dem Setzen: Kürzel, für das „passende Marke-offen-GPs neu benennen“ angeboten wird, samt Vorschau */
    public array $nachrendern = [];

    /** Ansicht „bei mehreren Lieferanten gleich angelegt“ und die Vorschau des Zusammenführens (je Schlüssel) */
    public bool $dublettenAnsicht = false;

    public array $zusammenVorschau = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function updated(string $feld): void
    {
        if (in_array($feld, ['lieferant', 'typ', 'nurGlobal', 'suche'], true)) {
            $this->seite = 1;
            $this->artikelZahlen = [];
        }
    }

    public function blaettern(int $richtung): void
    {
        $this->seite = max(1, $this->seite + $richtung);
        $this->artikelZahlen = [];
    }

    public function zaehlen(): void
    {
        $dienst = app(MarkenUebersichtService::class);
        foreach ($this->liste()['zeilen'] as $z) {
            $this->artikelZahlen[$z['id']] = $dienst->artikelZahl($z['id']);
        }
    }

    public function bearbeiten(int $id): void
    {
        $this->darf();
        $e = FoodAlchemistSupplierMarke::query()->findOrFail($id);
        $this->bearbeitenId = $id;
        $this->editForm = ['marke' => $e->marke, 'typ' => $e->typ, 'position' => $e->position, 'vorrang' => (int) $e->vorrang];
        $this->fehler = null;
    }

    public function abbrechen(): void
    {
        $this->bearbeitenId = null;
        $this->neuOffen = false;
        $this->fehler = null;
    }

    public function speichern(): void
    {
        $this->darf();
        $e = FoodAlchemistSupplierMarke::query()->findOrFail((int) $this->bearbeitenId);
        $this->schreibe(fn (MarkenPflegeService $p) => $p->kuerzelSetzen(Auth::user(), $e->supplier_id, (string) $e->kuerzel,
            $e->typ === FoodAlchemistSupplierMarke::TYP_SPERRE && $this->editForm['typ'] === FoodAlchemistSupplierMarke::TYP_SPERRE ? MarkenPflegeService::SPERR_MARKE : (string) $this->editForm['marke'],
            (string) $this->editForm['typ'], (string) $this->editForm['position'], (int) $this->editForm['vorrang']));
        if ($this->fehler === null) {
            $this->bearbeitenId = null;
        }
    }

    public function loeschen(int $id): void
    {
        $this->darf();
        $e = FoodAlchemistSupplierMarke::query()->findOrFail($id);
        try {
            app(MarkenPflegeService::class)->kuerzelEntfernen(Auth::user(), $e->supplier_id, (string) $e->kuerzel);
            $this->meldung = 'Eintrag „' . $e->kuerzel . '“ entfernt.';
            $this->fehler = null;
        } catch (FaRechtFehltException|\RuntimeException $ex) {
            $this->fehler = $ex->getMessage();
        }
    }

    public function neuSpeichern(): void
    {
        $this->darf();
        $sid = $this->neuForm['supplier_id'] !== '' && $this->neuForm['supplier_id'] !== null ? (int) $this->neuForm['supplier_id'] : null;
        $this->schreibe(fn (MarkenPflegeService $p) => $p->kuerzelSetzen(Auth::user(), $sid, (string) $this->neuForm['kuerzel'], (string) $this->neuForm['marke'],
            (string) $this->neuForm['typ'], (string) $this->neuForm['position'], (int) $this->neuForm['vorrang']));
        if ($this->fehler === null) {
            $this->neuOffen = false;
            $this->neuForm = ['supplier_id' => '', 'kuerzel' => '', 'marke' => '', 'typ' => FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE, 'position' => 'anfang', 'vorrang' => 0];
        }
    }

    /** Vorschau „passende Marke-offen-GPs neu benennen“ für das zuletzt gesetzte Kürzel (Trockenlauf, streng). */
    public function nachrendernVorschau(): void
    {
        $this->darf();
        $n = $this->nachrendern;
        $this->nachrendern['ergebnis'] = app(MarkeOffenService::class)->nachrendern(Auth::user(), $n['supplier_id'], $n['kuerzel'], $n['position'], true);
    }

    public function nachrendernAnwenden(): void
    {
        $this->darf();
        $n = $this->nachrendern;
        $r = app(MarkeOffenService::class)->nachrendern(Auth::user(), $n['supplier_id'], $n['kuerzel'], $n['position'], false);
        $z = $r['zaehler'];
        $this->meldung = '„' . $r['kuerzel'] . '“: ' . $z['gerendert'] . ' GP neu benannt, ' . $z['teilen'] . ' teilen, ' . $z['fall_b'] . ' Fall B (Artikel ohne Marke).';
        $this->nachrendern = [];
    }

    public function zusammenfuehrenVorschau(string $schluessel): void
    {
        $this->darf();
        $this->ausfuehren(fn () => $this->zusammenVorschau = app(MarkenUebersichtService::class)->zusammenfuehren(Auth::user(), $schluessel, true));
    }

    public function zusammenfuehren(string $schluessel): void
    {
        $this->darf();
        $this->ausfuehren(function () use ($schluessel) {
            $r = app(MarkenUebersichtService::class)->zusammenfuehren(Auth::user(), $schluessel, false);
            $z = $r['nachrendern']['zaehler'];
            $this->meldung = '„' . $r['marke'] . '“ (' . $r['kuerzel'] . ') gilt jetzt für alle Lieferanten; entfernt bei ' . implode(', ', $r['entfernt'])
                . '. Nachrendern: ' . $z['gerendert'] . ' GP neu benannt.';
            $this->zusammenVorschau = [];
        });
    }

    private function ausfuehren(callable $tu): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $tu();
        } catch (FaRechtFehltException|\RuntimeException $ex) {
            $this->fehler = $ex->getMessage();
        }
    }

    public function nachrendernVerwerfen(): void
    {
        $this->nachrendern = [];
    }

    private function schreibe(callable $tu): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $e = $tu(app(MarkenPflegeService::class));
        } catch (FaRechtFehltException|\RuntimeException $ex) {
            $this->fehler = $ex->getMessage();

            return;
        }
        $this->meldung = 'Gespeichert: „' . $e->kuerzel . '“ → ' . ($e->typ === FoodAlchemistSupplierMarke::TYP_SPERRE ? 'kein Kürzel' : $e->marke) . '.';
        // Angebot: passende Marke-offen-GPs jetzt neu benennen (nur für echte Marken)
        $this->nachrendern = $e->typ === FoodAlchemistSupplierMarke::TYP_SPERRE ? []
            : ['supplier_id' => $e->supplier_id !== null ? (int) $e->supplier_id : null, 'kuerzel' => (string) $e->kuerzel, 'position' => (string) $e->position];
    }

    private function darf(): void
    {
        abort_unless(app(MarkenPflegeService::class)->darf(Auth::user()), 403, 'Marken pflegen nur Plattform-Admin und Master-Admin.');
    }

    private function liste(): array
    {
        return app(MarkenUebersichtService::class)->liste(['lieferant' => $this->lieferant, 'typ' => $this->typ, 'nur_global' => $this->nurGlobal, 'suche' => $this->suche], $this->seite);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.suppliers.marken', [
            'liste' => $this->liste(),
            'dubletten' => $this->dublettenAnsicht ? app(MarkenUebersichtService::class)->dubletten() : [],
            'lieferanten' => $team !== null ? FoodAlchemistSupplier::visibleToTeam($team)->orderBy('name')->pluck('name', 'id')->all() : [],
            'darfPflegen' => app(MarkenPflegeService::class)->darf(Auth::user()),
        ])->layout(FaShell::layout());
    }
}
