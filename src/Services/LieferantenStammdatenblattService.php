<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierDeliveryArea;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierDocument;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Lieferanten-Stammdatenblatt (Lieferanten-Link, Stufe 2d). Der Lieferant füllt im Portal ein Formular: Firmendaten, Ansprechpartner je Rolle,
 * Bestellwege und -zeiten, Liefergebiete, Konditionen-Texte, Zertifikate mit Ablaufdatum. Das landet als Lauf der Art „lieferant“ (Herkunft
 * „lieferanten_link“) in der Prüfliste, eine Zeile je Datensatz mit Vorher/Nachher (diff). Erst das Team (Rolle Kuratieren) übernimmt.
 *
 * Feste Feld-Whitelist ({@see STAMM_FELDER}): Rückvergütung, Status, Rolle, Vereinbarungen, Inaktiv-Kennzeichen und alles Interne sind im Portal weder
 * sichtbar noch übernehmbar — auch dann nicht, wenn jemand eine Prüflisten-Zeile manipuliert (beim Übernehmen wird die Whitelist erneut angewendet).
 * Eine leere Eingabe heißt „nicht ändern“, das Portal löscht nichts. Globale Stamm-Lieferanten (team_id null): Übernehmen schreibt in die gemeinsame
 * Zeile und darf nur das Master-Team; Ansprechpartner, Liefergebiete und Zertifikate gehören dem übernehmenden Team (team_id).
 */
class LieferantenStammdatenblattService
{
    public const ROLLEN = ['vertrieb' => 'Vertrieb', 'disposition' => 'Disposition', 'qualitaet' => 'Qualität / Allergene', 'buchhaltung' => 'Buchhaltung', 'reklamation' => 'Reklamation'];

    public const KANAELE = ['email' => 'E-Mail', 'telefon' => 'Telefon', 'fax' => 'Fax', 'portal' => 'Online-Portal', 'edi' => 'EDI'];

    /** Feld ⇒ [Bezeichnung, Typ, Höchstlänge]. Nur diese Felder des Lieferanten kann das Portal vorschlagen. */
    public const STAMM_FELDER = [
        'legal_name' => ['Firmierung', 'text', 255], 'vat_id' => ['USt-IdNr.', 'text', 32], 'phone' => ['Zentrale (Telefon)', 'text', 64],
        'email_order' => ['Bestell-E-Mail', 'email', 255], 'homepage' => ['Webseite', 'url', 255],
        'address' => ['Straße und Nr.', 'text', 255], 'postal_code' => ['PLZ', 'text', 16], 'city' => ['Ort', 'text', 255],
        'order_channels' => ['Bestellwege', 'kanaele', 0], 'order_cutoff_time' => ['Bestellschluss (HH:MM)', 'time', 0],
        'order_lead_days' => ['Vorlaufzeit (Tage)', 'int', 0], 'delivery_days' => ['Liefertage (1=Mo … 7=So)', 'tage', 0],
        'min_order_value' => ['Mindestbestellwert netto (€)', 'dec', 0], 'free_shipping_threshold' => ['Frei-Haus-Grenze netto (€)', 'dec', 0],
        'small_order_surcharge' => ['Mindermengenzuschlag netto (€)', 'dec', 0], 'freight_note' => ['Fracht / Mindermengen', 'text', 1000],
        'payment_term_days' => ['Zahlungsziel (Tage)', 'int', 0], 'payment_terms_note' => ['Zahlungsbedingungen (Skonto usw.)', 'text', 255],
        'complaint_path' => ['Reklamationsweg', 'text', 1000],
    ];

    private const KONTAKT_FELDER = ['name' => 255, 'phone' => 64, 'mobile' => 64, 'email' => 255];

    private const GEBIET_FELDER = ['name' => ['text', 255], 'postal_codes' => ['text', 1000], 'delivery_days' => ['tage', 0], 'delivery_von' => ['time', 0], 'delivery_bis' => ['time', 0],
        'order_cutoff_time' => ['time', 0], 'lead_days' => ['int', 0], 'min_order_value' => ['dec', 0], 'note' => ['text', 255]];

    public function __construct(
        private readonly LieferantenLinkService $links,
        private readonly LieferantenDokumentService $dokumente,
        private readonly LieferantenUploadBenachrichtigung $benachrichtigung,
        private readonly FaRechte $rechte,
    ) {}

