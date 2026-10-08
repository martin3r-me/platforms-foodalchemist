<?php

namespace Platform\FoodAlchemist\Livewire\Lager;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistStorageBin;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\InventurService;
use Platform\FoodAlchemist\Services\LagerBewegungService;
use Platform\FoodAlchemist\Services\LagerEinrichtungService;

/**
 * Spec 66 · Lager: Bestand, Bewegungen, Inventuren — Spec 66b: Einrichten (Stellplätze, Stammplätze,
 * Vorschlag aus Zustand/Warengruppe), smarte Filter, Zählen in Karton/Einheit/lose.
 * Periodisches Lager — der Bestand entsteht aus Wareneingang und Inventur.
 */
class Index extends Component
{
    private const REITER = ['bestand', 'bewegungen', 'inventur', 'einrichten', 'eigenproduktion', 'lagerartikel'];

    private const FILTER_LEER = ['suche' => '', 'stellplatz' => '', 'zustand' => '', 'warengruppe' => '', 'lieferant' => '', 'status' => '', 'ohne_preis' => false, 'ladenhueter' => false];

    #[Url(as: 'reiter')]
    public string $reiter = 'bestand';          // bestand | bewegungen | inventur | einrichten

    #[Url(as: 'ort')]
    public $lagerortId = null;

    /** Spec 66b: gemeinsame Filterleiste für Bestand, Zählliste und Einrichten. */
    public array $filter = self::FILTER_LEER;

    public string $quelle = '';

    /** Spec 67: Filter Grund + Hand-Buchung. */
    public string $grundFilter = '';

    public bool $buchungOffen = false;

    /** Spec 69: Eigenproduktion einlagern / entnehmen. */
    public array $einlagern = [];

    public string $einlagernSuche = '';

    public bool $nurAblaufend = false;

    public array $entnahme = [];

    public array $buchung = [];

    public string $buchungGpSuche = '';

    #[Url(as: 'inventur')]
    public ?int $inventurId = null;

    public $neuLagerortId = null;

    public string $neuDatum = '';

    public string $positionSuche = '';

    /** Buchen: nicht gezählte Positionen als 0 buchen. */
    public bool $nichtGezaehltNull = false;

    // Einrichten
    public string $neuPlatzName = '';

    public string $neuPlatzZone = '';

    /** @var list<int|string> markierte Grundprodukte für die Massen-Zuordnung */
    public array $auswahl = [];

    public $zielPlatzId = '';

    /** Spec 74: Lagerartikel — Eingabe Mindest/Soll je GP (kg/l/Stk), neues GP per Suche. */
    public array $vorrat = [];

    public string $vorratSuche = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(): void
    {
        $this->neuDatum = now()->toDateString();
        $this->reiter = in_array($this->reiter, self::REITER, true) ? $this->reiter : 'bestand';
        if ($this->reiter === 'inventur' && $this->inventurId !== null) {
            $this->dispatch('modal.open', name: 'lager-inventur');
        }
        // Spec 69: aus der Produktion „ins Lager" — Rezept, Menge und Zeile vorbelegen
        if ($this->reiter === 'eigenproduktion' && request()->filled('einlagern_rezept')) {
            $this->einlagern = ['menge' => (string) request('einlagern_menge', ''), 'production_order_line_id' => request()->integer('einlagern_zeile') ?: null];
            $this->einlagernRezept(request()->integer('einlagern_rezept'));
        }
    }

    public function reiterSetzen(string $reiter): void
    {
        $this->reiter = in_array($reiter, self::REITER, true) ? $reiter : 'bestand';
        $this->fehler = null;
        $this->hinweis = null;
        $this->filter = self::FILTER_LEER;
        $this->auswahl = [];
    }

    public function filterZuruecksetzen(): void
    {
        $this->filter = self::FILTER_LEER;
    }

    public function updatedLagerortId(): void
    {
        $this->filter['stellplatz'] = '';
        $this->auswahl = [];
    }

    // ── Inventur ────────────────────────────────────────────────────────────

