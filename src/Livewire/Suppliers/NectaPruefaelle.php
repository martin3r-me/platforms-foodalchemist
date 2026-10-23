<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Exceptions\NectaPruefallException;
use Platform\FoodAlchemist\Services\Necta\NectaPruefallService;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Necta-Prüffälle (PR 3, Kurator 18.10.): Liste mit Filtern, FA damals / FA jetzt / Necta nebeneinander, Einzel- und Sammel-Entscheidung.
 * Nur das Master-Team. Sammel-Entscheidung je Lieferant + Art mit Bestätigung (Anzahl, bei Allergenen die Liste der Lockerungen).
 */
class NectaPruefaelle extends Component
{
    public const ARTEN = [
        'allergen_aenderung' => 'Allergen lockerer',
        'deklaration_aenderung' => 'Deklaration lockerer',
        'produktpass_abweichung' => 'Produktpass-Abweichung',
        'manuell_gepflegt' => 'Von Hand gepflegt',
        'eingestellt_widerspruch' => 'Eingestellt-Widerspruch',
        'in_necta_entfernt' => 'In Necta entfernt',
    ];

    public const AKTION_LABEL = [
        'necta_uebernehmen' => 'Necta übernehmen', 'fa_behalten' => 'FA behalten',
        'eingestellt_bestaetigen' => 'Als eingestellt bestätigen', 'aktiv_lassen' => 'Aktiv lassen',
    ];

    #[Url(as: 'art')]
    public string $art = '';

    #[Url(as: 'lieferant')]
    public ?int $lieferant = null;

    #[Url(as: 'status')]
    public string $status = 'offen';

    public string $suche = '';

    public ?int $normalpreisTage = null;

    public int $seite = 1;

    public ?string $fehler = null;

    public ?string $meldung = null;

    /** Sammel-Bestätigung: Aktion + Vorschau (Anzahl, Allergen-Liste, Schlüssel) */
    public ?string $sammelAktion = null;

    public array $sammelVorschau = [];

    /** Laufender Hintergrund-Lauf einer Sammel-Entscheidung (Stand per wire:poll) */
    public ?string $sammelLauf = null;

    public function mount(): void
    {
        abort_unless(MasterTeam::istMaster($this->team()), 403, 'Necta-Prüffälle entscheidet das Master-Team.');
    }

    public function updated(string $feld): void
    {
        if (in_array($feld, ['art', 'lieferant', 'status', 'suche', 'normalpreisTage'], true)) {
            $this->seite = 1;
            $this->reset('sammelAktion', 'sammelVorschau');
        }
    }

    public function blaettern(int $richtung): void
    {
        $this->seite = max(1, $this->seite + $richtung);
    }

    public function entscheide(int $id, string $aktion): void
    {
        $this->reset('fehler', 'meldung');
        try {
            $r = app(NectaPruefallService::class)->entscheiden($this->team(), [$id], [], $aktion, Auth::id());
            $this->meldung = $r['uebersprungen'] !== [] ? 'Nicht entschieden: '.reset($r['uebersprungen']) : self::AKTION_LABEL[$aktion].' — erledigt.';
        } catch (FaRechtFehltException|NectaPruefallException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function sammelPruefen(string $aktion): void
    {
        $this->reset('fehler', 'meldung');
        try {
            $this->sammelVorschau = app(NectaPruefallService::class)->vorschau($this->team(), null, $this->filter(), $aktion);
            $this->sammelAktion = $aktion;
        } catch (NectaPruefallException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function sammelBestaetigen(): void
    {
        if ($this->sammelAktion === null) {
            return;
        }
        $this->reset('fehler', 'meldung');
        try {
            $r = app(NectaPruefallService::class)->entscheiden($this->team(), null, $this->filter(), $this->sammelAktion, Auth::id(),
                (string) ($this->sammelVorschau['schluessel'] ?? ''));
            if ($r['im_hintergrund'] ?? false) {
                $this->sammelLauf = $r['lauf'];
                $this->meldung = sprintf('%s: %d Prüffälle laufen im Hintergrund …', self::AKTION_LABEL[$this->sammelAktion], $r['gesamt']);
                $this->reset('sammelAktion', 'sammelVorschau');

                return;
            }
            $this->meldung = sprintf('%s: %d erledigt%s%s.', self::AKTION_LABEL[$this->sammelAktion], $r['erledigt'],
                $r['uebersprungen'] !== [] ? ', '.count($r['uebersprungen']).' übersprungen' : '',
                $r['rezepte_nachgerechnet'] > 0 ? ', '.$r['rezepte_nachgerechnet'].' Rezepte nachgerechnet' : '');
        } catch (FaRechtFehltException|NectaPruefallException $e) {
            $this->fehler = $e->getMessage();
        }
        $this->reset('sammelAktion', 'sammelVorschau');
    }

    /** wire:poll, solange ein Hintergrund-Lauf läuft: Fortschritt, am Ende die Fertig-Meldung. */
    public function sammelStand(): void
    {
        if ($this->sammelLauf === null || ($s = NectaPruefallService::sammelStand($this->sammelLauf)) === null) {
            return;
        }
        $label = self::AKTION_LABEL[$s['aktion']] ?? 'Sammel-Entscheidung';
        if (! ($s['fertig'] ?? false)) {
            $this->meldung = sprintf('%s: %d von %d entschieden …', $label, $s['erledigt'] + $s['uebersprungen'], $s['gesamt']);

            return;
        }
        $this->sammelLauf = null;
        $this->meldung = sprintf('%s fertig: %d erledigt%s%s.', $label, $s['erledigt'],
            $s['uebersprungen'] > 0 ? ', '.$s['uebersprungen'].' übersprungen' : '', $s['rezepte_nachgerechnet'] > 0 ? ', '.$s['rezepte_nachgerechnet'].' Rezepte nachgerechnet' : '');
        if (isset($s['fehler'])) {
            $this->fehler = 'Abgebrochen: '.$s['fehler'];
        }
    }

    public function sammelAbbrechen(): void
    {
        $this->reset('sammelAktion', 'sammelVorschau');
    }

    /** @return array<string, mixed> */
    /** Dominique 23.10.: nach dem Speichern im Artikel-Modal die Liste neu zeichnen („FA jetzt“), Filter und Seite bleiben. */
    #[On('item-gespeichert')]
    public function artikelGespeichert(): void {}

    private function filter(): array
    {
        return array_filter([
            'art' => $this->art ?: null, 'lieferant' => $this->lieferant ?: null, 'status' => $this->status ?: 'offen',
            'suche' => trim($this->suche) ?: null,
            'normalpreis_tage' => $this->art === 'eingestellt_widerspruch' && $this->normalpreisTage ? $this->normalpreisTage : null,
        ], fn ($v) => $v !== null);
    }

    public function render()
    {
        $svc = app(NectaPruefallService::class);
        $team = $this->team();
        $liste = $svc->liste($team, $this->filter(), $this->seite, 50);

        return view('foodalchemist::livewire.suppliers.necta-pruefaelle', [
            'liste' => $liste,
            'zaehler' => $svc->zaehler($team, $this->filter()),
            'arten' => self::ARTEN,
            'aktionLabel' => self::AKTION_LABEL,
            // Kurator 19.10.: Art genügt (z. B. „Deklaration lockerer“ über alle Lieferanten); Lieferant/Suche schränken optional ein
            'sammelErlaubt' => $this->art !== '' && $this->status === 'offen' && $this->sammelLauf === null,
        ])->layout(FaShell::layout());
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