    // ── Portal ──────────────────────────────────────────────────────────────

    /**
     * Aktueller Stand der Whitelist-Felder für die Vorbelegung des Formulars (nur was der Lieferant selbst pflegen darf).
     *
     * @return array{stamm: array<string, mixed>, kontakte: array<string, array<string, ?string>>, gebiete: list<array<string, mixed>>, zertifikate: list<array<string, mixed>>}
     */
    public function vorbelegung(Link $link): array
    {
        $team = Team::findOrFail($link->team_id);
        $lieferant = FoodAlchemistSupplier::findOrFail($link->supplier_id);
        $stamm = [];
        foreach (array_keys(self::STAMM_FELDER) as $f) {
            $stamm[$f] = $lieferant->{$f};
        }
        $kontakte = [];
        $alle = $this->kontakteVon($team, $lieferant);
        foreach ($alle->pluck('rolle_key')->unique() as $rolle) {
            $c = $this->kontaktFuerRolle($alle, $team, (string) $rolle);
            $kontakte[$c->rolle_key] = ['name' => $c->name, 'phone' => $c->phone, 'mobile' => $c->mobile, 'email' => $c->email];
        }

        return ['stamm' => $stamm, 'kontakte' => $kontakte,
            'gebiete' => $this->gebieteVon($team, $lieferant)->map(fn ($g) => $g->only(array_keys(self::GEBIET_FELDER)))->values()->all(),
            'zertifikate' => $this->zertifikateVon($team, $lieferant)->map(fn ($d) => ['cert_type' => $d->cert_type, 'cert_number' => $d->cert_number, 'issuer' => $d->issuer, 'term_end' => $d->term_end?->toDateString()])->values()->all()];
    }

    /**
     * Das ausgefüllte Formular einreichen: je geänderter Datensatz eine Prüflisten-Zeile.
     *
     * @param  array{stamm?: array<string, mixed>, kontakt?: array<string, array<string, mixed>>, gebiet?: list<array<string, mixed>>, zertifikat?: array<string, mixed>}  $eingabe
     * @return array{lauf: Lauf, zeilen: int}
     *
     * @throws \RuntimeException mit einer Meldung, die dem Lieferanten gezeigt werden darf
     */
    public function einreichen(Link $link, array $eingabe, ?UploadedFile $zertifikatDatei = null, ?Request $request = null): array
    {
        $team = Team::findOrFail($link->team_id);
        try {
            if (! in_array('formular', (array) $link->bereiche, true)) {
                throw new \RuntimeException('Dieser Bereich ist für deinen Zugang nicht freigegeben.');
            }
            $lieferant = FoodAlchemistSupplier::find($link->supplier_id);
            if ($lieferant === null || ! $this->links->lieferantErlaubt($team, $lieferant)) {
                throw new \RuntimeException('Dieser Zugang ist nicht mehr gültig.');
            }
            $saetze = $this->baueSaetze($team, $lieferant, $eingabe, $zertifikatDatei);
            if ($saetze === []) {
                throw new \RuntimeException('Es wurde nichts geändert oder neu eingetragen.');
            }
        } catch (\RuntimeException $e) {
            $this->links->ereignis($link, 'abgelehnt', ['bereich' => 'formular', 'grund' => mb_substr($e->getMessage(), 0, 200)], $request);

            throw $e;
        }

        $zaehler = ['zeilen' => count($saetze), 'neu' => 0, 'aendert' => 0];
        $lauf = DB::transaction(function () use ($team, $link, $lieferant, $saetze, &$zaehler) {
            $lauf = Lauf::create(['team_id' => $team->id, 'supplier_id' => $lieferant->id, 'filename' => 'Stammdatenblatt ' . $lieferant->name, 'status' => 'staged', 'art' => Lauf::ART_LIEFERANT,
                'herkunft' => Lauf::HERKUNFT_LIEFERANTEN_LINK, 'link_id' => $link->id]);
            foreach (array_values($saetze) as $i => $s) {
                Zeile::create(['team_id' => $team->id, 'run_id' => $lauf->id, 'row_nr' => $i + 1, 'supplier_id' => $lieferant->id, 'supplier_name' => $lieferant->name,
                    'designation' => $s['label'], 'status' => Zeile::STATUS_GEPRUEFT, 'aktion' => $s['aktion'], 'diff' => $s['diff'], 'daten' => ['typ' => $s['typ'], 'werte' => $s['werte']] + ($s['datei'] ?? []),
                    'hinweise' => []]);
                $zaehler[$s['aktion']]++;
            }
            $lauf->update(['counts' => $zaehler]);

            return $lauf->refresh();
        });
        $link->forceFill(['upload_count' => (int) $link->upload_count + 1, 'last_used_at' => now()])->save();
        $this->links->ereignis($link, 'upload', ['bereich' => 'formular', 'lauf_id' => $lauf->id, 'zeilen' => count($saetze)], $request);
        $this->benachrichtigung->melde($link, $lauf);

        return ['lauf' => $lauf, 'zeilen' => count($saetze)];
    }

