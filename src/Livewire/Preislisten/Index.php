<?php

namespace Platform\FoodAlchemist\Livewire\Preislisten;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\PreislistenImportService;

/**
 * Spec 82 §3 / Schritt 4d, Stufe 2 — Preisliste hochladen (CSV oder Excel), Spalten zuordnen, abgleichen, Prüfliste sehen.
 *
 * Aus Kundensicht: Der Mandant lädt SEINE Preisliste hoch. Dieser Schirm gleicht nur ab; geschrieben wird erst
 * die Schreib-Schicht (Teampreis über den PriceService). Berechtigung: nur das Kunden-Hauptteam mit Rolle Kuratieren
 * ({@see PreislistenImportService::darfImportieren}) — vor Vorschau UND vor dem Abgleich. Die Datei liegt im Livewire-
 * Temp-Verzeichnis (vom System erzeugter Name), es gibt nie einen frei wählbaren Pfad.
 */
class Index extends Component
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $datei = null;

    public ?int $lieferantId = null;

    /** Zielfeld => Spaltenindex der Datei (leer = nicht vorhanden). @var array<string, string> */
    public array $zuordnung = [];

    /** @var array{kopf: list<string>, beispiel: list<list<string>>, zeilen: int}|null */
    public ?array $vorschau = null;

    public ?int $laufId = null;

    /** Zuordnung merken (je Lieferant), wenn eine wartende Datei zugeordnet wird. */
    public bool $merken = true;

    public ?string $fehler = null;

    public ?string $meldung = null;

    /** Zielfelder in Anzeige-Reihenfolge. */
    public const FELDER = [
        'supplier' => 'Lieferant', 'article_number' => 'Artikelnummer', 'ean' => 'EAN', 'designation' => 'Bezeichnung',
        'unit' => 'Gebinde / Einheit', 'price' => 'Preis netto', 'valid_from' => 'Gültig ab',
    ];

    /** Vorlage herunterladen (CSV): Kopf in kanonischer Reihenfolge + eine Beispielzeile — der eigene Import erkennt sie ohne Nachzuordnung. */
    public function vorlageCsv()
    {
        $csv = app(PreislistenImportService::class)->vorlageCsv();

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'preisliste_vorlage.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Vorlage herunterladen (XLSX, nur mit openspout). */
    public function vorlageXlsx()
    {
        $svc = app(PreislistenImportService::class);
        if (! $svc->xlsxVerfuegbar()) {
            $this->fehler = 'Die Excel-Vorlage ist auf diesem Server nicht verfügbar. Bitte die CSV-Vorlage nehmen.';

            return null;
        }
        $pfad = tempnam(sys_get_temp_dir(), 'fa-vorlage-');
        $svc->schreibeVorlageXlsx($pfad);

        return response()->download($pfad, 'preisliste_vorlage.xlsx')->deleteFileAfterSend(true);
    }

    public function updatedDatei(): void
    {
        $this->fehler = $this->meldung = null;
        $this->vorschau = null;
        $team = $this->team();
        if ($team === null || ! $this->darf()) {
            $this->datei = null;
            $this->fehler = 'Preislisten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).';

            return;
        }
        try {
            $this->validate(['datei' => 'required|file|max:10240']);
            $endung = $this->endung();
            $v = app(PreislistenImportService::class)->vorschau($this->datei->getRealPath(), $endung);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->datei = null;
            $this->fehler = $e->validator->errors()->first('datei');

            return;
        } catch (\RuntimeException $e) {
            $this->datei = null;
            $this->fehler = $e->getMessage();

            return;
        }
        $this->vorschau = ['kopf' => $v['kopf'], 'beispiel' => $v['beispiel'], 'zeilen' => $v['zeilen']];
        $this->zuordnung = [];
        foreach (self::FELDER as $feld => $_) {
            $this->zuordnung[$feld] = isset($v['vorschlag'][$feld]) ? (string) $v['vorschlag'][$feld] : '';
        }
    }

    public function abgleichen(): void
    {
        $this->fehler = $this->meldung = null;
        $team = $this->team();
        $user = Auth::user();
        if ($team === null || ! $this->darf()) {
            $this->fehler = 'Preislisten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).';

            return;
        }
        if ($this->datei === null || $this->vorschau === null) {
            $this->fehler = 'Bitte zuerst eine Datei wählen.';

            return;
        }
        $mapping = array_filter($this->zuordnung, fn ($v) => $v !== '' && $v !== null);
        try {
            $run = app(PreislistenImportService::class)->stage(
                $team, $this->datei->getRealPath(), $mapping, $this->lieferantId ?: null, $user?->id,
                $this->endung(), $this->anzeigename(),
            );
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        if ($run->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun::STATUS_ZUORDNUNG_NOETIG) {
            $this->laufWaehlen($run->id);   // Datei wartet: Zuordnung unten ergänzen
            $this->meldung = 'Preis und Artikelnummer oder EAN sind noch nicht zugeordnet — bitte unten zuordnen.';
            $this->datei = null;
            $this->vorschau = null;

            return;
        }
        $this->laufId = $run->id;
        $c = $run->counts ?? [];
        $this->meldung = sprintf('Abgeglichen: %d Zeilen, %d Treffer, %d zu prüfen. Es wurde noch kein Preis geschrieben.',
            $c['zeilen'] ?? 0, $c['treffer'] ?? 0, ($c['zeilen'] ?? 0) - ($c['treffer'] ?? 0));
        $this->datei = null;
        $this->vorschau = null;
        $this->zuordnung = [];
    }

    public function laufWaehlen(int $id): void
    {
        $team = $this->team();
        $lauf = $team !== null ? app(PreislistenImportService::class)->lauf($team, $id) : null;
        $this->laufId = $lauf?->id;
        if ($lauf?->status === \Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun::STATUS_ZUORDNUNG_NOETIG) {
            $this->zuordnung = [];
            foreach ((array) ($lauf->mapping['vorschlag'] ?? []) as $idx => $feld) {   // Spaltenindex ⇒ Feld (Vorschlag der Erkennung)
                $this->zuordnung[(string) $feld] = (string) $idx;
            }
        }
    }

    /** Eigene Datei ohne erkannte Pflichtspalten (1c): gewählte Zuordnung anwenden, Datei abgleichen, Zuordnung je Lieferant merken. */
    public function zuordnen(): void
    {
        $this->fehler = $this->meldung = null;
        $team = $this->team();
        $svc = app(PreislistenImportService::class);
        $lauf = $team !== null && $this->laufId !== null ? $svc->lauf($team, $this->laufId) : null;
        if ($lauf === null || ! $this->darf()) {
            $this->fehler = 'Preislisten importieren darf nur das Haupt-Team des Kunden (Rolle Kuratieren).';

            return;
        }
        $mapping = array_filter($this->zuordnung, fn ($v) => $v !== '' && $v !== null);
        try {
            $neu = $svc->zuordnen($team, $lauf, $mapping, Auth::id(), $this->merken);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->laufId = $neu->id;
        $this->zuordnung = [];
        $c = $neu->counts ?? [];
        $this->meldung = sprintf('Abgeglichen: %d Zeilen, %d Treffer, %d zu prüfen. Es wurde noch kein Preis geschrieben.', $c['zeilen'] ?? 0, $c['treffer'] ?? 0, ($c['zeilen'] ?? 0) - ($c['treffer'] ?? 0));
    }

    /** Treffer eines Laufs als Teampreise übernehmen (Schreib-Schicht). Nur wer importieren darf; Bestätigung kommt aus der Oberfläche (wire:confirm). */
    public function anwenden(int $id): void
    {
        $this->fehler = $this->meldung = null;
        $team = $this->team();
        $svc = app(PreislistenImportService::class);
        $run = $team !== null ? $svc->lauf($team, $id) : null;
        if ($run === null) {
            return;
        }
        try {
            $b = $svc->anwenden($team, Auth::user(), $run);
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->laufId = $run->id;
        $neu = (int) ($b['kette']['neu_berechnet'] ?? 0);
        $this->meldung = sprintf('%s übernommen, %d unverändert, %d noch nicht gültig, %d mit Fehler. %s neu berechnet.',
            $b['angewendet'] === 1 ? '1 Preis' : $b['angewendet'] . ' Preise', $b['unveraendert'], $b['zukuenftig'], $b['fehler'],
            $neu === 1 ? '1 Rezept' : $neu . ' Rezepte');
    }

    public function pruefelisteLaden(int $id)
    {
        $team = $this->team();
        $svc = app(PreislistenImportService::class);
        $run = $team !== null ? $svc->lauf($team, $id) : null;
        if ($run === null) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pruefliste');
        $svc->exportPruefliste($run, $tmp);

        return response()->streamDownload(function () use ($tmp) {
            readfile($tmp);
            @unlink($tmp);
        }, 'pruefliste-' . $run->id . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    /** Anzeigename für den Lauf: Originalname der Datei, nur Basisname, ohne Steuerzeichen, max. 120 Zeichen (reine Anzeige, nie ein Pfad). */
    private function anzeigename(): string
    {
        $roh = (string) $this->datei?->getClientOriginalName();
        $name = basename(str_replace('\\', '/', $roh));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        return $name === '' ? 'preisliste' : mb_substr($name, 0, 120);
    }

    /** Endung der Originaldatei, nur aus der Whitelist. */
    private function endung(): ?string
    {
        $e = strtolower((string) $this->datei?->getClientOriginalExtension());

        return in_array($e, [...PreislistenImportService::ENDUNGEN_CSV, ...PreislistenImportService::ENDUNGEN_EXCEL, 'xls'], true) ? $e : null;
    }

    public function render()
    {
        $team = $this->team();
        $svc = app(PreislistenImportService::class);
        $lauf = $team !== null && $this->laufId !== null ? $svc->lauf($team, $this->laufId) : null;

        return view('foodalchemist::livewire.preislisten.index', [
            'darf' => $this->darf(),
            'laeufe' => $team !== null ? $svc->laeufe($team)->where('art', 'preisliste')->values() : collect(),
            'lieferanten' => $team !== null ? FoodAlchemistSupplier::visibleToTeam($team)->where('is_inactive', false)->orderBy('name')->get(['id', 'name']) : collect(),
            'lauf' => $lauf,
            'pruefliste' => $lauf !== null ? $svc->pruefliste($lauf)->take(500) : collect(),
            'felder' => self::FELDER,
            'xlsxVorlage' => $svc->xlsxVerfuegbar(),
            'vorlageHinweis' => PreislistenImportService::VORLAGE_HINWEIS,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
