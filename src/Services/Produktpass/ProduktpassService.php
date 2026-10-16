<?php

namespace Platform\FoodAlchemist\Services\Produktpass;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Services\ContextFileService;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\ProduktpassLesenJob;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpassFeld;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\ProduktpassFelder;
use Platform\FoodAlchemist\Support\ProduktpassSchutz;
use Platform\FoodAlchemist\Support\Warteschlange;
use Throwable;

/**
 * Produktpass am Lieferantenartikel (Bauauftrag Kurator 16.10.2026) — der GEMEINSAME Teil für Produktpass UND Websuche:
 * Vorschläge (Feld | Wert | Konfidenz | Quelle), Übernahme, Allergen-Bestätigung, Marker gegen den Necta-Abgleich.
 *
 * Regeln (Entscheide Dominique):
 *  - Quelle `produktpass`: Nährwerte, Zutatenliste, Zusatzstoffe, Haltbarkeit (mit Lagerart) trägt die KI DIREKT ein, wenn
 *    die Konfidenz ≥ {@see ProduktpassFelder::KONFIDENZ_DIREKT} ist; Gebinde nur in LEERE Felder (qty ist die Preisbasis);
 *    Herkunft/Marke nur als Vorschlag. Alter Wert wird festgehalten (`uebernommen`).
 *  - ALLERGENE nie direkt: `allergene_status=vorschlag`, bis jemand bestätigt (Klick; per MCP nur Admin). Bis dahin bleibt
 *    der alte Allergenstand unangetastet.
 *  - Quelle `websuche`: schreibt NIE direkt. Übernahme nur per Klick und nur in LEERE Felder; Allergene nur mit Bestätigung
 *    und nur dort, wo der Artikel noch „unbekannt“ ist; jedes Feld hat eine URL (Normalisierer).
 *  - Übernommene Felder bekommen einen Marker ({@see ProduktpassSchutz}): der Necta-Abgleich überschreibt sie nicht, er meldet
 *    Abweichungen als Prüffall. Strengere Allergene aus Necta kommen trotzdem (Strenge-Regel, #394).
 *  - Rechte wie beim Bearbeiten: `Curate`/{@see FoodAlchemistSupplierItem::isOwnedBy()} — Master an globalen Stamm-Artikeln,
 *    Mandant an eigenen. Sehen darf jeder, dem der Artikel sichtbar ist.
 */
class ProduktpassService
{
    public const ANHANG_MIMES = FoodAlchemistProduktpass::MIMES;

    public function __construct(private FaRechte $rechte)
    {
    }

    // ── Versionen + Datei ───────────────────────────────────────────────────

    /** @return Collection<int,FoodAlchemistProduktpass> neueste zuerst */
    public function versionen(FoodAlchemistSupplierItem $item): Collection
    {
        return FoodAlchemistProduktpass::where('supplier_item_id', $item->id)->where('status', '!=', 'verworfen')
            ->orderByDesc('version')->get();
    }