    // ── Team ────────────────────────────────────────────────────────────────

    /**
     * Offene Vorschläge des Teams (Lauf-Art „lieferant“), neueste zuerst.
     *
     * @return \Illuminate\Support\Collection<int, Zeile>
     */
    public function offene(Team $team, int $limit = 200): \Illuminate\Support\Collection
    {
        return Zeile::where('team_id', $team->id)->where('entscheidung', Zeile::ENTSCHEIDUNG_OFFEN)
            ->whereIn('run_id', Lauf::where('team_id', $team->id)->where('art', Lauf::ART_LIEFERANT)->select('id'))
            ->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Übernehmen oder verwerfen. Hart geprüft: Rolle Kuratieren; der Lieferant des Links muss für dieses Team erlaubt sein (eigener Lieferant,
     * ein globaler nur das Master-Team); geschrieben wird nur über die Feld-Whitelist.
     *
     * @param  list<int>  $zeilenIds
     * @return array{uebernommen: int, verworfen: int, fehler: list<string>}
     */
    public function entscheide(Team $team, ?User $user, array $zeilenIds, string $entscheidung): array
    {
        $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, 'Stammdatenblatt des Lieferanten übernehmen oder verwerfen');
        if (! in_array($entscheidung, ['uebernehmen', 'verwerfen'], true)) {
            throw new \RuntimeException('Entscheidung: uebernehmen oder verwerfen.');
        }
        $bilanz = ['uebernommen' => 0, 'verworfen' => 0, 'fehler' => []];
        $zeilen = $this->offene($team, 500)->whereIn('id', $zeilenIds);
        foreach ($zeilen as $z) {
            $lieferant = FoodAlchemistSupplier::find($z->supplier_id);
            if ($lieferant === null || ! $this->links->lieferantErlaubt($team, $lieferant) || ($lieferant->team_id === null && ! MasterTeam::istMaster($team))) {
                throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Stammdatenblatt dieses Lieferanten (nur eigene Lieferanten; globale nur das Master-Team)');
            }
            $link = Link::find(Lauf::whereKey($z->run_id)->value('link_id'));
            if ($entscheidung === 'verwerfen') {
                $this->loesche($z, Zeile::ENTSCHEIDUNG_VERWERFEN, Zeile::STATUS_VERWORFEN);
                $bilanz['verworfen']++;
                $link && $this->links->ereignis($link, 'stammdatenblatt_verworfen', ['zeile_id' => $z->id, 'typ' => $z->daten['typ'] ?? null]);

                continue;
            }
            try {
                DB::transaction(fn () => $this->uebernimm($team, $user, $lieferant, $z));
                $bilanz['uebernommen']++;
                $link && $this->links->ereignis($link, 'stammdatenblatt_uebernommen', ['zeile_id' => $z->id, 'typ' => $z->daten['typ'] ?? null]);
            } catch (\RuntimeException $e) {
                $bilanz['fehler'][] = "Zeile {$z->id}: " . $e->getMessage();
            }
        }
        foreach ($zeilen->pluck('run_id')->unique() as $id) {
            if (Zeile::where('run_id', $id)->where('entscheidung', Zeile::ENTSCHEIDUNG_OFFEN)->doesntExist()) {
                Lauf::where('id', $id)->where('team_id', $team->id)->update(['status' => Zeile::where('run_id', $id)->where('status', Zeile::STATUS_ANGEWENDET)->exists() ? 'applied' : 'verworfen']);
            }
        }

        return $bilanz;
    }

