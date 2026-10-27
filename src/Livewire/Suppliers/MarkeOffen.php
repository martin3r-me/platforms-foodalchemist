<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Marken-Nachlauf (Plan GP-Stamm 3g, Dominique 26.10.2026): Rezeptur-GPs ohne Marke, gruppiert nach unbekannten Kürzeln aus den Artikelbezeichnungen.
 * „Kürzel zuordnen“ (Plattform-Admin/Master-Admin) legt den Eintrag an und rendert die passenden GPs neu — nur bei genau einer Marke über alle Artikel.
 * Nur das Master-Team (die GPs sind global), wie die Necta-Prüffälle.
 */
class MarkeOffen extends Component
{
    #[Url(as: 'lieferant')]
    public ?int $lieferant = null;

    public string $suche = '';

    /** Offenes Zuordnen-Formular: Gruppen-Schlüssel + Eingaben */
    public ?string $offen = null;

    public array $form = ['marke' => '', 'typ' => FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE, 'geltung' => 'lieferant', 'vorrang' => 0];

    /** Ergebnis des letzten Zuordnens bzw. der Vorschau */
    public array $ergebnis = [];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        abort_unless(MasterTeam::istMaster(Auth::user()?->currentTeamRelation), 403, 'Den Marken-Nachlauf bearbeitet das Master-Team.');
    }

    /** Gruppe des offenen Formulars (bleibt sichtbar, auch wenn sie nach dem Zuordnen aus der Liste fällt — die Meldung steht im Formular). */
    public array $offenGruppe = [];

    public function oeffnen(string $schluessel): void
    {
        $this->offen = $schluessel;
        $this->offenGruppe = collect(app(MarkeOffenService::class)->liste()['gruppen'])->firstWhere('schluessel', $schluessel) ?? [];
        // Standards (Dominique 27.10.): gilt für diesen Lieferanten; Händler → Eigenmarke, sonst Herstellermarke
        $typ = ($this->offenGruppe['rolle'] ?? null) === 'haendler' ? FoodAlchemistSupplierMarke::TYP_EIGENMARKE : FoodAlchemistSupplierMarke::TYP_HERSTELLERMARKE;
        $this->form = ['marke' => '', 'typ' => $typ, 'geltung' => ($this->offenGruppe['supplier_id'] ?? null) !== null ? 'lieferant' : 'alle', 'vorrang' => 0];
        $this->ergebnis = [];
        $this->fehler = null;
        $this->meldung = null;
    }

    public function schliessen(): void
    {
        $this->offen = null;
        $this->offenGruppe = [];
        $this->ergebnis = [];
        $this->fehler = null;
        $this->meldung = null;
    }

    /** „Kein Kürzel“ (Dominique 27.10.): das Wort ist bei diesem Lieferanten an dieser Position nie eine Marke — verschwindet dauerhaft aus der Liste. */
    public function keinKuerzel(string $schluessel): void
    {
        abort_unless(app(MarkenPflegeService::class)->darf(Auth::user()), 403, 'Marken-Kürzel sperren nur Plattform-Admin und Master-Admin.');
        $this->fehler = null;
        $this->meldung = null;
        [$supplierId, $position, $kuerzel] = $this->teile($schluessel);
        try {
            $r = app(MarkeOffenService::class)->sperren(Auth::user(), $supplierId, $kuerzel, $position);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->meldung = '„' . $r['kuerzel'] . '“ ist als „Kein Kürzel“ gesperrt (' . $r['gps'] . ' GP). Rückgängig im Lieferanten unter „Marke & Rolle“.';
        if ($this->offen === $schluessel) {
            $this->offen = null;
        }
    }

    public function vorschau(): void
    {
        $this->ausfuehren(true);
    }

    public function zuordnen(): void
    {
        $this->ausfuehren(false);
    }

    private function ausfuehren(bool $trockenlauf): void
    {
        abort_unless(app(MarkenPflegeService::class)->darf(Auth::user()), 403, 'Marken-Kürzel zuordnen nur Plattform-Admin und Master-Admin.');
        $this->fehler = null;
        $this->meldung = null;
        [$supplierId, $position, $kuerzel] = $this->teile((string) $this->offen);
        if (trim((string) $this->form['marke']) === '') {
            $this->fehler = 'Bitte die Marke eintragen, wie sie in der Klammer stehen soll.';

            return;
        }
        try {
            $this->ergebnis = app(MarkeOffenService::class)->zuordnen(Auth::user(), $this->form['geltung'] === 'alle' ? null : $supplierId, $kuerzel,
                (string) $this->form['marke'], (string) $this->form['typ'], $position, (int) $this->form['vorrang'], $trockenlauf);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if (! $trockenlauf) {
            $z = $this->ergebnis['zaehler'];
            $this->meldung = '„' . $this->ergebnis['kuerzel'] . '“ → ' . $this->ergebnis['marke'] . ': ' . $z['gerendert'] . ' GP neu benannt, '
                . $z['teilen'] . ' teilen, ' . $z['fall_b'] . ' Fall B (Artikel ohne Marke).';
            // Formular bleibt offen: die Meldung steht dort, wo geklickt wurde (Dominique 27.10.), auch wenn die Gruppe jetzt aus der Liste fällt
        }
    }

    /** @return array{0: ?int, 1: string, 2: string} */
    private function teile(string $schluessel): array
    {
        [$lieferant, $position, $kuerzel] = array_pad(explode('|', $schluessel, 3), 3, '');

        return [$lieferant === 'alle' ? null : (int) $lieferant, $position, $kuerzel];
    }

    public function render()
    {
        $liste = app(MarkeOffenService::class)->liste();
        $gruppen = $this->lieferant !== null ? array_values(array_filter($liste['gruppen'], fn ($g) => $g['supplier_id'] === $this->lieferant)) : $liste['gruppen'];
        if (($s = mb_strtolower(trim($this->suche))) !== '') {
            $gruppen = array_values(array_filter($gruppen, fn ($g) => str_contains(mb_strtolower($g['kuerzel'] . ' ' . $g['lieferant'] . ' ' . implode(' ', $g['beispiele'])), $s)));
        }
        if ($this->offen !== null && $this->offenGruppe !== [] && ! collect($gruppen)->contains('schluessel', $this->offen)) {
            array_unshift($gruppen, $this->offenGruppe + ['erledigt' => true]);   // nach dem Zuordnen: Zeile mit Formular und Meldung oben halten
        }
        $lieferanten = collect($liste['gruppen'])->groupBy('supplier_id')
            ->map(fn ($g) => ['id' => $g->first()['supplier_id'], 'name' => $g->first()['lieferant'], 'n' => $g->count()])->sortBy('name')->values()->all();

        return view('foodalchemist::livewire.suppliers.marke-offen', [
            'liste' => $liste,
            'gruppen' => $gruppen,
            'lieferanten' => $lieferanten,
            'offenOhneRolle' => app(MarkeOffenService::class)->lieferantenOffenMitRezepturware(),
            'darfZuordnen' => app(MarkenPflegeService::class)->darf(Auth::user()),
        ])->layout(FaShell::layout());
    }
}
