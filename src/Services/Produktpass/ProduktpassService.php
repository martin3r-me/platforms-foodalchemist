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
use Platform\FoodAlchemist\Support\ProduktpassGebinde;
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
 *  - Übernommene Felder bekommen einen Marker ({@see ProduktpassSchutz}). Kerngruppen (Allergene samt Unterarten, Zusatzstoffe,
 *    Nährwerte, Zutatenliste, Gebinde) mit Marker aus dem Dokument: der Pass-Wert hat Vorrang vor Necta, auch vor einem strengeren — Abweichung → Prüffall + Signal
 *    ({@see ProduktpassAbweichungService}, Entscheid Dominique 16.10.2026). Übrige Felder kommen aus Necta.
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
        ProduktpassLesenJob::dispatch((int) $pass->id)->onQueue(Warteschlange::interaktiv());

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
        ProduktpassLesenJob::dispatch((int) $pass->id)->onQueue(Warteschlange::interaktiv());

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
                'allergene_status' => $this->hatKennzeichnung($extrakt) ? 'vorschlag' : 'keine',
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
    /**
     * Web-Recherche im Hintergrund (Befund io 19.10.: der Aufruf dauert länger als das FPM-Timeout von 60 s — der Request starb still).
     * Legt eine Version im Status `liest` an und stellt {@see \Platform\FoodAlchemist\Jobs\ProduktpassRechercheJob} auf die interaktive
     * Schlange; das Panel pollt wie beim Hochladen. Das Recht wird hier geprüft, nicht erst im Job.
     */
    public function rechercheStarten(Team $team, FoodAlchemistSupplierItem $item, ProduktdatenQuelle $quelle, ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Vorschlag recherchieren');
        $pass = FoodAlchemistProduktpass::create([
            'team_id' => $this->besitzerTeamId($team, $item), 'supplier_item_id' => $item->id, 'version' => $this->naechsteVersion($item),
            'status' => 'liest', 'quelle' => $quelle->name(), 'hochgeladen_von' => $userId,
        ]);
        \Platform\FoodAlchemist\Jobs\ProduktpassRechercheJob::dispatch((int) $pass->id, $quelle->name())->onQueue(Warteschlange::interaktiv());

        return $pass->refresh();
    }

    /** Job-Rumpf der Web-Recherche: Vorschlag schreiben oder Status `fehler` mit lesbarer Meldung — nie eine Ausnahme nach außen. */
    public function rechercheAusfuehren(FoodAlchemistProduktpass $pass, ProduktdatenQuelle $quelle): void
    {
        if ($pass->status !== 'liest') {
            return;   // verworfen oder schon fertig (doppelter Dispatch)
        }
        try {
            $roh = $quelle->liefere($pass->item()->firstOrFail());
            $extrakt = ProduktpassNormalisierer::normalisiere($roh, $quelle->name());
            $pass->update(['status' => 'gelesen', 'extrakt' => $extrakt, 'gelesen_at' => now(), 'fehler' => null,
                'allergene_status' => $this->hatKennzeichnung($extrakt) ? 'vorschlag' : 'keine',
                'ki_modell' => is_string($roh['modell'] ?? null) ? $roh['modell'] : null]);
        } catch (Throwable $e) {
            $meldung = $e instanceof \RuntimeException ? $e->getMessage() : 'Die Web-Recherche ist fehlgeschlagen.';
            $pass->update(['status' => 'fehler', 'fehler' => mb_substr($meldung, 0, 500)]);
            Log::warning('Produktpass Web-Recherche fehlgeschlagen', ['produktpass_id' => $pass->id, 'fehler' => $e->getMessage(), 'klasse' => $e::class]);
        }
    }

    public function vorschlagAusQuelle(Team $team, FoodAlchemistSupplierItem $item, ProduktdatenQuelle $quelle, array $optionen = [], ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Vorschlag recherchieren');
        $roh = $quelle->liefere($item, $optionen);
        $extrakt = ProduktpassNormalisierer::normalisiere($roh, $quelle->name());

        return FoodAlchemistProduktpass::create([
            'team_id' => $this->besitzerTeamId($team, $item), 'supplier_item_id' => $item->id, 'version' => $this->naechsteVersion($item),
            'status' => 'gelesen', 'quelle' => $quelle->name(), 'extrakt' => $extrakt, 'gelesen_at' => now(),
            'allergene_status' => $this->hatKennzeichnung($extrakt) ? 'vorschlag' : 'keine',
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
        $uebernommen = $pass->uebernommen ?? [];
        $this->schreibeVorschlag($item, $pass, $key, $uebernommen);
        $pass->update(['uebernommen' => $uebernommen, 'uebernommen_at' => now()]);

        return $pass->refresh();
    }

    /**
     * Vorschau für „Alle Vorschläge übernehmen": welche sicheren (≥ Schwelle) Nicht-Kennzeichnungs-Vorschläge in LEERE Felder gehen
     * und welche einen vorhandenen Wert überschreiben würden (braucht eine Bestätigung). Quelle Websuche: nie überschreiben.
     *
     * @return array{leer:list<string>,abweichend:list<string>,uebersprungen:list<string>,gleichwertig:int}
     */
    public function uebernahmeVorschau(FoodAlchemistSupplierItem $item, FoodAlchemistProduktpass $pass): array
    {
        $web = $pass->quelle === ProduktpassFelder::QUELLE_WEB;
        $uebernommen = $pass->uebernommen ?? [];
        $aus = ['leer' => [], 'abweichend' => [], 'uebersprungen' => [], 'gleichwertig' => 0];
        foreach ($this->vorschlagsFelder($item, $pass) as $key => $e) {
            $z = ProduktpassFelder::ziel($key);
            if ($z === null || $z['klasse'] === 'kennzeichnung' || $z['spalte'] === '' || ($e['konfidenz'] ?? 0.0) < ProduktpassFelder::KONFIDENZ_DIREKT
                || isset($uebernommen[$key]) || ($key === 'gebinde.menge' && isset($uebernommen['gebinde.qty']))) {
                continue;
            }
            if ($e['gleichwertig'] ?? false) {
                $aus['gleichwertig']++;

                continue;
            }
            $leer = $key === 'gebinde.menge' ? ProduktpassGebinde::artikelLeer($item) : $this->zielLeer($item, $z);
            if ($leer) {
                $aus['leer'][] = $key;
            } elseif ($web || $key === 'verpackungshinweis') {
                $aus['uebersprungen'][] = $key;   // Websuche überschreibt nie; ein vorhandener Hinweis wird von Hand ergänzt
            } else {
                $aus['abweichend'][] = $key;
            }
        }

        return $aus;
    }

    /**
     * „Alle Vorschläge übernehmen": die sicheren Nicht-Kennzeichnungs-Vorschläge (Allergene und Zusatzstoffe nie — die gehen über die
     * Bestätigung). Leere Felder sofort; Abweichungen von vorhandenen Werten nur mit `$mitAbweichungen` (die Oberfläche fragt vorher).
     *
     * @return array{uebernommen:list<string>,abweichend_offen:list<string>,fehler:array<string,string>}
     */
    public function alleUebernehmen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null, bool $mitAbweichungen = false): array
    {
        $item = $pass->item()->firstOrFail();
        $this->pruefeSchreiben($team, $item, $userId, FaRolle::Kuratieren, 'Vorschläge aus dem Produktpass übernehmen');
        $vorschau = $this->uebernahmeVorschau($item, $pass);
        $uebernommen = $pass->uebernommen ?? [];
        $bericht = ['uebernommen' => [], 'abweichend_offen' => $mitAbweichungen ? [] : $vorschau['abweichend'], 'fehler' => []];
        foreach ([...$vorschau['leer'], ...($mitAbweichungen ? $vorschau['abweichend'] : [])] as $key) {
            try {
                $this->schreibeVorschlag($item->refresh(), $pass, $key, $uebernommen);
                $bericht['uebernommen'][] = $key;
            } catch (\RuntimeException $e) {
                $bericht['fehler'][$key] = $e->getMessage();
            }
        }
        $pass->update(['uebernommen' => $uebernommen, 'uebernommen_at' => $bericht['uebernommen'] === [] ? $pass->uebernommen_at : now()]);

        return $bericht;
    }

    /**
     * Kennzeichnung bestätigen (Entscheid Dominique 16.10.2026): ALLERGENE und ZUSATZSTOFFE aus dem Vorschlag zusammen eintragen — nur nach
     * ausdrücklicher Bestätigung. `$mindestens` = Admin für MCP, sonst Kuratieren (Klick im Modal). Pass: alle gelesenen Allergene (auch lockerer als
     * bisher — die Person bestätigt); Websuche: nur, was am Artikel noch „unbekannt“ ist. Zusatzstoffe, die früher schon direkt übernommen wurden
     * (vor dieser Regel), bleiben stehen und werden nicht angefasst.
     */
    public function kennzeichnungBestaetigen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null, FaRolle $mindestens = FaRolle::Kuratieren): FoodAlchemistProduktpass
    {
        $item = $pass->item()->firstOrFail();
        $this->pruefeSchreiben($team, $item, $userId, $mindestens, 'Kennzeichnung aus dem Produktpass bestätigen');
        if ($pass->allergene_status !== 'vorschlag') {
            throw new \RuntimeException('Es gibt keinen offenen Kennzeichnungs-Vorschlag.');
        }
        $web = $pass->quelle === ProduktpassFelder::QUELLE_WEB;
        $quelle = $web ? ProduktpassFelder::QUELLE_WEB : FoodAlchemistProduktpassFeld::QUELLE;
        $uebernommen = $pass->uebernommen ?? [];
        $hatAllergene = (array) ($pass->extrakt['allergene'] ?? []) !== [] || (array) ($pass->extrakt['allergene_details'] ?? []) !== [];

        if ($hatAllergene) {
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
            $unterarten = (array) ($pass->extrakt['allergene_details'] ?? []);
            foreach ($unterarten as $unterart => $e) {
                if ($web && isset($details[$unterart])) {
                    continue;
                }
                $details[$unterart] = $e['wert'];
            }
            ksort($details);
            $zeile->details = $details === [] ? null : $details;
            $zeile->source = mb_substr($quelle, 0, 16);
            $zeile->save();
            if ($unterarten !== [] && $details !== []) {
                // Unterarten gehören zur Kerngruppe Allergene (Kurator 16.10.): EIN Marker auf die ganze Liste — auch eine
                // zusätzliche Unterart aus Necta ist dann eine Abweichung, nicht still übernommen
                $konf = collect($unterarten)->pluck('konfidenz')->filter(fn ($k) => $k !== null)->min();
                $this->setzeMarker($item, $pass, 'item_allergen', 'details', json_encode($details), false, $konf === null ? null : (float) $konf, $quelle);
            }
            $uebernommen['allergene'] = ['alt' => $alt, 'neu' => $neu, 'details' => $details];
        }

        $zusatz = [];
        foreach ((array) ($pass->extrakt['felder'] ?? []) as $key => $e) {
            $z = ProduktpassFelder::ziel($key);
            if ($z === null || $z['klasse'] !== 'kennzeichnung' || isset($uebernommen[$key])) {
                continue;   // schon früher direkt übernommen: bleibt stehen
            }
            $bisher = (int) ($item->declarations?->{$z['spalte']} ?? 0);
            if ($web && $bisher !== 0) {
                continue;   // Websuche füllt nur unbekannte Zusatzstoffe
            }
            $this->schreibeFeld($item->refresh(), $key, $z, $e['wert'], $e, $pass, false, $uebernommen);
            $zusatz[] = $key;
        }

        $pass->update([
            'uebernommen' => $uebernommen, 'allergene_status' => 'bestaetigt', 'allergene_bestaetigt_von' => $userId,
            'allergene_bestaetigt_at' => now(), 'uebernommen_at' => now(),
        ]);
        ProduktpassSchutz::zuruecksetzen();
        app(ProduktpassAbweichungService::class)->erledigteSchliessen($item->refresh());

        return $pass->refresh();
    }

    public function kennzeichnungVerwerfen(Team $team, FoodAlchemistProduktpass $pass, ?int $userId = null): FoodAlchemistProduktpass
    {
        $this->pruefeSchreiben($team, $pass->item()->firstOrFail(), $userId, FaRolle::Kuratieren, 'Kennzeichnungs-Vorschlag verwerfen');
        if ($pass->allergene_status === 'vorschlag') {
            $pass->update(['allergene_status' => 'verworfen']);
        }

        return $pass->refresh();
    }

    // ── Anzeige / MCP ───────────────────────────────────────────────────────

    public function stand(FoodAlchemistSupplierItem $item, ?FoodAlchemistProduktpass $pass = null): array
    {
        $versionen = $this->versionen($item);
        $pass ??= $versionen->firstWhere('status', 'gelesen') ?? $versionen->first();
        $felder = [];
        $zusatzstoffe = [];
        if ($pass !== null) {
            $uebernommen = $pass->uebernommen ?? [];
            foreach ($this->vorschlagsFelder($item, $pass) as $key => $e) {
                $z = ProduktpassFelder::ziel($key);
                if ($z === null) {
                    continue;
                }
                $status = ($e['gleichwertig'] ?? false) ? 'gleichwertig'
                    : (isset($uebernommen[$key]) || ($key === 'gebinde.menge' && isset($uebernommen['gebinde.qty'])) ? 'uebernommen' : 'vorschlag');
                if ($z['klasse'] === 'kennzeichnung') {
                    $zusatzstoffe[] = ['key' => $key, 'label' => $z['label'], 'vorschlag' => $e['wert'], 'konfidenz' => $e['konfidenz'] ?? 0.0, 'url' => $e['url'] ?? null,
                        'zitat' => $e['zitat'] ?? null, 'aktuell' => $this->aktuellerWert($item, $z), 'status' => $status];

                    continue;
                }
                $felder[] = [
                    'key' => $key, 'label' => $z['label'], 'wert' => $e['wert'], 'einheit' => $e['einheit'] ?? null,
                    'konfidenz' => $e['konfidenz'] ?? 0.0, 'url' => $e['url'] ?? null, 'zitat' => $e['zitat'] ?? null,
                    'status' => $status, 'abgeleitet' => (bool) ($e['abgeleitet'] ?? false), 'im_dokument' => $e['im_dokument'] ?? null,
                    'aktuell' => $z['spalte'] === '' ? null : ($key === 'gebinde.menge' ? $this->aktuelleMenge($item) : $this->aktuellerWert($item, $z)),
                    'uebernehmbar' => $z['spalte'] !== '' && $status !== 'gleichwertig',
                ];
            }
        }
        $allergene = [];
        if ($pass !== null) {
            $row = $item->allergens;
            foreach ((array) ($pass->extrakt['allergene'] ?? []) as $key => $e) {
                $allergene[] = ['key' => $key, 'label' => FoodAlchemistItemAllergen::ALLERGENE[$key] ?? $key, 'vorschlag' => $e['wert'],
                    'konfidenz' => $e['konfidenz'] ?? 0.0, 'aktuell' => $row?->{"allergen_{$key}"} ?? 'unbekannt', 'url' => $e['url'] ?? null, 'zitat' => $e['zitat'] ?? null];
            }
        }

        return [
            'versionen' => $versionen->map(fn (FoodAlchemistProduktpass $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'quelle' => $v->quelle, 'datei' => $v->original_name,
                'datum' => $v->created_at?->toDateString(), 'allergene_status' => $v->allergene_status, 'fehler' => $v->fehler,
            ])->all(),
            'angezeigt' => $pass === null ? null : ['id' => $pass->id, 'version' => $pass->version, 'status' => $pass->status, 'quelle' => $pass->quelle,
                'allergene_status' => $pass->allergene_status, 'kennzeichnung_status' => $pass->allergene_status, 'ki_modell' => $pass->ki_modell,
                'verworfen_felder' => $pass->extrakt['verworfen'] ?? []],
            'felder' => $felder,
            'zusatzstoffe' => $zusatzstoffe,
            'allergene' => $allergene,
            'allergene_details' => $pass === null ? [] : (array) ($pass->extrakt['allergene_details'] ?? []),
            'quellen_der_felder' => FoodAlchemistProduktpassFeld::where('supplier_item_id', $item->id)
                ->get(['objekt', 'feld', 'quelle', 'abgeleitet'])->map(fn ($f) => $f->only(['objekt', 'feld', 'quelle', 'abgeleitet']))->all(),
        ];
    }

    // ── intern ──────────────────────────────────────────────────────────────

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
        $uebernommen = $pass->uebernommen ?? [];
        $schwelle = ProduktpassFelder::KONFIDENZ_DIREKT;

        foreach ($this->vorschlagsFelder($item, $pass) as $key => $e) {
            $z = ProduktpassFelder::ziel($key);
            // Direkt nur: Nährwerte, Zutatenliste, Haltbarkeit (mit Lagerart), Gebinde in leere Felder. Zusatzstoffe und Allergene: nur über die Bestätigung.
            if ($z === null || in_array($z['klasse'], ['vorschlag', 'kennzeichnung'], true) || $z['spalte'] === '' || ($e['konfidenz'] ?? 0.0) < $schwelle) {
                continue;
            }
            if ($e['gleichwertig'] ?? false) {
                continue;
            }
            if ($key === 'gebinde.menge') {
                // Menge nur in einen LEEREN Artikel; hat er schon eine Einheit, muss es dieselbe Einheit sein (sonst Vorschlag mit Klick)
                $hat = ProduktpassGebinde::einheit($item->unit_code);
                if (! ProduktpassGebinde::artikelLeer($item) || ($item->unit_code !== null && $item->unit_code !== '' && ($hat === null || $hat[0] !== $e['einheit']))) {
                    continue;
                }
            } elseif ($z['klasse'] === 'nur_leer' && ! $this->zielLeer($item, $z)) {
                continue;
            }
            try {
                $this->schreibeVorschlag($item->refresh(), $pass, $key, $uebernommen);
            } catch (\RuntimeException) {
                continue;   // bleibt ein Vorschlag, der Rest läuft weiter
            }
        }
        $pass->update(['uebernommen' => $uebernommen, 'uebernommen_at' => $uebernommen === [] ? null : now()]);
    }

    /**
     * Schlüssel → Eintrag, so wie die Oberfläche und die Übernahme sie sehen (der Extrakt bleibt unverändert, auch für ältere Versionen):
     *  - Menge + Einheit werden EIN Paar `gebinde.menge` in der Einheit der Preisrechnung (g → kg, ml → l); gleichwertig zum Artikel → `gleichwertig`.
     *  - Verpackungs-Einheit nur als bekannter Code (`gebinde.packaging_unit`); eine Beschreibung wird `verpackungshinweis` (Vorschlag in additional_text).
     *  - Salz wird Natrium in mg (Salz g × 400), markiert `abgeleitet`.
     *
     * @return array<string,array<string,mixed>>
     */
    private function vorschlagsFelder(FoodAlchemistSupplierItem $item, FoodAlchemistProduktpass $pass): array
    {
        $felder = (array) ($pass->extrakt['felder'] ?? []);
        $aus = $felder;

        $menge = ProduktpassGebinde::menge($felder);
        unset($aus['gebinde.qty'], $aus['gebinde.unit_code']);
        if ($menge !== null) {
            $aus['gebinde.menge'] = ['wert' => $menge['qty'], 'einheit' => $menge['unit'], 'konfidenz' => $menge['konfidenz'], 'zitat' => $felder['gebinde.qty']['zitat'] ?? null,
                'url' => $felder['gebinde.qty']['url'] ?? null, 'im_dokument' => $menge['roh'], 'gleichwertig' => ProduktpassGebinde::gleichwertig($item, $menge)];
        }

        if (isset($felder['gebinde.packaging_unit'])) {
            $wert = (string) $felder['gebinde.packaging_unit']['wert'];
            if (ProduktpassGebinde::verpackungscode($wert) === null) {
                unset($aus['gebinde.packaging_unit']);
                $aus['verpackungshinweis'] ??= ['wert' => $wert] + $felder['gebinde.packaging_unit'];
            }
        }

        if (isset($felder['naehrwerte.salz'])) {
            unset($aus['naehrwerte.salz']);
            if (! isset($felder['naehrwerte.sodium'])) {
                $aus['naehrwerte.sodium'] = ['wert' => round((float) $felder['naehrwerte.salz']['wert'] * ProduktpassFelder::SALZ_ZU_NATRIUM_MG, 4), 'einheit' => 'mg', 'abgeleitet' => true]
                    + $felder['naehrwerte.salz'];
            }
        }

        return $aus;
    }

    private function aktuelleMenge(FoodAlchemistSupplierItem $item): ?string
    {
        if (ProduktpassGebinde::artikelLeer($item)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $item->qty, 4, ',', ''), '0'), ',').' '.($item->unit_code ?? '');
    }

    private function hatKennzeichnung(array $extrakt): bool
    {
        if (($extrakt['allergene'] ?? []) !== [] || ($extrakt['allergene_details'] ?? []) !== []) {
            return true;
        }
        foreach (array_keys((array) ($extrakt['felder'] ?? [])) as $key) {
            if ((ProduktpassFelder::ziel((string) $key)['klasse'] ?? null) === 'kennzeichnung') {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein Vorschlag (aufbereitet) wird geschrieben — die EINE Stelle für Klick, „Alle übernehmen" und die automatische Übernahme.
     * Wirft RuntimeException mit verständlicher Meldung (nie SQL).
     */
    private function schreibeVorschlag(FoodAlchemistSupplierItem $item, FoodAlchemistProduktpass $pass, string $key, array &$uebernommen): void
    {
        $felder = $this->vorschlagsFelder($item, $pass);
        $eintrag = $felder[$key] ?? null;
        $z = ProduktpassFelder::ziel($key);
        if ($eintrag === null || $z === null) {
            throw new \RuntimeException('Dieses Feld steht nicht im Vorschlag.');
        }
        if ($z['klasse'] === 'kennzeichnung') {
            throw new \RuntimeException('Zusatzstoffe werden zusammen mit den Allergenen über „Kennzeichnung bestätigen“ eingetragen.');
        }
        if ($z['spalte'] === '') {
            throw new \RuntimeException('Die Lagerart fehlt im Dokument — bitte die Haltbarkeit von Hand der richtigen Lagerart zuordnen.');
        }
        $web = $pass->quelle === ProduktpassFelder::QUELLE_WEB;
        $abgeleitet = (bool) ($eintrag['abgeleitet'] ?? false);

        if ($key === 'gebinde.menge') {
            if ($eintrag['gleichwertig'] ?? false) {
                throw new \RuntimeException('Der Artikel hat diese Menge schon (gleichwertig).');
            }
            if ($web && ! ProduktpassGebinde::artikelLeer($item)) {
                throw new \RuntimeException('Aus der Websuche nur in leere Felder — die Menge hat schon einen Wert.');
            }
            // Menge und Einheit gehören zusammen: nie die Einheit allein, nie g/ml (die Preisrechnung kennt kg, l, Stk)
            $this->schreibeFeld($item, 'gebinde.qty', ['objekt' => 'supplier_item', 'spalte' => 'qty', 'typ' => 'zahl', 'label' => 'Gebinde-Menge'], $eintrag['wert'], $eintrag, $pass, false, $uebernommen);
            $this->schreibeFeld($item->refresh(), 'gebinde.unit_code', ['objekt' => 'supplier_item', 'spalte' => 'unit_code', 'typ' => 'text', 'label' => 'Einheit der Gebinde-Menge'], $eintrag['einheit'], $eintrag, $pass, false, $uebernommen);

            return;
        }
        if ($key === 'verpackungshinweis' && ! $this->zielLeer($item, $z)) {
            throw new \RuntimeException('Am Artikel steht schon ein Hinweis — bitte von Hand ergänzen.');
        }
        if ($web && ! $this->zielLeer($item, $z)) {
            throw new \RuntimeException('Aus der Websuche nur in leere Felder — dieses Feld hat schon einen Wert.');
        }
        $this->schreibeFeld($item, $key, $z, $eintrag['wert'], $eintrag, $pass, $abgeleitet, $uebernommen);
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
        $max = ProduktpassFelder::MAX_LAENGE[$spalte] ?? null;
        if ($max !== null && is_string($wert) && mb_strlen($wert) > $max) {
            throw new \RuntimeException("Der Wert ist zu lang für „{$z['label']}“ (höchstens {$max} Zeichen).");
        }
        try {
            $this->schreibeFeldTx($item, $key, $z, $wert, $eintrag, $pass, $abgeleitet, $quelle, $spalte, $uebernommen);
        } catch (\Illuminate\Database\QueryException) {
            // nie SQL-Text zum Nutzer (Erstbefund io: SQLSTATE 1406 „Data too long")
            throw new \RuntimeException("Der Wert passt nicht in „{$z['label']}“ und wurde nicht übernommen.");
        }
        ProduktpassSchutz::zuruecksetzen();
        app(ProduktpassAbweichungService::class)->erledigteSchliessen($item);
    }

    private function schreibeFeldTx(FoodAlchemistSupplierItem $item, string $key, array $z, mixed $wert, array $eintrag, FoodAlchemistProduktpass $pass, bool $abgeleitet, string $quelle, string $spalte, array &$uebernommen): void
    {
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