    private function uebernimm(Team $team, User $user, FoodAlchemistSupplier $lieferant, Zeile $z): void
    {
        $typ = (string) ($z->daten['typ'] ?? '');
        $w = (array) ($z->daten['werte'] ?? []);
        match ($typ) {
            'stamm' => $this->schreibeStamm($lieferant, $w),
            'kontakt' => $this->schreibeKontakt($team, $lieferant, $w),
            'gebiet' => $this->schreibeGebiet($team, $lieferant, $w),
            'zertifikat' => $this->schreibeZertifikat($team, $user, $lieferant, $z, $w),
            default => throw new \RuntimeException('Unbekannter Datensatz.'),
        };
        $this->loesche($z, Zeile::ENTSCHEIDUNG_UEBERNEHMEN, Zeile::STATUS_ANGEWENDET);
    }

    /** Nur Whitelist-Felder; die Werte werden beim Übernehmen erneut normalisiert, nichts wird ungeprüft aus der Zeile geschrieben. */
    private function schreibeStamm(FoodAlchemistSupplier $lieferant, array $werte): void
    {
        $nur = [];
        foreach (self::STAMM_FELDER as $f => [$label, $art, $max]) {
            if (array_key_exists($f, $werte) && $werte[$f] !== null) {
                $nur[$f] = $this->wert($label, $art, $max, $werte[$f]);
            }
        }
        if ($nur !== []) {
            $lieferant->update($nur);
        }
    }

    private function schreibeKontakt(Team $team, FoodAlchemistSupplier $lieferant, array $w): void
    {
        $rolle = (string) ($w['rolle_key'] ?? '');
        if (! isset(self::ROLLEN[$rolle]) || trim((string) ($w['name'] ?? '')) === '') {
            throw new \RuntimeException('Ansprechpartner ohne Rolle oder Namen.');
        }
        $daten = ['name' => $this->wert('Name', 'text', 255, $w['name']), 'phone' => $this->wert('Telefon', 'text', 64, $w['phone'] ?? null), 'mobile' => $this->wert('Mobil', 'text', 64, $w['mobile'] ?? null),
            'email' => $this->wert('E-Mail', 'email', 255, $w['email'] ?? null), 'role' => self::ROLLEN[$rolle]];
        // Nur den EIGENEN Kontakt der Rolle ändern; gibt es nur den eines Eltern-Teams, bekommt das Team einen eigenen (der fremde bleibt).
        $vorhanden = $this->kontakteVon($team, $lieferant)->first(fn ($c) => $c->rolle_key === $rolle && (int) $c->team_id === (int) $team->id);
        $vorhanden !== null
            ? $vorhanden->update(array_filter($daten, fn ($v) => $v !== null))
            : FoodAlchemistSupplierContact::create($daten + ['team_id' => $team->id, 'supplier_id' => $lieferant->id, 'rolle_key' => $rolle]);
    }

    private function schreibeGebiet(Team $team, FoodAlchemistSupplier $lieferant, array $w): void
    {
        $daten = [];
        foreach (self::GEBIET_FELDER as $f => [$art, $max]) {
            if (isset($w[$f]) && $w[$f] !== '') {
                $daten[$f] = $this->wert($f, $art, $max, $w[$f]);
            }
        }
        if (empty($daten['name'])) {
            throw new \RuntimeException('Liefergebiet ohne Namen.');
        }
        $vorhanden = $this->gebieteVon($team, $lieferant)->first(fn ($g) => Str::lower($g->name) === Str::lower($daten['name']));
        $vorhanden !== null ? $vorhanden->update($daten) : FoodAlchemistSupplierDeliveryArea::create($daten + ['team_id' => $team->id, 'supplier_id' => $lieferant->id]);
    }