    public function inventurAnlegen(InventurService $svc): void
    {
        $this->fehler = null;
        try {
            $count = $svc->anlegen($this->team(), (int) $this->neuLagerortId, $this->neuDatum ?: now()->toDateString());
        } catch (\Throwable $e) {
            $this->fehler = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 'Bitte einen Lagerort wählen.' : $e->getMessage();

            return;
        }
        $this->reiter = 'inventur';
        $this->inventurId = (int) $count->id;
        $this->filter = self::FILTER_LEER;
        $this->hinweis = 'Inventur angelegt — Zählliste ist vorbelegt und nach Laufweg sortiert.';
        $this->dispatch('modal.open', name: 'lager-inventur');
    }

    public function inventurOeffnen(int $id): void
    {
        $this->inventurId = $id;
        $this->positionSuche = '';
        $this->filter = self::FILTER_LEER;
        $this->nichtGezaehltNull = false;
        $this->fehler = null;
        $this->hinweis = null;
        $this->dispatch('modal.open', name: 'lager-inventur');
    }

    public function inventurSchliessen(): void
    {
        $this->inventurId = null;
        $this->filter = self::FILTER_LEER;
    }

    /** Inventur-Editor geschlossen (✕, Escape, Hintergrund) → zurück zur Liste. */
    #[On('modal.closed')]
    public function beiModalGeschlossen(?string $name = null): void
    {
        if ($name === 'lager-inventur') {
            $this->inventurSchliessen();
        }
    }

