<?php

namespace Platform\FoodAlchemist\Tools\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Platform\FoodAlchemist\Support\TrendVokabular;

/**
 * Spec 79 · Datei für einen Beleg aus MCP-Argumenten: `datei.base64` (auch als data-URL) oder `datei.url`,
 * dazu `datei.name`. Ergibt ein UploadedFile für den gemeinsamen Schreibpfad (ContextFileService).
 * Muster wie Core `core.context.files.CREATE`. Temp-Datei räumt `dateiAufraeumen` weg.
 */
trait DateiAusArgumenten
{
    /** @var list<string> */
    private array $tempDateien = [];

    /** @param  array<string,mixed>|null  $datei */
    protected function dateiAusArgumenten(?array $datei): ?UploadedFile
    {
        if ($datei === null || $datei === []) {
            return null;
        }
        $name = trim((string) ($datei['name'] ?? '')) ?: 'beleg';
        $inhalt = null;
        $mime = null;
        if (! empty($datei['base64'])) {
            $roh = (string) $datei['base64'];
            if (preg_match('/^data:([^;]+);base64,(.+)$/si', $roh, $m)) {
                $mime = strtolower($m[1]);
                $roh = $m[2];
            }
            $inhalt = base64_decode($roh, true);
            if ($inhalt === false) {
                throw new \RuntimeException('datei.base64 ist kein gültiges Base64.');
            }
        } elseif (! empty($datei['url'])) {
            $url = (string) $datei['url'];
            if (! preg_match('~^https?://~i', $url)) {
                throw new \RuntimeException('datei.url muss mit http:// oder https:// beginnen.');
            }
            $antwort = Http::timeout(30)->get($url);
            if (! $antwort->successful()) {
                throw new \RuntimeException('Datei konnte nicht geladen werden (HTTP '.$antwort->status().').');
            }
            $inhalt = $antwort->body();
            $mime = strtolower(trim(explode(';', (string) $antwort->header('Content-Type'))[0])) ?: null;
            if ($name === 'beleg') {
                $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'beleg';
            }
        } else {
            return null;
        }
        if (strlen($inhalt) > TrendVokabular::DATEI_MAX_KB * 1024) {
            throw new \RuntimeException('Die Datei ist größer als 15 MB.');
        }
        $pfad = tempnam(sys_get_temp_dir(), 'fa_trend_');
        file_put_contents($pfad, $inhalt);
        $this->tempDateien[] = $pfad;
        $mime = $mime ?: (new \finfo(FILEINFO_MIME_TYPE))->file($pfad) ?: 'application/octet-stream';

        return new UploadedFile($pfad, $name, $mime, null, true);
    }

    protected function dateiAufraeumen(): void
    {
        foreach ($this->tempDateien as $pfad) {
            @unlink($pfad);
        }
        $this->tempDateien = [];
    }

    /** Schema-Baustein für das `datei`-Argument. */
    protected static function dateiSchema(): array
    {
        return ['type' => 'object', 'description' => 'Optional: Datei zum Beleg (Screenshot, Foto, PDF; max. 15 MB). base64 (auch data-URL) ODER url.',
            'properties' => [
                'base64' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'name' => ['type' => 'string', 'description' => 'Dateiname, z. B. instagram-2026-10-08.jpg'],
            ]];
    }
}
