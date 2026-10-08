<?php

namespace Platform\FoodAlchemist\Livewire\Wareneingang;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoiceLine;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\LieferantenRechnungService;

/**
 * Spec 75b · Reiter Rechnungen im Wareneingang. Erfassen belegt aus gebuchten, noch nicht abgerechneten
 * Lieferscheinen des Lieferanten vor (Sammelrechnung), Preise aus der Bestellung. Prüfen im Detail:
 * Befund je Position (Triple Match), Abweichung begründen, Freigeben (Rolle Freigeben), bezahlt.
 * Rechte prüft der Service; die Oberfläche blendet Knöpfe nur zusätzlich aus.
 */
class Rechnungen extends Component
{
    use WithFileUploads;

    public bool $formOffen = false;

    public ?int $formId = null;

    /** @var array{supplier_id:?int, nummer:string, datum:string, total:string, notiz:string} */
    public array $form = ['supplier_id' => null, 'nummer' => '', 'datum' => '', 'total' => '', 'notiz' => ''];

    /** @var list<array<string,mixed>> */
    public array $zeilen = [];

    /** @var list<array{art:string, designation:string, betrag:string}> */
    public array $neben = [];

    public $anhang = null;

    public ?int $fLieferant = null;

    public string $fStatus = '';

    public string $fSuche = '';

    public ?int $offenId = null;

    /** @var array<int,string> line_id => Begründung (Eingabe) */
    public array $begruendung = [];

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(): void
    {
        $this->formZuruecksetzen();
    }

    public function neu(): void
    {
        $this->formZuruecksetzen();
        $this->formOffen = true;
    }

    public function lieferantWaehlen($supplierId, LieferantenRechnungService $svc): void
    {
        if ($this->formId !== null) {
            return;
        }
        $this->form['supplier_id'] = $supplierId !== '' && $supplierId !== null ? (int) $supplierId : null;
        $this->zeilen = [];
        if ($this->form['supplier_id'] !== null) {
            foreach ($svc->vorbelegen($this->team(), $this->form['supplier_id']) as $v) {
                $this->zeilen[] = $v + ['an' => true, 'qty' => $this->zahlText($v['qty_packs']), 'preis' => $this->zahlText($v['pack_price']), 'preis_bestellt' => $v['pack_price']];
            }
        }
    }

    public function nebenHinzu(): void
    {
        $this->neben[] = ['art' => 'fracht', 'designation' => '', 'betrag' => ''];
    }

    public function nebenEntfernen(int $i): void
    {
        unset($this->neben[$i]);
        $this->neben = array_values($this->neben);
    }

