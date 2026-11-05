<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Illuminate\Support\Facades\Http;

/** Fester offizieller Endpunkt; weder URL noch Credentials kommen aus Tool-Eingaben. */
class GenesisClient
{
    public const ENDPOINT = 'https://genesis.destatis.de/genesisWS/rest/2020/data/tablefile';

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function eingerichtet(): bool
    {
        return trim((string) config('foodalchemist.marktdaten.genesis_token')) !== '';
    }

    public const METADATEN_TABELLE = 'https://genesis.destatis.de/genesisWS/rest/2020/metadata/table';

    public const MERKMAL_WERTE = 'https://genesis.destatis.de/genesisWS/rest/2020/catalogue/values2variable';

    /** Tabelle, Merkmal und Schlüssel kommen nur aus der festen Reihen-Konfiguration (MarktdatenService::REIHEN). */
    public function laden(string $tabelle, string $merkmal, string $schluessel): string
    {
        $r = $this->post(self::ENDPOINT, [
            'name' => $tabelle, 'startyear' => now()->year - 2, 'endyear' => now()->year,
            'classifyingvariable1' => $merkmal, 'classifyingkey1' => $schluessel,
            'format' => 'ffcsv', 'compress' => 'false', 'language' => 'de',
        ]);

        // tablefile liefert auch bei compress=false ein ZIP. Nie auf die Platte entpacken.
        return str_starts_with($r->body(), "PK\x03\x04") ? $this->csvAusZip($r->body()) : $r->body();
    }

    /** Aufbau einer Tabelle (Merkmale samt Codes), gekürzt. Nur zum Nachschlagen der Reihen-Codes. */
    public function tabellenAufbau(string $tabelle): array
    {
        $j = $this->json($this->post(self::METADATEN_TABELLE, ['name' => $tabelle, 'area' => 'all', 'language' => 'de']));
        $o = $j['Object'] ?? [];

        return ['code' => $o['Code'] ?? null, 'inhalt' => $o['Content'] ?? null, 'zeit' => $o['Time'] ?? null,
            'struktur' => $o['Structure'] ?? null, 'status' => $j['Status']['Content'] ?? null];
    }

    /** @return list<array{code:string, inhalt:string}> Schlüssel eines Merkmals (höchstens 2.500). */
    public function merkmalWerte(string $merkmal): array
    {
        $j = $this->json($this->post(self::MERKMAL_WERTE, ['name' => $merkmal, 'selection' => '*', 'area' => 'all', 'pagelength' => 2500, 'language' => 'de']));

        return array_values(array_map(fn ($w) => ['code' => (string) ($w['Code'] ?? ''), 'inhalt' => (string) ($w['Content'] ?? '')],
            array_filter((array) ($j['List'] ?? []), 'is_array')));
    }

    private function post(string $url, array $daten): \Illuminate\Http\Client\Response
    {
        if (! $this->eingerichtet()) {
            throw new \RuntimeException('GENESIS-Zugang fehlt. Kostenlosen Zugang einrichten und Token serverseitig hinterlegen.');
        }
        try {
            $r = Http::asForm()->withHeaders(['username' => config('foodalchemist.marktdaten.genesis_token'), 'password' => ''])
                ->connectTimeout(10)->timeout(40)->withoutRedirecting()
                ->withOptions(['progress' => static function ($gesamt, $geladen) {
                    if ($gesamt > 2 * 1024 * 1024 || $geladen > 2 * 1024 * 1024) {
                        throw new \RuntimeException('GENESIS-Antwort zu groß.');
                    }
                }])->post($url, $daten);
        } catch (\Throwable) {
            // HTTP-Exceptions können Request-Header enthalten: keine Originalexception loggen/weiterreichen.
            throw new \RuntimeException('GENESIS nicht erreichbar oder Antwort zu groß. Später erneut versuchen.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException('GENESIS-Abruf fehlgeschlagen (HTTP '.$r->status().'). Zugang und Verfügbarkeit prüfen.');
        }
        if (strlen($r->body()) > self::MAX_BYTES) {
            throw new \RuntimeException('GENESIS-Antwort zu groß.');
        }

        return $r;
    }

    private function json(\Illuminate\Http\Client\Response $r): array
    {
        $j = json_decode($r->body(), true);
        if (! is_array($j)) {
            throw new \RuntimeException('GENESIS liefert keine lesbare JSON-Antwort.');
        }
        // GENESIS meldet Fehler mit HTTP 200 und Status.Type = „ERROR“ bzw. Code ≠ 0; Meldung ohne Zugangsdaten.
        $status = $j['Status'] ?? $j;
        if (($status['Type'] ?? null) === 'ERROR' || (isset($j['Code']) && (int) $j['Code'] !== 0 && ! isset($j['Object']) && ! isset($j['List']))) {
            throw new \RuntimeException('GENESIS-Fehler: '.mb_substr((string) ($status['Content'] ?? 'unbekannt'), 0, 200));
        }

        return $j;
    }

    private function csvAusZip(string $inhalt): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Für GENESIS-Downloads fehlt die PHP-ZIP-Erweiterung.');
        }
        $datei = tmpfile();
        if ($datei === false) {
            throw new \RuntimeException('GENESIS-Download konnte nicht temporär geöffnet werden.');
        }
        $zip = new \ZipArchive;
        $offen = false;
        try {
            fwrite($datei, $inhalt);
            $pfad = stream_get_meta_data($datei)['uri'];
            $offen = $zip->open($pfad, \ZipArchive::RDONLY) === true;
            if (! $offen || $zip->numFiles !== 1) {
                throw new \RuntimeException('GENESIS-ZIP muss genau eine CSV-Datei enthalten.');
            }
            $info = $zip->statIndex(0);
            if (! is_array($info) || ! str_ends_with(strtolower($info['name']), '.csv') || $info['size'] > self::MAX_BYTES) {
                throw new \RuntimeException('GENESIS-ZIP enthält keine unterstützte, begrenzte CSV-Datei.');
            }
            // getFromIndex begrenzt auch die tatsächliche Entpackung, unabhängig von ZIP-Metadaten.
            $csv = $zip->getFromIndex(0, self::MAX_BYTES + 1);
            if (! is_string($csv) || strlen($csv) > self::MAX_BYTES) {
                throw new \RuntimeException('GENESIS-CSV ist ungültig oder zu groß.');
            }

            return $csv;
        } finally {
            if ($offen) {
                $zip->close();
            }
            fclose($datei);
        }
    }
}
