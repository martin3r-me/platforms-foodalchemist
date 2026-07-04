<?php

namespace Platform\FoodAlchemist\Livewire\Speiseplan;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * M14-03 / Speiseplan v2 — Menü-Linien × echte Wochentage × Mahlzeit. Wochen-Matrix
 * + Monats-Kalender, Linien-Editor, Zyklus-Ausrollen, Kosten/Veggie/Wiederholung.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sp')]
    public ?int $selectedId = null;

    public array $form = ['name' => '', 'start_datum' => null, 'zyklus_wochen' => 4, 'min_abstand_tage' => 0, 'status' => 'draft'];

    public string $mahlzeit = 'mittag';

    public string $ansicht = 'woche';                 // woche | monat

    public ?string $montag = null;                    // Y-m-d (Montag der sichtbaren Woche)

    public ?int $monatCursor = null;                  // 1. des sichtbaren Monats als Timestamp-Ersatz: Y-m-01

    public ?string $monatStr = null;                  // Y-m-01

    // Linien-Editor
    public string $neueLinie = '';

    public ?int $editLinieId = null;

    public array $linieForm = ['name' => '', 'farbe' => '', 'ist_vegetarisch' => false];

    // Zellen-Picker
    public ?string $cellDatum = null;

    public ?int $cellLinie = null;

    public string $pickerTyp = 'gericht';             // concept | paket | gericht

    public string $pickerSuche = '';

    // Ausrollen
    public ?string $ausrollenBis = null;

    public ?string $ausrollenInfo = null;

    public function mount(SpeiseplanService $svc): void
    {
        if ($this->selectedId !== null) {
            $this->waehle($this->selectedId, $svc);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function neu(SpeiseplanService $svc): void
    {
        $sp = $svc->create($this->team(), ['name' => 'Neuer Speiseplan']);
        $this->waehle($sp->id, $svc);
    }

    public function waehle(int $id, SpeiseplanService $svc): void
    {
        $sp = $svc->detail($this->team(), $id);
        if ($sp === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'name' => $sp->name,
            'start_datum' => optional($sp->start_datum)->format('Y-m-d'),
            'zyklus_wochen' => $sp->zyklus_wochen,
            'min_abstand_tage' => $sp->min_abstand_tage,
            'status' => $sp->status,
        ];
        $start = $sp->start_datum ?? Carbon::now();
        $this->montag = $start->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->monatStr = $start->copy()->startOfMonth()->format('Y-m-d');
        $this->ausrollenBis = $start->copy()->addMonths(3)->format('Y-m-d');
        $this->cellSchliessen();
        $this->editLinieId = null;
    }

    public function speichern(SpeiseplanService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->update($this->team(), $this->selectedId, $this->form);
        }
    }

    public function loeschen(int $id, SpeiseplanService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    // ── Navigation ───────────────────────────────────────────────────────

    public function wocheVerschieben(int $wochen): void
    {
        $this->montag = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY)->addWeeks($wochen)->format('Y-m-d');
        $this->cellSchliessen();
    }

    public function heute(): void
    {
        $this->montag = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->cellSchliessen();
    }

    public function monatVerschieben(int $monate): void
    {
        $this->monatStr = Carbon::parse($this->monatStr ?? 'now')->startOfMonth()->addMonths($monate)->format('Y-m-d');
    }

    public function tagOeffnen(string $datum): void
    {
        $this->montag = Carbon::parse($datum)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->ansicht = 'woche';
        $this->cellSchliessen();
    }

    public function ansichtSetzen(string $a): void
    {
        $this->ansicht = in_array($a, ['woche', 'monat'], true) ? $a : 'woche';
        $this->cellSchliessen();
    }

    public function mahlzeitSetzen(string $m): void
    {
        $this->mahlzeit = array_key_exists($m, SpeiseplanService::MAHLZEITEN) ? $m : 'mittag';
        $this->cellSchliessen();
    }

    // ── Linien ─────────────────────────────────────────────────────────

    public function linieAdd(SpeiseplanService $svc): void
    {
        if ($this->selectedId === null || trim($this->neueLinie) === '') {
            return;
        }
        $svc->addLinie($this->team(), $this->selectedId, ['name' => $this->neueLinie]);
        $this->neueLinie = '';
    }

    public function linieEdit(int $id, SpeiseplanService $svc): void
    {
        $sp = $svc->detail($this->team(), (int) $this->selectedId);
        $linie = $sp?->linien->firstWhere('id', $id);
        if ($linie === null) {
            return;
        }
        $this->editLinieId = $id;
        $this->linieForm = ['name' => $linie->name, 'farbe' => $linie->farbe ?? '', 'ist_vegetarisch' => (bool) $linie->ist_vegetarisch];
    }

    public function linieSpeichern(SpeiseplanService $svc): void
    {
        if ($this->editLinieId !== null) {
            $svc->updateLinie($this->team(), $this->editLinieId, $this->linieForm);
            $this->editLinieId = null;
        }
    }

    public function linieRaus(int $id, SpeiseplanService $svc): void
    {
        $svc->removeLinie($this->team(), $id);
        if ($this->editLinieId === $id) {
            $this->editLinieId = null;
        }
    }

    public function linieVerschieben(int $id, int $richtung, SpeiseplanService $svc): void
    {
        $svc->reorderLinie($this->team(), $id, $richtung);
    }

    // ── Zellen-Picker ────────────────────────────────────────────────────

    public function zelleOeffnen(string $datum, ?int $linieId): void
    {
        $this->cellDatum = $datum;
        $this->cellLinie = $linieId;
        $this->pickerSuche = '';
    }

    public function cellSchliessen(): void
    {
        $this->cellDatum = null;
        $this->cellLinie = null;
        $this->pickerSuche = '';
    }

    public function inhaltHinzu(string $typ, int $id, SpeiseplanService $svc): void
    {
        if ($this->selectedId === null || $this->cellDatum === null) {
            return;
        }
        $feld = ['concept' => 'concept_id', 'paket' => 'package_id', 'gericht' => 'vk_recipe_id'][$typ] ?? 'vk_recipe_id';
        $svc->addEintrag($this->team(), $this->selectedId, [
            'datum' => $this->cellDatum, 'line_id' => $this->cellLinie, 'mahlzeit' => $this->mahlzeit, $feld => $id,
        ]);
        $this->pickerSuche = '';
    }

    public function eintragRaus(int $id, SpeiseplanService $svc): void
    {
        $svc->removeEintrag($this->team(), $id);
    }

    public function ausrollen(SpeiseplanService $svc): void
    {
        if ($this->selectedId === null || $this->ausrollenBis === null) {
            return;
        }
        $n = $svc->vorlageAusrollen($this->team(), $this->selectedId, $this->ausrollenBis);
        $this->ausrollenInfo = $n > 0 ? "{$n} Einträge ausgerollt." : 'Nichts auszurollen (Vorlage leer oder schon belegt).';
    }

    public function render(SpeiseplanService $svc)
    {
        $team = $this->team();
        $sp = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;

        if ($sp !== null && $this->montag === null) {
            $start = $sp->start_datum ?? Carbon::now();
            $this->montag = $start->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $this->monatStr = $start->copy()->startOfMonth()->format('Y-m-d');
        }

        $montag = Carbon::parse($this->montag ?? 'now')->startOfWeek(Carbon::MONDAY);
        $wochenTage = [];
        for ($i = 0; $i < 5; $i++) {                  // Mo–Fr (Werktage)
            $wochenTage[] = $montag->copy()->addDays($i);
        }
        $monatStart = Carbon::parse($this->monatStr ?? 'now')->startOfMonth();

        $kandidaten = collect();
        if ($sp !== null && $this->cellDatum !== null && $this->pickerSuche !== '') {
            $s = '%' . mb_strtolower($this->pickerSuche) . '%';
            $kandidaten = match ($this->pickerTyp) {
                'paket' => FoodAlchemistPaket::visibleToTeam($team)->whereRaw('LOWER(name) LIKE ?', [$s])->orderBy('name')->limit(15)->get(['id', 'name']),
                'concept' => FoodAlchemistConcept::visibleToTeam($team)->echte()->whereRaw('LOWER(name) LIKE ?', [$s])->orderBy('name')->limit(15)->get(['id', 'name']),
                default => FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->whereRaw('LOWER(name) LIKE ?', [$s])->orderBy('name')->limit(15)->get(['id', 'name']),
            };
        }

        return view('foodalchemist::livewire.speiseplan.index', [
            'plaene' => $svc->paginateBrowser(['search' => $this->search], $team),
            'sp' => $sp,
            'linien' => $sp !== null ? $sp->linien : collect(),
            'wochenTage' => $wochenTage,
            'montagDt' => $montag,
            'monatStart' => $monatStart,
            'raster' => $sp !== null ? $svc->wochenRaster($sp, $this->mahlzeit, $montag) : [],
            'monatsRaster' => $sp !== null ? $svc->monatsRaster($sp, (int) $monatStart->year, (int) $monatStart->month, $this->mahlzeit) : [],
            'kosten' => $sp !== null ? $svc->wochenKosten($sp, $this->mahlzeit, $montag) : null,
            'veggie' => $sp !== null ? $svc->veggieCheck($sp, $this->mahlzeit, $montag) : null,
            'wiederholungen' => $sp !== null ? collect($svc->wiederholungen($sp))->where('konflikt', true)->values()->all() : [],
            'mahlzeiten' => SpeiseplanService::MAHLZEITEN,
            'kandidaten' => $kandidaten,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
