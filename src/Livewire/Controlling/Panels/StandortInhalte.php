<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\StandortService;

/**
 * Spec 77d · Controlling → Standorte & Freigaben (Editor). Je Standort (Unter-Team): Haken „übernimmt alles vom
 * Oberteam" oder nur Freigegebenes — Sammlungen und fertige Ausgaben. Sammlungen werden hier gepflegt.
 * Die Rechte prüft `InhaltsFreigabeService` (Freigaben FA-Admin, Sammlungen ab Kuratieren).
 */
class StandortInhalte extends Component
{
    public ?string $fehler = null;

    public ?string $meldung = null;

    public string $neueSammlung = '';

    /** @var array<int, string> Standort-ID → „typ:id" der Ausgabe, die freigegeben werden soll */
    public array $freigabeNeu = [];

    /** Sammlung, in die gerade gesucht wird, und der Suchtext. */
    public ?int $sammlungOffen = null;

    public string $suche = '';

    /** Picker-Ebene: gericht | basis | concept | paket | format */
    public string $ebene = 'gericht';

    /** Picker-Filter: Status, Kategorie (Rezepte/Konzepte), nur noch nicht Enthaltenes */
    public string $filterStatus = '';

    public string $filterKategorie = '';

    public bool $nurNeue = false;

    /** @var array<int, bool> im Picker angehakte IDs der aktuellen Ebene */
    public array $pickerAuswahl = [];

    /** Ebene → [Sammlungs-Typ, Label] */
    public const EBENEN = [
        'gericht' => ['recipe', 'Gerichte'], 'basis' => ['recipe', 'Basisrezepte'],
        'concept' => ['concept', 'Konzepte'], 'paket' => ['paket', 'Pakete'], 'format' => ['format', 'Formate'],
    ];

    public function updatedEbene(): void
    {
        $this->pickerAuswahl = [];
        $this->filterStatus = '';
        $this->filterKategorie = '';
    }

    public function updatedFilterStatus(): void
    {
        $this->pickerAuswahl = [];
    }

    public function updatedFilterKategorie(): void
    {
        $this->pickerAuswahl = [];
    }

    /** Alle sichtbaren (nicht schon enthaltenen) Treffer anhaken. @param list<int> $ids */
    public function alleAnhaken(array $ids): void
    {
        foreach ($ids as $id) {
            $this->pickerAuswahl[(int) $id] = true;
        }
    }

    public function updatedSuche(): void
    {
        $this->pickerAuswahl = [];
    }