    private function schreibeZertifikat(Team $team, User $user, FoodAlchemistSupplier $lieferant, Zeile $z, array $w): void
    {
        $typ = $this->wert('Zertifikat', 'text', 64, $w['cert_type'] ?? null);
        $ende = $this->datum($w['term_end'] ?? null);
        if ($typ === null || $ende === null) {
            throw new \RuntimeException('Zertifikat ohne Art oder Ablaufdatum.');
        }
        $nummer = $this->wert('Nummer', 'text', 64, $w['cert_number'] ?? null);
        $dok = $this->zertifikateVon($team, $lieferant)->first(fn ($d) => Str::lower((string) $d->cert_type) === Str::lower($typ) && (string) $d->cert_number === (string) $nummer)
            ?? FoodAlchemistSupplierDocument::create(['team_id' => $team->id, 'supplier_id' => $lieferant->id, 'kind' => 'zertifikat', 'title' => $typ, 'cert_type' => $typ, 'cert_number' => $nummer]);
        $dok->update(['term_end' => $ende, 'issuer' => $this->wert('Aussteller', 'text', 128, $w['issuer'] ?? null) ?? $dok->issuer]);
        $pfad = (string) ($z->daten['pfad'] ?? '');
        if ($pfad !== '' && str_starts_with($pfad, LieferantenDokumentService::ORDNER . '/') && Storage::disk('local')->exists($pfad)) {
            $tmp = tempnam(sys_get_temp_dir(), 'fa-zert-');
            file_put_contents($tmp, Storage::disk('local')->get($pfad));
            try {
                $res = app(\Platform\Core\Services\ContextFileService::class)->uploadForContext(new UploadedFile($tmp, (string) ($z->daten['name'] ?? 'zertifikat'), (string) ($z->daten['mime'] ?? null), null, true),
                    'foodalchemist.supplier_document', (int) $dok->id, ['team_id' => $team->id, 'user_id' => $user->id, 'folder' => 'foodalchemist/lieferanten-zertifikate/' . $lieferant->id, 'keep_original' => true, 'generate_variants' => false]);
                $dok->update(['context_file_id' => (int) $res['id'], 'original_name' => $z->daten['name'] ?? null, 'mime' => $z->daten['mime'] ?? null]);
            } finally {
                @unlink($tmp);
            }
        }
    }

    /** Staging-Datei entfernen (falls es eine gibt) und die Zeile abschließen. */
    private function loesche(Zeile $z, string $entscheidung, string $status): void
    {
        $pfad = (string) ($z->daten['pfad'] ?? '');
        if (str_starts_with($pfad, LieferantenDokumentService::ORDNER . '/')) {
            Storage::disk('local')->delete($pfad);
        }
        $z->forceFill(['entscheidung' => $entscheidung, 'status' => $status])->save();
    }

    // ── Saetze bauen (Prüfen, Normalisieren, Vorher/Nachher) ─────────────────