    public function bearbeiten(int $id, LieferantenRechnungService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $d = $svc->detail($this->team(), $id);
            if ($d['status'] !== FoodAlchemistSupplierInvoice::STATUS_ERFASST) {
                throw new \RuntimeException('Nur Rechnungen in Prüfung lassen sich bearbeiten.');
            }
            $this->formZuruecksetzen();
            $this->formOffen = true;
            $this->formId = $id;
            $this->form = ['supplier_id' => $d['supplier_id'], 'nummer' => (string) $d['invoice_number'], 'datum' => (string) $d['invoice_date'],
                'total' => $this->zahlText($d['total_net']), 'notiz' => (string) $d['note']];
            foreach ($d['zeilen'] as $z) {
                if ($z['art'] === 'ware') {
                    $this->zeilen[] = ['delivery_note_line_id' => $z['delivery_note_line_id'], 'order_line_id' => $z['order_line_id'],
                        'lieferschein' => $z['lieferschein'], 'nummer' => $z['nummer'], 'designation' => $z['designation'],
                        'packaging_unit' => null, 'an' => true, 'qty' => $this->zahlText($z['qty_packs']), 'preis' => $this->zahlText($z['pack_price']),
                        'preis_bestellt' => $z['preis_bestellt'], 'begruendung' => $z['begruendung'], 'ohne_bestellung' => $z['order_line_id'] === null];
                } else {
                    $this->neben[] = ['art' => $z['art'], 'designation' => $z['designation'], 'betrag' => $this->zahlText(abs((float) $z['line_net']))];
                }
            }
            foreach ($svc->vorbelegen($this->team(), $d['supplier_id']) as $v) {
                $this->zeilen[] = $v + ['an' => false, 'qty' => $this->zahlText($v['qty_packs']), 'preis' => $this->zahlText($v['pack_price']), 'preis_bestellt' => $v['pack_price']];
            }
        });
    }

    public function speichern(LieferantenRechnungService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $id = $this->schreiben($svc);
            $this->formZuruecksetzen();
            $this->offenId = $id;
            $this->hinweis = 'Rechnung gespeichert — jetzt prüfen und freigeben.';
        });
    }

    public function abbrechen(): void
    {
        $this->formZuruecksetzen();
    }

    public function umschalten(int $id): void
    {
        $this->offenId = $this->offenId === $id ? null : $id;
    }

    public function begruenden(int $lineId, LieferantenRechnungService $svc): void
    {
        $this->aktion(fn () => $svc->begruenden($this->team(), $lineId, $this->begruendung[$lineId] ?? '', Auth::id()));
    }

    public function freigeben(int $id, LieferantenRechnungService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $svc->freigeben($this->team(), $id, Auth::id());
            $this->hinweis = 'Rechnung freigegeben — Menge und Preis stehen jetzt an den Bestellungen.';
        });
    }

    public function bezahlt(int $id, LieferantenRechnungService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $svc->bezahlt($this->team(), $id, null, Auth::id());
            $this->hinweis = 'Als bezahlt markiert.';
        });
    }

    public function strittig(int $id, bool $wert, LieferantenRechnungService $svc): void
    {
        $this->aktion(fn () => $svc->strittig($this->team(), $id, $wert, Auth::id()));
    }

    public function stornieren(int $id, LieferantenRechnungService $svc): void
    {
        $this->aktion(function () use ($id, $svc) {
            $r = $svc->stornieren($this->team(), $id, Auth::id());
            $this->hinweis = $r === null ? 'Rechnung gelöscht.' : 'Rechnung storniert — Prüfwerte an den Bestellungen zurückgerechnet.';
            if ($r === null && $this->offenId === $id) {
                $this->offenId = null;
            }
        });
    }

    public function render(LieferantenRechnungService $svc, FaRechte $rechte)
    {
        $team = $this->team();
        $detail = null;
        if ($this->offenId !== null) {
            try {
                $detail = $svc->detail($team, $this->offenId);
                $detail['anhang_url'] = $detail['anhang'] !== null ? $svc->anhangUrl($team, $this->offenId) : null;
                foreach ($detail['zeilen'] as $z) {
                    $this->begruendung[$z['id']] ??= (string) $z['begruendung'];
                }
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->offenId = null;
            }
        }
        $summe = round(array_sum(array_map(fn ($z) => ! empty($z['an']) ? ($this->zahl($z['qty']) ?? 0) * ($this->zahl($z['preis']) ?? 0) : 0, $this->zeilen))
            + array_sum(array_map(fn ($n) => ($n['art'] === 'rabatt' ? -1 : 1) * abs($this->zahl($n['betrag']) ?? 0), $this->neben)), 2);

        return view('foodalchemist::livewire.wareneingang.rechnungen', [
            'liste' => $svc->liste($team, ['supplier_id' => $this->fLieferant, 'status' => $this->fStatus, 'suche' => $this->fSuche]),
            'detail' => $detail,
            'lieferanten' => FoodAlchemistSupplier::visibleToTeam($team)->orderBy('name')->get(['id', 'name']),
            'statusLabels' => FoodAlchemistSupplierInvoice::STATUS_LABELS,
            'arten' => array_diff_key(FoodAlchemistSupplierInvoiceLine::ARTEN, ['ware' => true]),
            'summe' => $summe,
            'darfErfassen' => $rechte->darf(Auth::user(), $team, FaRolle::Kuratieren),
            'darfFreigeben' => $rechte->darf(Auth::user(), $team, FaRolle::Freigeben),
        ]);
    }

    // ── intern ──

    private function schreiben(LieferantenRechnungService $svc): int
    {
        $lines = [];
        foreach ($this->zeilen as $z) {
            if (empty($z['an'])) {
                continue;
            }
            $lines[] = ['delivery_note_line_id' => $z['delivery_note_line_id'] ?? null, 'order_line_id' => $z['order_line_id'] ?? null,
                'art' => 'ware', 'designation' => $z['designation'], 'qty_packs' => $z['qty'], 'pack_price' => $z['preis'], 'begruendung' => $z['begruendung'] ?? null];
        }
        foreach ($this->neben as $n) {
            $lines[] = ['art' => $n['art'], 'designation' => $n['designation'], 'line_net' => $n['betrag']];
        }
        if ($lines === []) {
            throw new \RuntimeException('Bitte mindestens eine Position auf die Rechnung nehmen.');
        }
        $inv = $svc->speichern($this->team(), ['supplier_id' => $this->form['supplier_id'], 'invoice_number' => $this->form['nummer'],
            'invoice_date' => $this->form['datum'] ?: null, 'total_net' => $this->form['total'], 'note' => $this->form['notiz'], 'lines' => $lines],
            Auth::id(), $this->formId);
        if ($this->anhang !== null) {
            $svc->anhangSpeichern($this->team(), (int) $inv->id, $this->anhang, Auth::id());
        }

        return (int) $inv->id;
    }

    private function formZuruecksetzen(): void
    {
        $this->formOffen = false;
        $this->formId = null;
        $this->form = ['supplier_id' => null, 'nummer' => '', 'datum' => now()->toDateString(), 'total' => '', 'notiz' => ''];
        $this->zeilen = [];
        $this->neben = [];
        $this->anhang = null;
    }

    private function aktion(callable $tu): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $tu();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nicht gefunden — bitte Seite neu laden.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function zahl(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '.', trim((string) $v));

        return is_numeric($v) ? (float) $v : null;
    }

    private function zahlText(mixed $v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) (0 + (float) $v));
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
