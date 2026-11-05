<?php

namespace Platform\FoodAlchemist\Services\Trends;

use Illuminate\Support\Facades\Http;

/** Fester offizieller Endpunkt; weder URL noch Credentials kommen aus Tool-Eingaben. */
class GenesisClient
{
    public const ENDPOINT = 'https://genesis.destatis.de/genesisWS/rest/2020/data/tablefile';

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

        return $r->body();
    }
}
