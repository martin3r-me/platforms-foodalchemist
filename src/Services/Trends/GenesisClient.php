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

    public function laden(string $code): string
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
                }])->post(self::ENDPOINT, [
                    'name' => '61111-0004', 'startyear' => now()->year - 2, 'endyear' => now()->year,
                    'classifyingvariable1' => 'CC13A5', 'classifyingkey1' => $code,
                    'format' => 'ffcsv', 'compress' => 'false', 'language' => 'de',
                ]);
        } catch (\Throwable) {
            // HTTP-Exceptions können Request-Header enthalten: keine Originalexception loggen/weiterreichen.
            throw new \RuntimeException('GENESIS nicht erreichbar oder Antwort zu groß. Später erneut versuchen.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException('GENESIS-Abruf fehlgeschlagen (HTTP '.$r->status().'). Zugang und Verfügbarkeit prüfen.');
        }
        if (strlen($r->body()) > 2 * 1024 * 1024) {
            throw new \RuntimeException('GENESIS-Antwort zu groß.');
        }

        // tablefile liefert auch bei compress=false ein ZIP. Nie auf die Platte entpacken.
        return str_starts_with($r->body(), "PK\x03\x04") ? $this->csvAusZip($r->body()) : $r->body();
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
