<?php

namespace Platform\FoodAlchemist\Livewire\Preislisten;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;

/**
 * Stammdaten-Import, Stufe 1a (Kurator 29.10.2026): Vorlage herunterladen, Datei hochladen, Prüfliste lesen. Eingebettet in den Preislisten-Schirm
 * (eine Pipeline). Schreibt NICHTS in den Bestand — die Übernahme je Zeile folgt in Stufe 1b. Rechte wie der Preislisten-Import: Haupt-Team + Kuratieren.
 * Die Datei liegt im Livewire-Temp-Verzeichnis (vom System erzeugter Name), es gibt nie einen frei wählbaren Pfad.
 */
class StammdatenImport extends Component
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $datei = null;

    public ?int $lieferantId = null;

    public ?int $laufId = null;

    /** Filter der Prüfliste: '' = alle, sonst neu | aendert | konflikt | fehler | unveraendert. */
    public string $filter = '';

    /** Zuordnung nötig: Spaltenindex ⇒ Vorlagen-Überschrift ('' = nicht verwenden). @var array<int, string> */
    public array $zuordnung = [];

    public bool $merken = true;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function vorlageCsv()
    {
        $csv = app(StammdatenImportService::class)->vorlageCsv();

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'stammdaten_vorlage.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function vorlageXlsx()
    {
        $svc = app(StammdatenImportService::class);
        if (! app(PreislistenImportService::class)->xlsxVerfuegbar()) {
            $this->fehler = 'Die Excel-Vorlage ist auf diesem Server nicht verfügbar. Bitte die CSV-Vorlage nehmen.';

            return null;
        }
        $pfad = tempnam(sys_get_temp_dir(), 'fa-vorlage-');
        $svc->schreibeVorlageXlsx($pfad);

        return response()->download($pfad, 'stammdaten_vorlage.xlsx')->deleteFileAfterSend(true);
    }

    public function pruefen(): void
    {
        $this->fehler = $this->meldung = null;
        $team = $this->team();
        if ($team === null || ! $this->darf()) {
            $this->fehler = 'Stammdaten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).';

            return;
        }
        try {
            $this->validate(['datei' => 'required|file|max:10240']);
            $lauf = app(StammdatenImportService::class)->stage($team, $this->datei->getRealPath(), $this->lieferantId ?: null, Auth::id(), $this->endung(), $this->anzeigename());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->fehler = $e->validator->errors()->first('datei');

            return;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->laufId = $lauf->id;
        $this->filter = '';
        $this->datei = null;
        if ($lauf->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun::STATUS_ZUORDNUNG_NOETIG) {
            $this->zuordnungVorbereiten($lauf);
            $this->meldung = 'Die Spalten dieser Datei wurden nicht (vollständig) erkannt — bitte unten zuordnen. Es wurde nichts in den Bestand geschrieben.';

            return;
        }
        $c = $lauf->counts ?? [];
        $this->meldung = sprintf('Geprüft: %d Zeilen — %d neu, %d ändern, %d Konflikte, %d Fehler. Es wurde nichts in den Bestand geschrieben.',
            $c['zeilen'] ?? 0, $c['neu'] ?? 0, $c['aendert'] ?? 0, $c['konflikt'] ?? 0, $c['fehler'] ?? 0);
        $this->datei = null;
    }

    public function laufWaehlen(int $id): void
    {
        $team = $this->team();
        $lauf = $team !== null ? Lauf::where('team_id', $team->id)->where('art', Lauf::ART_STAMMDATEN)->find($id) : null;
        $this->laufId = $lauf?->id;
        $this->filter = '';
        if ($lauf?->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun::STATUS_ZUORDNUNG_NOETIG) {
            $this->zuordnungVorbereiten($lauf);
        }
    }

    /** Gewählte Zuordnung anwenden: die wartende Datei wird mit ihr geprüft (und je Lieferant gemerkt). */
    public function zuordnen(): void
    {
        $this->fehler = $this->meldung = null;
        $team = $this->team();
        $lauf = $team !== null && $this->laufId !== null ? Lauf::where('team_id', $team->id)->where('art', Lauf::ART_STAMMDATEN)->find($this->laufId) : null;
        if ($team === null || $lauf === null || ! $this->darf()) {
            $this->fehler = 'Stammdaten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).';

            return;
        }
        $mapping = [];
        foreach ($this->zuordnung as $idx => $ziel) {
            if ($ziel !== '' && $ziel !== null) {
                $mapping[$ziel] = (int) $idx;
            }
        }
        try {
            $neu = app(StammdatenImportService::class)->zuordnen($team, $lauf, $mapping, Auth::id(), $this->merken);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->laufId = $neu->id;
        $this->zuordnung = [];
        $c = $neu->counts ?? [];
        $this->meldung = sprintf('Geprüft: %d Zeilen — %d neu, %d ändern, %d Konflikte, %d Fehler. Es wurde nichts in den Bestand geschrieben.',
            $c['zeilen'] ?? 0, $c['neu'] ?? 0, $c['aendert'] ?? 0, $c['konflikt'] ?? 0, $c['fehler'] ?? 0);
    }

    private function zuordnungVorbereiten(Lauf $lauf): void
    {
        $this->zuordnung = [];
        foreach ((array) ($lauf->mapping['vorschlag'] ?? []) as $idx => $ziel) {
            $this->zuordnung[(int) $idx] = (string) $ziel;
        }
    }

    private function team(): ?\Platform\Core\Models\Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    private function darf(): bool
    {
        $team = $this->team();

        return $team !== null && app(PreislistenImportService::class)->darfImportieren($team, Auth::user());
    }

    private function anzeigename(): string
    {
        $name = basename(str_replace('\\', '/', (string) $this->datei?->getClientOriginalName()));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return $name === '' ? 'stammdaten' : mb_substr($name, 0, 120);
    }

    private function endung(): ?string
    {
        $e = strtolower((string) $this->datei?->getClientOriginalExtension());

        return in_array($e, [...PreislistenImportService::ENDUNGEN_CSV, ...PreislistenImportService::ENDUNGEN_EXCEL, 'xls'], true) ? $e : null;
    }

    public function render()
    {
        $team = $this->team();
        $svc = app(StammdatenImportService::class);
        $lauf = $team !== null && $this->laufId !== null ? Lauf::where('team_id', $team->id)->where('art', Lauf::ART_STAMMDATEN)->find($this->laufId) : null;

        return view('foodalchemist::livewire.preislisten.stammdaten-import', [
            'darf' => $this->darf(),
            'laeufe' => $team !== null ? Lauf::where('team_id', $team->id)->where('art', Lauf::ART_STAMMDATEN)->orderByDesc('id')->limit(20)->get() : collect(),
            'lieferanten' => $team !== null ? FoodAlchemistSupplier::visibleToTeam($team)->where('is_inactive', false)->orderBy('name')->get(['id', 'name']) : collect(),
            'lauf' => $lauf,
            'pruefliste' => $lauf !== null ? $svc->pruefliste($lauf, $this->filter !== '' ? $this->filter : null)->take(500) : collect(),
            'xlsxVorlage' => app(PreislistenImportService::class)->xlsxVerfuegbar(),
            'hinweis' => StammdatenImportService::HINWEIS,
            'ziele' => array_values(array_unique(array_column(StammdatenImportService::VORLAGE, 0))),
        ]);
    }
}