    /** @return list<array{typ: string, label: string, aktion: string, diff: array<string, array{0: mixed, 1: mixed}>, werte: array<string, mixed>, datei?: array<string, mixed>}> */
    private function baueSaetze(Team $team, FoodAlchemistSupplier $lieferant, array $e, ?UploadedFile $datei): array
    {
        $saetze = [];
        $stamm = $diff = [];
        foreach (self::STAMM_FELDER as $f => [$label, $art, $max]) {
            $neu = $this->wert($label, $art, $max, $e['stamm'][$f] ?? null);
            if ($neu !== null && ! $this->gleich($lieferant->{$f}, $neu)) {
                $stamm[$f] = $neu;
                $diff[$f] = [$lieferant->{$f}, $neu];
            }
        }
        if ($stamm !== []) {
            $saetze[] = ['typ' => 'stamm', 'label' => 'Firmen- und Bestelldaten', 'aktion' => Zeile::AKTION_AENDERT, 'diff' => $diff, 'werte' => $stamm];
        }
        $kontakte = $this->kontakteVon($team, $lieferant);
        foreach ((array) ($e['kontakt'] ?? []) as $rolle => $k) {
            $k = (array) $k;
            if (! isset(self::ROLLEN[$rolle]) || $this->leer($k)) {
                continue;
            }
            $werte = ['rolle_key' => $rolle];
            foreach (self::KONTAKT_FELDER as $f => $max) {
                $werte[$f] = $this->wert($f, $f === 'email' ? 'email' : 'text', $max, $k[$f] ?? null);
            }
            if ($werte['name'] === null) {
                throw new \RuntimeException('Ansprechpartner ' . self::ROLLEN[$rolle] . ': bitte einen Namen angeben.');
            }
            $alt = $this->kontaktFuerRolle($kontakte, $team, $rolle);
            $eigen = $alt !== null && (int) $alt->team_id === (int) $team->id;   // Übernehmen ändert nur den eigenen, sonst legt es einen an
            $diff = [];
            foreach (self::KONTAKT_FELDER as $f => $_) {
                if ($werte[$f] !== null && ! $this->gleich($alt?->{$f}, $werte[$f])) {
                    $diff[$f] = [$alt?->{$f}, $werte[$f]];
                }
            }
            if ($diff !== []) {
                $saetze[] = ['typ' => 'kontakt', 'label' => 'Ansprechpartner ' . self::ROLLEN[$rolle], 'aktion' => $eigen ? Zeile::AKTION_AENDERT : Zeile::AKTION_NEU, 'diff' => $diff, 'werte' => $werte];
            }
        }
        $gebiete = $this->gebieteVon($team, $lieferant);
        foreach (array_slice((array) ($e['gebiet'] ?? []), 0, 30) as $g) {
            $g = (array) $g;
            if ($this->leer($g)) {
                continue;
            }
            $werte = [];
            foreach (self::GEBIET_FELDER as $f => [$art, $max]) {
                $werte[$f] = $this->wert($f, $art, $max, $g[$f] ?? null);
            }
            if ($werte['name'] === null) {
                throw new \RuntimeException('Liefergebiet: bitte einen Namen angeben.');
            }
            $alt = $gebiete->first(fn ($x) => Str::lower($x->name) === Str::lower($werte['name']));
            $diff = [];
            foreach ($werte as $f => $v) {
                if ($v !== null && ! $this->gleich($alt?->{$f}, $v)) {
                    $diff[$f] = [$alt?->{$f}, $v];
                }
            }
            if ($diff !== []) {
                $saetze[] = ['typ' => 'gebiet', 'label' => 'Liefergebiet ' . $werte['name'], 'aktion' => $alt === null ? Zeile::AKTION_NEU : Zeile::AKTION_AENDERT, 'diff' => $diff, 'werte' => array_filter($werte, fn ($v) => $v !== null)];
            }
        }
        $z = (array) ($e['zertifikat'] ?? []);
        if (! $this->leer($z) || $datei !== null) {
            $typ = $this->wert('Zertifikat', 'text', 64, $z['cert_type'] ?? null);
            $ende = $this->datum($z['term_end'] ?? null);
            if ($typ === null || $ende === null) {
                throw new \RuntimeException('Zertifikat: bitte Art und Ablaufdatum (TT.MM.JJJJ) angeben.');
            }
            $werte = ['cert_type' => $typ, 'cert_number' => $this->wert('Nummer', 'text', 64, $z['cert_number'] ?? null), 'issuer' => $this->wert('Aussteller', 'text', 128, $z['issuer'] ?? null), 'term_end' => $ende];
            $alt = $this->zertifikateVon($team, $lieferant)->first(fn ($d) => Str::lower((string) $d->cert_type) === Str::lower($typ) && (string) $d->cert_number === (string) $werte['cert_number']);
            $satz = ['typ' => 'zertifikat', 'label' => 'Zertifikat ' . $typ, 'aktion' => $alt === null ? Zeile::AKTION_NEU : Zeile::AKTION_AENDERT,
                'diff' => ['term_end' => [$alt?->term_end?->toDateString(), $ende]] + ($werte['issuer'] !== null ? ['issuer' => [$alt?->issuer, $werte['issuer']]] : []), 'werte' => $werte];
            if ($datei !== null) {
                [$endung, $mime] = $this->dokumente->pruefeDatei($datei);
                $rel = LieferantenDokumentService::ORDNER . '/' . Str::uuid() . '.' . $endung;
                Storage::disk('local')->put($rel, (string) file_get_contents((string) $datei->getRealPath()));
                $satz['datei'] = ['pfad' => $rel, 'name' => mb_substr(preg_replace('/[^\p{L}\p{N} ._\-]+/u', '_', pathinfo((string) $datei->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'zertifikat', 0, 80) . '.' . $endung, 'mime' => $mime];
                $satz['diff']['datei'] = [null, $satz['datei']['name']];
            }
            $saetze[] = $satz;
        }

        return $saetze;
    }

    /** Eingabe prüfen und normalisieren. Leer ⇒ null (= nicht ändern). Fehler ⇒ Meldung für den Lieferanten. */
    private function wert(string $label, string $art, int $max, mixed $roh): int|float|string|array|null
    {
        if (is_array($roh) && $art !== 'kanaele') {
            throw new \RuntimeException("{$label}: ungültige Eingabe.");
        }
        if ($art === 'kanaele') {
            $k = array_values(array_intersect(array_keys(self::KANAELE), array_map('strval', (array) $roh)));

            return $k === [] ? null : $k;
        }
        $s = trim(preg_replace('/\s+/u', ' ', (string) $roh) ?? '');
        if ($s === '') {
            return null;
        }
        $fehler = fn (string $was) => new \RuntimeException("{$label}: {$was}");
        switch ($art) {
            case 'email':
                return filter_var($s, FILTER_VALIDATE_EMAIL) && mb_strlen($s) <= $max ? $s : throw $fehler('bitte eine gültige E-Mail-Adresse angeben.');
            case 'url':
                return preg_match('#^https?://[^\s]+$#i', $s) && mb_strlen($s) <= $max ? $s : throw $fehler('bitte eine Adresse mit http:// oder https:// angeben.');
            case 'int':
                return preg_match('/^\d{1,4}$/', $s) ? (int) $s : throw $fehler('bitte eine ganze Zahl angeben.');
            case 'dec':
                $n = str_contains($s, ',') ? str_replace(',', '.', str_replace('.', '', $s)) : $s;

                return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $n) ? round((float) $n, 2) : throw $fehler('bitte einen Betrag angeben (z. B. 150,00).');
            case 'time':
                return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $s) ? sprintf('%02d:%s', (int) explode(':', $s)[0], explode(':', $s)[1]) : throw $fehler('bitte eine Uhrzeit im Format HH:MM angeben.');
            case 'tage':
                $t = array_values(array_unique(array_map('intval', preg_split('/[\s,;]+/', $s) ?: [])));
                sort($t);

                return $t !== [] && min($t) >= 1 && max($t) <= 7 && preg_match('/^[1-7\s,;]+$/', $s) ? implode(',', $t) : throw $fehler('bitte Wochentage als Zahlen 1 (Mo) bis 7 (So) angeben, z. B. 1,3,5.');
            default:   // text
                return mb_strlen($s) <= $max ? LieferantenUploadService::entschaerfe($s) : throw $fehler("bitte höchstens {$max} Zeichen.");
        }
    }