    /** Datei hochladen, neue Version anlegen und das Lesen in die Warteschlange stellen. */
    public function hochladen(Team $team, FoodAlchemistSupplierItem $item, UploadedFile $file, ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Produktpass hochladen');
        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, self::ANHANG_MIMES, true)) {
            throw new \RuntimeException('Bitte ein PDF oder ein Foto (JPG, PNG, WebP) hochladen.');
        }
        if ($file->getSize() > FoodAlchemistProduktpass::MAX_KB * 1024) {
            throw new \RuntimeException('Die Datei ist größer als 15 MB.');
        }

        $besitzer = $this->besitzerTeamId($team, $item);
        $pass = FoodAlchemistProduktpass::create([
            'team_id' => $besitzer, 'supplier_item_id' => $item->id, 'version' => $this->naechsteVersion($item),
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255), 'mime' => $mime,
            'groesse_kb' => (int) ceil($file->getSize() / 1024), 'status' => 'hochgeladen', 'quelle' => ProduktpassFelder::QUELLE_PASS,
            'hochgeladen_von' => $userId,
        ]);
        try {
            $res = app(ContextFileService::class)->uploadForContext($file, 'foodalchemist.produktpass', (int) $pass->id, [
                'team_id' => $besitzer, 'user_id' => $userId, 'folder' => 'foodalchemist/produktpaesse/'.(int) $item->id,
                'keep_original' => true, 'generate_variants' => false,
            ]);
            $pass->update(['context_file_id' => (int) $res['id']]);
        } catch (Throwable $e) {
            $pass->forceDelete();
            throw new \RuntimeException('Die Datei konnte nicht gespeichert werden: '.$e->getMessage());
        }
        ProduktpassLesenJob::dispatch((int) $pass->id)->onQueue(Warteschlange::anreichern());

        return $pass->refresh();
    }

    public function downloadUrl(FoodAlchemistProduktpass $pass): ?string
    {
        return $pass->context_file_id ? app(ContextFileService::class)->getDownloadUrl((int) $pass->context_file_id) : null;
    }

    /** Fehlgeschlagene/alte Version noch einmal lesen lassen. */
    public function erneutLesen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null): FoodAlchemistProduktpass
    {
        $item = $pass->item()->firstOrFail();
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Produktpass erneut lesen');
        if ($pass->context_file_id === null) {
            throw new \RuntimeException('Zu diesem Vorschlag gibt es keine Datei zum Lesen.');
        }
        $pass->update(['status' => 'hochgeladen', 'fehler' => null]);
        ProduktpassLesenJob::dispatch((int) $pass->id)->onQueue(Warteschlange::anreichern());

        return $pass->refresh();
    }

    public function verwerfen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null): void
    {
        $this->pruefeSchreiben($team, $pass->item()->firstOrFail(), $userId, FaRolle::Kuratieren, 'Produktpass verwerfen');
        $pass->update(['status' => 'verworfen']);
    }

    // ── Lesen (Job) ─────────────────────────────────────────────────────────

    /**
     * Die Datei lesen lassen, Extrakt speichern und die direkten Felder übernehmen. Läuft im Job
     * ({@see ProduktpassLesenJob}); ein Fehler setzt die Version auf `fehler` (nie ein Absturz, nie ein halber Zustand).
     */
    public function lesen(FoodAlchemistProduktpass $pass): FoodAlchemistProduktpass
    {
        $pass->update(['status' => 'liest', 'fehler' => null]);
        try {
            $item = $pass->item()->firstOrFail();
            $team = $pass->team_id ? Team::find($pass->team_id) : null;
            if ($team !== null) {
                if (! app(TeamSettingsService::class)->kiAktiv($team)) {
                    throw new \RuntimeException('Die KI ist für dieses Team ausgeschaltet.');
                }
                if ($this->rechte->kiBudgetErschoepft($team)) {
                    throw new \RuntimeException('Das KI-Budget dieses Monats ist aufgebraucht.');
                }
            }
            $extrakt = app(ProduktpassLeser::class)->liefere($item, ['produktpass' => $pass]);
            $pass->update([
                'extrakt' => $extrakt, 'status' => 'gelesen', 'gelesen_at' => now(), 'ki_modell' => $extrakt['modell'] ?? null,
                'allergene_status' => ($extrakt['allergene'] ?? []) !== [] || ($extrakt['allergene_details'] ?? []) !== [] ? 'vorschlag' : 'keine',
            ]);
            $this->anwenden($pass->refresh(), false);
        } catch (Throwable $e) {
            $pass->update(['status' => 'fehler', 'fehler' => mb_substr($e->getMessage(), 0, 500)]);
            Log::warning('Produktpass lesen fehlgeschlagen', ['produktpass_id' => $pass->id, 'fehler' => $e->getMessage()]);
        }

        return $pass->refresh();
    }

    /**
     * Vorschlag aus einer beliebigen {@see ProduktdatenQuelle} (z. B. Websuche) anlegen — ohne Datei, ohne Schreiben am Artikel.
     * Alles Weitere (Übernehmen, Allergene bestätigen) läuft über dieselben Methoden wie beim Produktpass.
     */
    public function vorschlagAusQuelle(Team $team, FoodAlchemistSupplierItem $item, ProduktdatenQuelle $quelle, array $optionen = [], ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Vorschlag recherchieren');
        $roh = $quelle->liefere($item, $optionen);
        $extrakt = ProduktpassNormalisierer::normalisiere($roh, $quelle->name());

        return FoodAlchemistProduktpass::create([
            'team_id' => $this->besitzerTeamId($team, $item), 'supplier_item_id' => $item->id, 'version' => $this->naechsteVersion($item),
            'status' => 'gelesen', 'quelle' => $quelle->name(), 'extrakt' => $extrakt, 'gelesen_at' => now(),
            'allergene_status' => $extrakt['allergene'] !== [] || $extrakt['allergene_details'] !== [] ? 'vorschlag' : 'keine',
            'hochgeladen_von' => $userId, 'ki_modell' => is_string($roh['modell'] ?? null) ? $roh['modell'] : null,
        ])->refresh();
    }

    // ── Übernahme ───────────────────────────────────────────────────────────

    /** Eine ganze Version ausdrücklich übernehmen (die direkten Felder nach den Regeln, auch wenn eine ältere Version schon wirkt). */
    public function versionUebernehmen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $pass->item()->firstOrFail(), $userId, FaRolle::Kuratieren, 'Produktpass übernehmen');
        if ($pass->status !== 'gelesen') {
            throw new \RuntimeException('Nur eine gelesene Version lässt sich übernehmen.');
        }
        $this->anwenden($pass, true);

        return $pass->refresh();
    }

    /** Ein einzelnes Feld per Klick übernehmen (auch ein Vorschlag mit niedriger Konfidenz — die Person entscheidet). */
    public function feldUebernehmen(Team $team, FoodAlchemistProduktpass $pass, string $key, ?int $userId = null): FoodAlchemistProduktpass
    {
        $item = $pass->item()->firstOrFail();
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Feld aus dem Produktpass übernehmen');
        $eintrag = $pass->extrakt['felder'][$key] ?? null;
        $z = ProduktpassFelder::ziel($key);
        if ($eintrag === null || $z === null) {
            throw new \RuntimeException('Dieses Feld steht nicht im Vorschlag.');
        }
        if ($z['spalte'] === '') {
            throw new \RuntimeException('Die Lagerart fehlt im Dokument — bitte die Haltbarkeit von Hand der richtigen Lagerart zuordnen.');
        }
        $web = $pass->quelle === ProduktpassFelder::QUELLE_WEB;
        $wert = $eintrag['wert'];
        $abgeleitet = false;
        if ($key === 'naehrwerte.salz') {
            $wert = round((float) $wert * ProduktpassFelder::SALZ_ZU_NATRIUM_MG, 4);
            $abgeleitet = true;
        }
        if ($web && ! $this->zielLeer($item, $z)) {
            throw new \RuntimeException('Aus der Websuche nur in leere Felder — dieses Feld hat schon einen Wert.');
        }
        $uebernommen = $pass->uebernommen ?? [];
        if ($key === 'gebinde.qty') {
            $einheit = $this->gebindeEinheit($item, $pass->extrakt['felder'] ?? [], false);
            if ($einheit === null) {
                throw new \RuntimeException('Die Einheit der Gebinde-Menge fehlt (kg, l oder Stk) — bitte zuerst am Artikel setzen.');
            }
            if (($item->unit_code === null || $item->unit_code === '')) {
                $this->schreibeFeld($item, 'gebinde.unit_code', ['objekt' => 'supplier_item', 'spalte' => 'unit_code', 'typ' => 'text'], $einheit, $eintrag, $pass, false, $uebernommen);
            } elseif ($item->unit_code !== $einheit) {
                throw new \RuntimeException("Einheit passt nicht: am Artikel steht {$item->unit_code}, im Dokument {$einheit}.");
            }
        }
        $this->schreibeFeld($item, $key, $z, $wert, $eintrag, $pass, $abgeleitet, $uebernommen);
        $pass->update(['uebernommen' => $uebernommen, 'uebernommen_at' => now()]);

        return $pass->refresh();
    }

    /**
     * Allergene aus dem Vorschlag übernehmen — NUR nach ausdrücklicher Bestätigung. `$mindestens` = Admin für MCP, sonst
     * Kuratieren (Klick im Modal). Pass: alle gelesenen Allergene (auch lockerer als bisher — die Person bestätigt);
     * Websuche: nur Allergene, die am Artikel noch „unbekannt“ sind.
     */
    public function allergeneBestaetigen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null, FaRolle $mindestens = FaRolle::Kuratieren): FoodAlchemistProduktpass
    {
        $item = $pass->item()->firstOrFail();
        $this->pruefeSchreiben($team, $item, $userId, $mindestens, 'Allergene aus dem Produktpass bestätigen');
        if ($pass->allergene_status !== 'vorschlag') {
            throw new \RuntimeException('Es gibt keinen offenen Allergen-Vorschlag.');
        }
        $web = $pass->quelle === ProduktpassFelder::QUELLE_WEB;
        $quelle = $web ? ProduktpassFelder::QUELLE_WEB : FoodAlchemistProduktpassFeld::QUELLE;
        $zeile = FoodAlchemistItemAllergen::firstOrNew(['supplier_item_id' => $item->id]);
        if (! $zeile->exists) {
            $zeile->team_id = $item->team_id;
        }
        $alt = [];
        $neu = [];
        foreach ((array) ($pass->extrakt['allergene'] ?? []) as $key => $e) {
            $spalte = "allergen_{$key}";
            $bisher = $zeile->{$spalte};
            if ($web && $bisher !== null && $bisher !== '') {
                continue;   // Websuche füllt nur unbekannte Allergene
            }
            $alt[$key] = $bisher;
            $neu[$key] = $e['wert'];
            $zeile->{$spalte} = $e['wert'];
            $this->setzeMarker($item, $pass, 'item_allergen', $spalte, $e['wert'], false, $e['konfidenz'] ?? null, $quelle);
        }
        $details = (array) ($zeile->details ?? []);
        foreach ((array) ($pass->extrakt['allergene_details'] ?? []) as $unterart => $e) {
            if ($web && isset($details[$unterart])) {
                continue;
            }
            $details[$unterart] = $e['wert'];
        }
        ksort($details);
        $zeile->details = $details === [] ? null : $details;
        $zeile->source = mb_substr($quelle, 0, 16);
        $zeile->save();
        $uebernommen = $pass->uebernommen ?? [];
        $uebernommen['allergene'] = ['alt' => $alt, 'neu' => $neu, 'details' => $details];
        $pass->update([
            'uebernommen' => $uebernommen, 'allergene_status' => 'bestaetigt', 'allergene_bestaetigt_von' => $userId,
            'allergene_bestaetigt_at' => now(), 'uebernommen_at' => now(),
        ]);
        ProduktpassSchutz::zuruecksetzen();

        return $pass->refresh();
    }

    public function allergeneVerwerfen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $pass->item()->firstOrFail(), $userId, FaRolle::Kuratieren, 'Allergen-Vorschlag verwerfen');
        if ($pass->allergene_status === 'vorschlag') {
            $pass->update(['allergene_status' => 'verworfen']);
        }

        return $pass->refresh();
    }

    // ── Anzeige / MCP ───────────────────────────────────────────────────────

    /**
     * Stand eines Artikels: Versionen + der Vorschlag der jüngsten gelesenen Version (Feld | Wert | Konfidenz | übernommen?)
     * samt aktuellem FA-Wert und Allergen-Vorschlag mit Gegenüberstellung.
     *
     * @return array<string,mixed>
     */
    public function stand(FoodAlchemistSupplierItem $item, ?FoodAlchemistProduktpass $pass = null): array
    {
        $versionen = $this->versionen($item);
        $pass ??= $versionen->firstWhere('status', 'gelesen') ?? $versionen->first();
        $felder = [];
        if ($pass !== null) {
            $uebernommen = $pass->uebernommen ?? [];
            foreach ((array) ($pass->extrakt['felder'] ?? []) as $key => $e) {
                $z = ProduktpassFelder::ziel($key);
                if ($z === null) {
                    continue;
                }
                $felder[] = [
                    'key' => $key, 'label' => $z['label'], 'wert' => $e['wert'], 'einheit' => $e['einheit'] ?? null,
                    'konfidenz' => $e['konfidenz'] ?? 0.0, 'url' => $e['url'] ?? null, 'zitat' => $e['zitat'] ?? null,
                    'status' => isset($uebernommen[$key]) ? 'uebernommen' : 'vorschlag',
                    'aktuell' => $z['spalte'] === '' ? null : $this->aktuellerWert($item, $z),
                    'uebernehmbar' => $z['spalte'] !== '',
                ];
            }
        }
        $allergene = [];
        if ($pass !== null) {
            $row = $item->allergens;
            foreach ((array) ($pass->extrakt['allergene'] ?? []) as $key => $e) {
                $allergene[] = ['key' => $key, 'label' => FoodAlchemistItemAllergen::ALLERGENE[$key] ?? $key, 'vorschlag' => $e['wert'],
                    'konfidenz' => $e['konfidenz'] ?? 0.0, 'aktuell' => $row?->{"allergen_{$key}"} ?? 'unbekannt', 'url' => $e['url'] ?? null];
            }
        }

        return [
            'versionen' => $versionen->map(fn (FoodAlchemistProduktpass $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'quelle' => $v->quelle, 'datei' => $v->original_name,
                'datum' => $v->created_at?->toDateString(), 'allergene_status' => $v->allergene_status, 'fehler' => $v->fehler,
            ])->all(),
            'angezeigt' => $pass === null ? null : ['id' => $pass->id, 'version' => $pass->version, 'status' => $pass->status, 'quelle' => $pass->quelle,
                'allergene_status' => $pass->allergene_status, 'ki_modell' => $pass->ki_modell, 'verworfen_felder' => $pass->extrakt['verworfen'] ?? []],
            'felder' => $felder,
            'allergene' => $allergene,
            'allergene_details' => $pass === null ? [] : (array) ($pass->extrakt['allergene_details'] ?? []),
            'quellen_der_felder' => FoodAlchemistProduktpassFeld::where('supplier_item_id', $item->id)
                ->get(['objekt', 'feld', 'quelle', 'abgeleitet'])->map(fn ($f) => $f->only(['objekt', 'feld', 'quelle', 'abgeleitet']))->all(),
        ];
    }

    // ── intern ──────────────────────────────────────────────────────────────

    /**
     * Die Felder der Version nach den Regeln direkt übernehmen. Ohne `$explizit` läuft das nur, wenn dieser Artikel noch
     * keinen Marker einer ANDEREN Version hat — eine neue Version ersetzt ältere Werte erst auf ausdrücklichen Befehl.
     */
    private function anwenden(FoodAlchemistProduktpass $pass, bool $explizit): void
    {
        if ($pass->quelle !== ProduktpassFelder::QUELLE_PASS) {
            return;   // Websuche schreibt nie direkt
        }
        if (! $explizit && FoodAlchemistProduktpassFeld::where('supplier_item_id', $pass->supplier_item_id)
            ->where('produktpass_id', '!=', $pass->id)->exists()) {
            return;
        }
        $item = $pass->item()->firstOrFail();
        $felder = (array) ($pass->extrakt['felder'] ?? []);
        $uebernommen = $pass->uebernommen ?? [];
        $schwelle = ProduktpassFelder::KONFIDENZ_DIREKT;

        $abgeleitet = [];
        if (isset($felder['naehrwerte.salz']) && ! isset($felder['naehrwerte.sodium'])) {
            $felder['naehrwerte.sodium'] = ['wert' => round((float) $felder['naehrwerte.salz']['wert'] * ProduktpassFelder::SALZ_ZU_NATRIUM_MG, 4)]
                + $felder['naehrwerte.salz'];
            $abgeleitet['naehrwerte.sodium'] = true;
        }
        unset($felder['naehrwerte.salz']);

        foreach ($felder as $key => $e) {
            $z = ProduktpassFelder::ziel($key);
            if ($z === null || $z['klasse'] === 'vorschlag' || $z['spalte'] === '' || ($e['konfidenz'] ?? 0.0) < $schwelle) {
                continue;
            }
            if ($key === 'gebinde.unit_code') {
                continue;   // nur zusammen mit gebinde.qty
            }
            if ($z['klasse'] === 'nur_leer' && ! $this->zielLeer($item, $z)) {
                continue;
            }
            if ($key === 'gebinde.qty') {
                $einheit = $this->gebindeEinheit($item, $felder, true);
                if ($einheit === null) {
                    continue;   // ohne gesicherte Einheit keine Menge (Preisfalle)
                }
                if ($item->unit_code === null || $item->unit_code === '') {
                    $this->schreibeFeld($item, 'gebinde.unit_code', ['objekt' => 'supplier_item', 'spalte' => 'unit_code', 'typ' => 'text'], $einheit, $felder['gebinde.unit_code'], $pass, false, $uebernommen);
                }
            }
            $this->schreibeFeld($item, $key, $z, $e['wert'], $e, $pass, $abgeleitet[$key] ?? false, $uebernommen);
        }
        $pass->update(['uebernommen' => $uebernommen, 'uebernommen_at' => $uebernommen === [] ? null : now()]);
    }

    /**
     * Einheit zur Gebinde-Menge: die des Dokuments (`gebinde.unit_code`, gültig), bei vorhandener Artikel-Einheit muss sie
     * übereinstimmen. `$streng`: das Dokument MUSS eine Einheit mit Konfidenz ≥ Schwelle nennen (automatische Übernahme).
     */
    private function gebindeEinheit(FoodAlchemistSupplierItem $item, array $felder, bool $streng): ?string
    {
        $dok = $felder['gebinde.unit_code'] ?? null;
        $einheit = $dok !== null ? $this->einheitNorm((string) $dok['wert']) : null;
        if ($streng && ($einheit === null || ($dok['konfidenz'] ?? 0.0) < ProduktpassFelder::KONFIDENZ_DIREKT)) {
            return null;
        }
        $hat = $item->unit_code !== null && $item->unit_code !== '' ? $this->einheitNorm((string) $item->unit_code) : null;
        if ($einheit !== null && $hat !== null && $einheit !== $hat) {
            return null;
        }

        return $einheit ?? $hat;
    }

    private function einheitNorm(string $roh): ?string
    {
        return match (mb_strtolower(trim($roh))) {
            'kg' => 'kg', 'l', 'liter' => 'l', 'stk', 'stück', 'stueck', 'st' => 'Stk',
            default => null,
        };
    }

    private function zielLeer(FoodAlchemistSupplierItem $item, array $z): bool
    {
        $wert = $item->{$z['spalte']} ?? null;
        if ($z['typ'] === 'zahl') {
            return $wert === null || (float) $wert <= 0.0;
        }

        return $wert === null || trim((string) $wert) === '';
    }

    /** Schreibt EIN Feld (Artikel, Nährwerte oder Zusatzstoffe), setzt Marker und hält den alten Wert in `$uebernommen`. */
    private function schreibeFeld(FoodAlchemistSupplierItem $item, string $key, array $z, mixed $wert, array $eintrag, FoodAlchemistProduktpass $pass, bool $abgeleitet, array &$uebernommen): void
    {
        $quelle = $pass->quelle === ProduktpassFelder::QUELLE_WEB ? ProduktpassFelder::QUELLE_WEB : FoodAlchemistProduktpassFeld::QUELLE;
        $spalte = $z['spalte'];
        DB::transaction(function () use ($item, $key, $z, $wert, $eintrag, $pass, $abgeleitet, $quelle, $spalte, &$uebernommen): void {
            switch ($z['objekt']) {
                case 'supplier_item':
                    $alt = $item->{$spalte};
                    $neu = match ($z['typ']) {
                        'tage' => (int) $wert, 'zahl' => (float) $wert, default => (string) $wert,
                    };
                    $item->update([$spalte => $neu]);
                    break;
                case 'item_nutritional':
                    $zeile = FoodAlchemistItemNutritional::firstOrNew(['supplier_item_id' => $item->id]);
                    $zeile->team_id ??= $item->team_id;
                    $alt = $zeile->{$spalte};
                    $neu = (float) $wert;
                    $zeile->{$spalte} = $neu;
                    $zeile->source = mb_substr($quelle, 0, 16);
                    $zeile->save();
                    break;
                case 'item_declaration':
                    $zeile = FoodAlchemistItemDeclaration::firstOrNew(['supplier_item_id' => $item->id]);
                    $zeile->team_id ??= $item->team_id;
                    $alt = $zeile->{$spalte};
                    $neu = $wert === 'ja' ? 3 : 1;
                    $zeile->{$spalte} = $neu;
                    $zeile->source = mb_substr($quelle, 0, 16);
                    $zeile->save();
                    break;
                default:
                    throw new \RuntimeException("Unbekanntes Ziel {$z['objekt']}.");
            }
            $this->setzeMarker($item, $pass, $z['objekt'], $spalte, $neu, $abgeleitet, $eintrag['konfidenz'] ?? null, $quelle);
            $uebernommen[$key] = ['spalte' => $spalte, 'alt' => $alt, 'neu' => $neu, 'abgeleitet' => $abgeleitet, 'konfidenz' => $eintrag['konfidenz'] ?? null];
        });
        ProduktpassSchutz::zuruecksetzen();
    }

    private function setzeMarker(FoodAlchemistSupplierItem $item, FoodAlchemistProduktpass $pass, string $objekt, string $feld, mixed $wert, bool $abgeleitet, ?float $konfidenz, string $quelle): void
    {
        FoodAlchemistProduktpassFeld::updateOrCreate(
            ['supplier_item_id' => $item->id, 'objekt' => $objekt, 'feld' => $feld],
            ['produktpass_id' => $pass->id, 'wert_norm' => ProduktpassFelder::norm($wert), 'quelle' => $quelle, 'abgeleitet' => $abgeleitet, 'konfidenz' => $konfidenz],
        );
    }

    /** Aktueller FA-Wert zum Feld (für die Gegenüberstellung): Zahl/Text, bei Zusatzstoffen ja|nein|null. */
    private function aktuellerWert(FoodAlchemistSupplierItem $item, array $z): mixed
    {
        $spalte = $z['spalte'];
        switch ($z['objekt']) {
            case 'item_nutritional':
                $v = $item->nutritionals?->{$spalte};

                return $v === null ? null : (float) $v;
            case 'item_declaration':
                return match ((int) ($item->declarations?->{$spalte} ?? 0)) {
                    3 => 'ja', 1 => 'nein', default => null,
                };
            default:
                $v = $item->{$spalte} ?? null;

                return $v === '' ? null : $v;
        }
    }

    /** Rechte wie beim Bearbeiten: Rolle im Team + der Artikel gehört diesem Team (Master: globale Stamm-Artikel). */
    private function pruefeSchreiben(Team $team, FoodAlchemistSupplierItem $item, ?int $userId, FaRolle $mindestens, string $wofuer): void
    {
        $this->rechte->pruefeId($userId, $team, $mindestens, $wofuer);
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Dieser Artikel kommt aus einem übergeordneten Katalog. Den Produktpass pflegen kann nur das Team, dem er gehört.');
        }
    }

    private function besitzerTeamId(Team $team, FoodAlchemistSupplierItem $item): int
    {
        return (int) ($item->team_id ?? MasterTeam::id() ?? $team->id);
    }

    private function naechsteVersion(FoodAlchemistSupplierItem $item): int
    {
        return (int) FoodAlchemistProduktpass::withTrashed()->where('supplier_item_id', $item->id)->max('version') + 1;
    }
}