    /** Gezählte Menge in kg / l / Stk (leer = nicht gezählt). */
    public function zaehlen(int $lineId, $menge, InventurService $svc): void
    {
        try {
            $svc->zaehlen($this->team(), $lineId, $menge);
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Spec 66b: Kartons + Einheiten + lose (kg/l/Stk). */
    public function zaehlenGebinde(int $lineId, $kartons, $einheiten, $lose, InventurService $svc): void
    {
        try {
            $svc->zaehlenGebinde($this->team(), $lineId, $kartons, $einheiten, $lose);
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function positionHinzu(int $gpId, InventurService $svc): void
    {
        if ($this->inventurId === null) {
            return;
        }
        try {
            $svc->positionHinzu($this->team(), $this->inventurId, $gpId);
            $this->positionSuche = '';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function buchen(InventurService $svc): void
    {
        if ($this->inventurId === null) {
            return;
        }
        try {
            $c = $svc->buchen($this->team(), $this->inventurId, Auth::id(), $this->nichtGezaehltNull);
            $this->hinweis = 'Inventur gebucht — Bestandswert ' . number_format((float) $c->value_total, 2, ',', '.') . ' €.';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function loeschen(InventurService $svc): void
    {
        if ($this->inventurId === null) {
            return;
        }
        try {
            $svc->loeschen($this->team(), $this->inventurId);
            $this->inventurId = null;
            $this->dispatch('modal.close', name: 'lager-inventur');
            $this->hinweis = 'Inventur verworfen.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Lagerartikel (Spec 74) ──────────────────────────────────────────────

    public function lagerartikelSpeichern(int $gpId, \Platform\FoodAlchemist\Services\LagerartikelService $svc): void
    {
        $this->fehler = null;
        try {
            $svc->setzen($this->team(), $gpId, true, $this->vorrat[$gpId]['min'] ?? null, $this->vorrat[$gpId]['soll'] ?? null);
            $this->hinweis = 'Lagerartikel gespeichert.';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function lagerartikelHinzu(int $gpId, \Platform\FoodAlchemist\Services\LagerartikelService $svc): void
    {
        try {
            $svc->setzen($this->team(), $gpId, true);
            $this->vorratSuche = '';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function lagerartikelEntfernen(int $gpId, \Platform\FoodAlchemist\Services\LagerartikelService $svc): void
    {
        $svc->setzen($this->team(), $gpId, false);
        unset($this->vorrat[$gpId]);
    }

    /** Spec 74: alle genutzten Grundprodukte einer Warengruppe als Lagerartikel markieren. */
    public function warengruppeAlsLagerartikel(string $code, \Platform\FoodAlchemist\Services\LagerartikelService $svc): void
    {
        $n = $svc->warengruppeMarkieren($this->team(), $code);
        $this->hinweis = $n . ' Grundprodukt(e) als Lagerartikel markiert — jetzt Mindest- und Sollbestand pflegen.';
    }

    /** Einrichten: markierte Grundprodukte als Lagerartikel (Gewürze, Öle …). */
    public function auswahlAlsLagerartikel(\Platform\FoodAlchemist\Services\LagerartikelService $svc): void
    {
        $n = $svc->markieren($this->team(), array_map('intval', $this->auswahl));
        $this->auswahl = [];
        $this->hinweis = $n . ' Grundprodukt(e) als Lagerartikel markiert — Mindest- und Sollbestand im Reiter „Lagerartikel" pflegen.';
    }

    // ── Bewegungen von Hand (Spec 67) ───────────────────────────────────────

    public function buchungOeffnen(string $art = 'abgang'): void
    {
        $this->buchung = [
            'art' => in_array($art, ['zugang', 'abgang', 'umlagerung'], true) ? $art : 'abgang',
            'gp_id' => null, 'gp_name' => '', 'location_id' => $this->lagerortId ?: ($this->neuLagerortId ?? ''), 'ziel_location_id' => '',
            'menge' => '', 'kartons' => '', 'einheiten' => '', 'lose' => '', 'grund' => '', 'notiz' => '', 'datum' => now()->toDateString(), 'preis' => '',
        ];
        $this->buchungGpSuche = '';
        $this->buchungOffen = true;
        $this->fehler = null;
    }

    public function buchungSchliessen(): void
    {
        $this->buchungOffen = false;
    }

    public function updatedBuchung($wert, $schluessel): void
    {
        if ($schluessel === 'art') {
            $this->buchung['grund'] = '';
        }
    }

    public function buchungGpWaehlen(int $gpId): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($this->team())->find($gpId, ['id', 'name']);
        if ($gp !== null) {
            $this->buchung['gp_id'] = $gp->id;
            $this->buchung['gp_name'] = $gp->name;
            $this->buchungGpSuche = '';
        }
    }

    public function bewegungBuchen(LagerBewegungService $svc): void
    {
        $this->fehler = null;
        if (empty($this->buchung['gp_id'])) {
            $this->fehler = 'Bitte ein Grundprodukt wählen.';

            return;
        }
        try {
            $ms = $svc->buchen($this->team(), $this->buchung, Auth::id());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Bitte Lagerort (und Ziel) wählen.';

            return;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $art = ['zugang' => 'Zugang', 'abgang' => 'Abgang', 'umlagerung' => 'Umlagerung'][$this->buchung['art']] ?? 'Bewegung';
        $wert = $ms[0]->value_eur !== null ? ' · ' . number_format((float) $ms[0]->value_eur, 2, ',', '.') . ' €' : '';
        $this->hinweis = $art . ' gebucht: ' . $this->buchung['gp_name'] . $wert . '.';
        $this->buchungOffen = false;
    }

    public function stornieren(int $movementId, LagerBewegungService $svc): void
    {
        $this->aktion(function () use ($movementId, $svc) {
            $svc->stornieren($this->team(), $movementId, Auth::id());
            $this->hinweis = 'Buchung storniert — Gegenbuchung angelegt.';
        });
    }

    // ── Eigenproduktion (Spec 69) ───────────────────────────────────────────

    public function einlagernRezept(int $recipeId): void
    {
        $r = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($this->team())->find($recipeId);
        if ($r === null) {
            return;
        }
        $eigen = app(\Platform\FoodAlchemist\Services\EigenproduktionService::class);
        $this->einlagern = [
            'recipe_id' => $r->id, 'name' => $r->name, 'einheit' => $eigen->anzeigeEinheit($eigen->einheit($r)),
            'menge' => $this->einlagern['menge'] ?? '', 'location_id' => $this->einlagern['location_id'] ?? ($this->lagerortId ?: ''),
            'lagerart' => $r->storage_type ?: 'gekuehlt', 'produziert_am' => now()->toDateString(), 'eingefroren_am' => '', 'verbrauchen_bis' => '', 'notiz' => '',
            'production_order_line_id' => $this->einlagern['production_order_line_id'] ?? null,
        ];
        $this->einlagernSuche = '';
    }

    public function einlagernSpeichern(\Platform\FoodAlchemist\Services\EigenproduktionService $svc): void
    {
        if (empty($this->einlagern['recipe_id'])) {
            $this->fehler = 'Bitte ein Rezept wählen.';

            return;
        }
        $this->aktion(function () use ($svc) {
            $b = $svc->einlagern($this->team(), $this->einlagern, Auth::id());
            $this->hinweis = 'Eingelagert: Charge ' . $b->charge . ($b->best_before ? ' · verbrauchen bis ' . $b->best_before->format('d.m.Y') : '') . '.';
            $this->einlagern = ['letzte_charge' => $b->id];
        });
    }

    public function entnehmen(int $batchId, \Platform\FoodAlchemist\Services\EigenproduktionService $svc): void
    {
        $e = $this->entnahme[$batchId] ?? [];
        $this->aktion(function () use ($svc, $batchId, $e) {
            $r = $svc->entnehmen($this->team(), ['batch_id' => $batchId, 'menge' => $e['menge'] ?? '', 'grund' => $e['grund'] ?? 'verbrauch'], Auth::id());
            $this->hinweis = 'Entnommen aus Charge ' . $r[0]['charge'] . '.';
            unset($this->entnahme[$batchId]);
        });
    }

    // ── Einrichten (Spec 66b) ───────────────────────────────────────────────

    public function platzAnlegen(LagerEinrichtungService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $svc->stellplatzAnlegen($this->team(), $this->ortPflicht(), $this->neuPlatzName, $this->neuPlatzZone ?: null);
            $this->neuPlatzName = '';
        });
    }

    public function platzUmbenennen(int $binId, string $name, LagerEinrichtungService $svc): void
    {
        $this->aktion(fn () => $svc->stellplatzAendern($this->team(), $binId, ['name' => $name]));
    }

    public function platzZone(int $binId, string $zone, LagerEinrichtungService $svc): void
    {
        $this->aktion(fn () => $svc->stellplatzAendern($this->team(), $binId, ['zone' => $zone ?: null]));
    }

    public function platzVerschieben(int $binId, int $richtung, LagerEinrichtungService $svc): void
    {
        $this->aktion(fn () => $svc->stellplatzVerschieben($this->team(), $binId, $richtung));
    }

    public function platzLoeschen(int $binId, LagerEinrichtungService $svc): void
    {
        $this->aktion(fn () => $svc->stellplatzLoeschen($this->team(), $binId));
    }

    /** Stammplatz eines einzelnen Grundprodukts setzen ('' = entfernen). */
    public function stammplatz(int $gpId, $binId, LagerEinrichtungService $svc): void
    {
        $this->aktion(fn () => $svc->zuordnen($this->team(), $this->ortPflicht(), [$gpId], $binId !== '' && $binId !== null ? (int) $binId : null));
    }

    /** Markierte Grundprodukte gesammelt einem Stellplatz zuordnen. */
    public function auswahlZuordnen(LagerEinrichtungService $svc): void
    {
        if ($this->auswahl === []) {
            $this->fehler = 'Bitte zuerst Grundprodukte markieren.';

            return;
        }
        $this->aktion(function () use ($svc) {
            $n = $svc->zuordnen($this->team(), $this->ortPflicht(), array_map('intval', $this->auswahl), $this->zielPlatzId !== '' ? (int) $this->zielPlatzId : null);
            $this->hinweis = $n . ' Grundprodukt(e) ' . ($this->zielPlatzId !== '' ? 'zugeordnet.' : 'vom Stellplatz gelöst.');
            $this->auswahl = [];
        });
    }

    /** Alle sichtbaren (gefilterten) Grundprodukte markieren. */
    public function alleMarkieren(LagerEinrichtungService $svc): void
    {
        $this->auswahl = array_column($this->einrichtenZeilen($svc), 'gp_id');
    }

    public function vorschlagUebernehmen(LagerEinrichtungService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $n = $svc->vorschlagUebernehmen($this->team(), $this->ortPflicht());
            $this->hinweis = $n > 0
                ? $n . ' Grundprodukt(e) nach Zustand und Warengruppe einsortiert. Bitte kurz prüfen.'
                : 'Nichts vorzuschlagen — entweder ist alles einsortiert oder es fehlen Stellplätze mit passender Zone.';
        });
    }

    // ── Render ──────────────────────────────────────────────────────────────

    public function render(InventurService $svc, LagerEinrichtungService $einrichtung)
    {
        $team = $this->team();
        $orte = FoodAlchemistInventoryLocation::where('team_id', $team->id)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->get();
        $ortId = $this->lagerortId !== null && $this->lagerortId !== '' ? (int) $this->lagerortId : null;
        $this->neuLagerortId ??= $orte->first()?->id;
        if ($this->reiter === 'einrichten' && $ortId === null && $orte->isNotEmpty()) {
            $this->lagerortId = $orte->first()->id;
            $ortId = (int) $this->lagerortId;
        }

        $bestandAlle = $this->reiter === 'bestand' ? $svc->bestand($team, $ortId) : [];
        $bestand = $svc->filterBestand($bestandAlle, $this->filter);

        $inventur = null;
        $summen = null;
        $zeilen = collect();
        $kandidaten = collect();
        $platzOrtId = $ortId;
        if ($this->reiter === 'inventur' && $this->inventurId !== null) {
            try {
                $inventur = $svc->detail($team, $this->inventurId);
                $summen = $svc->summen($inventur);
                $platzOrtId = (int) $inventur->inventory_location_id;
                // Laufweg: Stellplatz-Reihenfolge, dann Position; ohne Stellplatz zuletzt
                $zeilen = $svc->filterZeilen($inventur->lines, $this->filter)
                    ->sortBy(fn ($l) => [$l->bin?->sort_order ?? PHP_INT_MAX, $l->position])->values();
                if (! $inventur->istGebucht() && mb_strlen(trim($this->positionSuche)) >= 2) {
                    $kandidaten = FoodAlchemistGp::visibleToTeam($team)
                        ->where('name', 'like', '%' . trim($this->positionSuche) . '%')->orderBy('name')->limit(12)->get(['id', 'name']);
                }
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->inventurId = null;
            }
        }

        $plaetze = $platzOrtId !== null
            ? $einrichtung->stellplaetze($team, $platzOrtId)
            : FoodAlchemistStorageBin::where('team_id', $team->id)->with('location:id,name')->orderBy('inventory_location_id')->orderBy('sort_order')->get();

        $lieferantIds = array_filter(array_unique(array_column($bestandAlle, 'lieferant_id')));

        // Spec 74: Lagerartikel + Signal „unter Mindestbestand"
        $laSvc = app(\Platform\FoodAlchemist\Services\LagerartikelService::class);
        $lagerartikel = $this->reiter === 'lagerartikel' ? $laSvc->liste($team) : [];
        foreach ($lagerartikel as $la) {
            $this->vorrat[$la['gp_id']] ??= ['min' => $la['min'] !== null ? str_replace('.', ',', (string) $la['min']) : '', 'soll' => $la['soll'] !== null ? str_replace('.', ',', (string) $la['soll']) : ''];
        }

        return view('foodalchemist::livewire.lager.index', [
            'lagerartikel' => $lagerartikel,
            'warengruppenVorschlag' => $this->reiter === 'lagerartikel' ? $laSvc->warengruppenVorschlag($team) : [],
            'unterMindest' => $laSvc->unterMindest($team),
            'vorratKandidaten' => $this->reiter === 'lagerartikel' && mb_strlen(trim($this->vorratSuche)) >= 2
                ? FoodAlchemistGp::visibleToTeam($team)->where('name', 'like', '%' . trim($this->vorratSuche) . '%')->whereNotIn('status', ['merged', 'rejected'])->orderBy('name')->limit(10)->get(['id', 'name'])
                : collect(),
            'orte' => $orte,
            'bestand' => $bestand,
            'bestandGesamt' => count($bestandAlle),
            'bestandWert' => round(array_sum(array_map(fn ($r) => (float) ($r['wert'] ?? 0), $bestand)), 2),
            'ohnePreis' => count(array_filter($bestand, fn ($r) => $r['wert'] === null)),
            'bewegungen' => $this->reiter === 'bewegungen' ? $svc->bewegungen($team, $this->quelle ?: null, 200, $this->grundFilter ?: null) : collect(),
            'storniert' => $this->reiter === 'bewegungen'
                ? \Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement::where('team_id', $team->id)->whereNotNull('storno_of_id')->pluck('storno_of_id')->flip()->all()
                : [],
            'buchungGebinde' => $this->buchungOffen && ! empty($this->buchung['gp_id']) && ! empty($this->buchung['location_id'])
                ? $this->gebindeSicher($team, (int) $this->buchung['gp_id'], (int) $this->buchung['location_id']) : null,
            'buchungKandidaten' => $this->buchungOffen && mb_strlen(trim($this->buchungGpSuche)) >= 2
                ? FoodAlchemistGp::visibleToTeam($team)->where('name', 'like', '%' . trim($this->buchungGpSuche) . '%')
                    ->whereNotIn('status', ['merged', 'rejected'])->orderBy('name')->limit(10)->get(['id', 'name'])
                : collect(),
            'gruende' => LagerBewegungService::GRUENDE,
            'chargen' => $this->reiter === 'eigenproduktion'
                ? app(\Platform\FoodAlchemist\Services\EigenproduktionService::class)->offeneChargen($team, null, $ortId)
                    ->when($this->nurAblaufend, fn ($c) => $c->filter(fn ($b) => $b->best_before !== null && $b->tageBisAblauf() <= 3)->values())
                : collect(),
            'ablaufendAnzahl' => app(\Platform\FoodAlchemist\Services\EigenproduktionService::class)->ablaufend($team)->count(),
            'einlagernTreffer' => $this->reiter === 'eigenproduktion' && mb_strlen(trim($this->einlagernSuche)) >= 2
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->where('name', 'like', '%' . trim($this->einlagernSuche) . '%')
                    ->orderBy('name')->limit(10)->get(['id', 'name', 'is_sales_recipe'])
                : collect(),
            'eigen' => app(\Platform\FoodAlchemist\Services\EigenproduktionService::class),
            'inventuren' => $this->reiter === 'inventur' ? $svc->liste($team) : collect(),
            'inventur' => $inventur,
            'zeilen' => $zeilen,
            'summen' => $summen,
            'kandidaten' => $kandidaten,
            'plaetze' => $plaetze,
            'einrichten' => $this->reiter === 'einrichten' && $ortId !== null ? $this->einrichtenZeilen($einrichtung) : [],
            'einrichtenGesamt' => $this->reiter === 'einrichten' && $ortId !== null ? count($einrichtung->artikel($team, $ortId)) : 0,
            'warengruppen' => FoodAlchemistLookupWarengruppe::query()->orderBy('code')->get()->unique('code')
                ->mapWithKeys(fn ($w) => [$w->code => $w->code . ' ' . $w->name])->all(),
            'lieferanten' => $lieferantIds === [] ? [] : FoodAlchemistSupplier::whereIn('id', $lieferantIds)->orderBy('name')->pluck('name', 'id')->all(),
            'zonen' => FoodAlchemistStorageBin::ZONEN,
            'svc' => $svc,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    /** Einrichten-Liste des gewählten Lagerorts, gefiltert. */
    private function einrichtenZeilen(LagerEinrichtungService $svc): array
    {
        $ortId = $this->lagerortId !== null && $this->lagerortId !== '' ? (int) $this->lagerortId : null;
        if ($ortId === null) {
            return [];
        }
        $f = $this->filter;
        $suche = mb_strtolower(trim((string) $f['suche']));

        return array_values(array_filter($svc->artikel($this->team(), $ortId), fn ($r) => ($suche === '' || str_contains(mb_strtolower($r['name']), $suche))
            && ($f['stellplatz'] === '' || ($f['stellplatz'] === 'ohne' ? $r['bin_id'] === null : (int) $f['stellplatz'] === $r['bin_id']))
            && ($f['zustand'] === '' || mb_strtolower((string) $r['zustand']) === mb_strtolower($f['zustand']))
            && ($f['warengruppe'] === '' || (string) $r['warengruppe'] === (string) $f['warengruppe'])));
    }

    private function gebindeSicher(Team $team, int $gpId, int $ortId): ?array
    {
        try {
            return app(LagerBewegungService::class)->gebindeFuer($team, $gpId, $ortId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return null;
        }
    }

    private function aktion(callable $tu): void
    {
        $this->fehler = null;
        try {
            $tu();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nicht gefunden — bitte Seite neu laden.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function ortPflicht(): int
    {
        if ($this->lagerortId === null || $this->lagerortId === '') {
            throw new \RuntimeException('Bitte einen Lagerort wählen.');
        }

        return (int) $this->lagerortId;
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