    private function datum(mixed $roh): ?string
    {
        $s = trim((string) $roh);
        if ($s === '') {
            return null;
        }
        foreach (['d.m.Y', 'Y-m-d'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $s);
            if ($d !== false && $d->format($fmt) === $s && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100) {
                return $d->format('Y-m-d');
            }
        }
        throw new \RuntimeException('Datum bitte als TT.MM.JJJJ angeben.');
    }

    private function gleich(mixed $alt, mixed $neu): bool
    {
        if (is_array($neu)) {
            $a = (array) $alt;
            sort($a);
            $n = $neu;
            sort($n);

            return $a === $n;
        }
        if (is_numeric($neu) && is_numeric($alt)) {
            return abs((float) $alt - (float) $neu) < 0.005;
        }

        return trim((string) $alt) === trim((string) $neu);
    }

    private function leer(array $f): bool
    {
        return collect($f)->filter(fn ($v) => is_array($v) ? $v !== [] : trim((string) $v) !== '')->isEmpty();
    }

    // ── Lesen (ohne Schreiben) ──────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int, FoodAlchemistSupplierContact> */
    private function kontakteVon(Team $team, FoodAlchemistSupplier $l): \Illuminate\Support\Collection
    {
        return FoodAlchemistSupplierContact::visibleToTeam($team)->where('supplier_id', $l->id)->whereNotNull('rolle_key')->get();
    }

    /** Kontakt einer Rolle für Anzeige und Vergleich: der eigene vor dem eines Eltern-Teams. */
    private function kontaktFuerRolle(\Illuminate\Support\Collection $kontakte, Team $team, string $rolle): ?FoodAlchemistSupplierContact
    {
        $zurRolle = $kontakte->where('rolle_key', $rolle);

        return $zurRolle->first(fn ($c) => (int) $c->team_id === (int) $team->id) ?? $zurRolle->first();
    }

    /** @return \Illuminate\Support\Collection<int, FoodAlchemistSupplierDeliveryArea> */
    private function gebieteVon(Team $team, FoodAlchemistSupplier $l): \Illuminate\Support\Collection
    {
        return FoodAlchemistSupplierDeliveryArea::visibleToTeam($team)->where('supplier_id', $l->id)->orderBy('name')->get();
    }

    /** @return \Illuminate\Support\Collection<int, FoodAlchemistSupplierDocument> */
    private function zertifikateVon(Team $team, FoodAlchemistSupplier $l): \Illuminate\Support\Collection
    {
        return FoodAlchemistSupplierDocument::visibleToTeam($team)->where('supplier_id', $l->id)->where('kind', 'zertifikat')->get();
    }
}