    /** Alle angehakten Einträge der Ebene in die offene Sammlung. */
    public function auswahlHinzu(InhaltsFreigabeService $svc): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->pickerAuswahl)));
        if ($this->sammlungOffen === null || $ids === []) {
            $this->fehler = 'Bitte zuerst Einträge anhaken.';

            return;
        }
        $typ = self::EBENEN[$this->ebene][0] ?? 'recipe';
        $this->fehler = null;
        $this->meldung = null;
        try {
            $n = $svc->sammlungHinzu($this->team(), (int) $this->sammlungOffen, $typ, $ids, Auth::user());
            $this->pickerAuswahl = [];
            $this->meldung = $n . ' hinzugefügt.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function erbtAllesSetzen(int $unterTeamId, bool $an, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->setzeErbtAlles($this->team(), $unterTeamId, $an, Auth::user()),
            $an ? 'Der Standort übernimmt wieder alles vom Oberteam.' : 'Der Standort sieht jetzt nur noch, was ihm freigegeben ist.');
    }

    public function freigeben(int $unterTeamId, InhaltsFreigabeService $svc): void
    {
        [$typ, $id] = array_pad(explode(':', (string) ($this->freigabeNeu[$unterTeamId] ?? '')), 2, null);
        if ($typ === null || $id === null || $id === '') {
            $this->fehler = 'Bitte zuerst eine Ausgabe oder Sammlung wählen.';

            return;
        }
        $this->ausfuehren(fn () => $svc->freigeben($this->team(), $typ, (int) $id, $unterTeamId, Auth::user()), 'Freigegeben.');
        unset($this->freigabeNeu[$unterTeamId]);
    }

    public function freigabeEntziehen(int $freigabeId, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->freigabeEntziehen($this->team(), $freigabeId, Auth::user()), 'Freigabe entzogen.');
    }

    public function sammlungAnlegen(InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(function () use ($svc) {
            $this->sammlungOffen = $svc->sammlungAnlegen($this->team(), $this->neueSammlung, null, Auth::user());
            $this->neueSammlung = '';
        }, 'Sammlung angelegt.');
    }

    public function sammlungLoeschen(int $id, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->sammlungLoeschen($this->team(), $id, Auth::user()), 'Sammlung gelöscht.');
    }

    public function sammlungOeffnen(int $id): void
    {
        $this->sammlungOffen = $this->sammlungOffen === $id ? null : $id;
        $this->suche = '';
        $this->pickerAuswahl = [];
    }

    public function sammlungHinzu(int $sammlungId, string $typ, int $id, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->sammlungHinzu($this->team(), $sammlungId, $typ, [$id], Auth::user()), 'Hinzugefügt.');
    }

    public function sammlungEntfernen(int $sammlungId, string $typ, int $id, InhaltsFreigabeService $svc): void
    {
        $this->ausfuehren(fn () => $svc->sammlungEntfernen($this->team(), $sammlungId, $typ, $id, Auth::user()), 'Entfernt.');
    }

    public function render(InhaltsFreigabeService $svc, StandortService $standorte)
    {
        $team = $this->team();
        $unter = $standorte->unterTeams($team);
        foreach ($unter as &$u) {
            $u['erbt_alles'] = $svc->erbtAlles(Team::find($u['id']));
        }
        unset($u);
        $sammlungen = $svc->sammlungen($team);

        // Eigene Ausgaben (Kunden-IP: nur eigene, nie geerbte) als Freigabe-Auswahl
        $ausgaben = ['Sammlungen' => collect($sammlungen)->mapWithKeys(fn ($s) => ['sammlung:'.$s['id'] => $s['name']])->all()];
        foreach (['foodbook' => ['foodalchemist_foodbooks', 'Foodbooks', 'label'], 'speiseplan' => ['foodalchemist_menu_plans', 'Speisepläne', 'name'], 'speisekarte' => ['foodalchemist_menu_cards', 'Speisekarten', 'name']] as $typ => [$tabelle, $label, $spalte]) {
            $ausgaben[$label] = DB::table($tabelle)->where('team_id', $team->id)->whereNull('deleted_at')->orderBy($spalte)->limit(300)
                ->pluck($spalte, 'id')->mapWithKeys(fn ($n, $id) => [$typ.':'.$id => (string) $n])->all();
        }

        // Picker: Ebene wählen, optional filtern, anhaken — schon enthaltene Einträge sind markiert
        $treffer = [];
        $statusOptionen = [];
        $kategorieOptionen = [];
        if ($this->sammlungOffen !== null) {
            [$typ] = self::EBENEN[$this->ebene] ?? self::EBENEN['gericht'];
            $klasse = match ($this->ebene) {
                'concept' => FoodAlchemistConcept::class,
                'paket' => \Platform\FoodAlchemist\Models\FoodAlchemistPaket::class,
                'format' => FoodAlchemistFormat::class,
                default => FoodAlchemistRecipe::class,
            };
            $tabelle = (new $klasse())->getTable();
            $spalten = \Illuminate\Support\Facades\Schema::getColumnListing($tabelle);
            $basis = function () use ($klasse, $team) {
                $q = $klasse::visibleToTeam($team);
                if ($this->ebene === 'gericht' || $this->ebene === 'basis') {
                    $q->where('is_sales_recipe', $this->ebene === 'gericht');
                }

                return $q;
            };
            // Filter-Optionen aus dem tatsächlichen Bestand der Ebene
            if (in_array('status', $spalten, true)) {
                $statusOptionen = $basis()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status')
                    ->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : (string) $s)->unique()->mapWithKeys(fn ($s) => [$s => $s])->all();
            }
            if (in_array('category_id', $spalten, true)) {
                $katIds = $basis()->whereNotNull('category_id')->distinct()->pluck('category_id')->all();
                $katKlasse = $klasse === FoodAlchemistRecipe::class ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::class : \Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory::class;
                $katSpalte = $klasse === FoodAlchemistRecipe::class ? 'label' : 'name';   // Rezept-Kategorien heißen „label"
                $kategorieOptionen = $katIds === [] ? [] : $katKlasse::whereIn('id', $katIds)->orderBy($katSpalte)->pluck($katSpalte, 'id')->all();
            }
            $q = $basis();
            if (mb_strlen(trim($this->suche)) >= 2) {
                $q->where('name', 'like', '%'.trim($this->suche).'%');
            }
            if ($this->filterStatus !== '' && in_array('status', $spalten, true)) {
                $q->where('status', $this->filterStatus);
            }
            if ($this->filterKategorie !== '' && in_array('category_id', $spalten, true)) {
                $q->where('category_id', (int) $this->filterKategorie);
            }
            $drin = collect(collect($sammlungen)->firstWhere('id', $this->sammlungOffen)['objekte'] ?? [])
                ->where('typ', $typ)->pluck('id')->flip();
            if ($this->nurNeue && $drin->isNotEmpty()) {
                $q->whereNotIn($tabelle.'.id', $drin->keys()->all());
            }
            foreach ($q->orderBy('name')->limit(60)->get(['id', 'name']) as $m) {
                $treffer[] = ['typ' => $typ, 'id' => (int) $m->id, 'name' => (string) $m->name, 'drin' => $drin->has((int) $m->id)];
            }
        }

        return view('foodalchemist::livewire.controlling.panels.standort-inhalte', [
            'standorte' => $unter,
            'freigaben' => collect($svc->freigaben($team))->groupBy('empfaenger_team_id'),
            'sammlungen' => $sammlungen,
            'ausgaben' => $ausgaben,
            'treffer' => $treffer,
            'typLabels' => InhaltsFreigabeService::SAMMLUNG_TYPEN,
            'ebenen' => array_map(fn ($e) => $e[1], self::EBENEN),
            'statusOptionen' => $statusOptionen,
            'kategorieOptionen' => $kategorieOptionen,
            'ausgabeLabels' => InhaltsFreigabeService::AUSGABE_TYPEN,
        ]);
    }

    private function ausfuehren(callable $fn, string $ok): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $fn();
            $this->meldung = $ok;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
