<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Tools\FaRechteToolHuelle;

/**
 * Registriert die MCP-Tools aus `src/Tools/_liste.php` in die Core-Registry (Spec 84 C3a).
 *
 * Idempotent: Ein Name, den die Registry schon kennt, bleibt unangetastet (der Core würde ihn sonst still
 * überschreiben). Jedes Tool kommt in die Rechte-Hülle (Spec 77a, schreibend ab Kuratieren).
 */
final class ToolListe
{
    /** @return list<class-string> */
    public static function klassen(): array
    {
        return require dirname(__DIR__) . '/Tools/_liste.php';
    }

    /**
     * @param  list<class-string>|null  $klassen  nur für Tests; Standard = die Liste (dann über den Scan-Cache, Spec 84 E-4)
     */
    public static function registriere(object $registry, ?array $klassen = null): void
    {
        if ($klassen !== null) {
            self::registriereLive($registry, $klassen);

            return;
        }
        $liste = self::klassen();
        $meta = ScanCache::tools($liste, fn (array $k) => self::metaLive($k));
        foreach ($liste as $toolClass) {
            $m = $meta[ltrim($toolClass, '\\')] ?? $meta[$toolClass] ?? null;
            if ($m === null) {
                self::registriereLive($registry, [$toolClass]);   // fehlt im Cache → dieses Tool live (nie ein halb leeres MCP)

                continue;
            }
            if (! $registry->has($m['name'])) {
                $registry->register(FaRechteToolHuelle::verzoegert($toolClass, $m['name'], $m['meta']));
            }
        }
    }

    /** Bisheriger Weg: jedes Tool bauen und in die Rechte-Hülle legen; Fehler mit Klasse ins Log. */
    private static function registriereLive(object $registry, array $klassen): void
    {
        foreach ($klassen as $toolClass) {
            try {
                $tool = new $toolClass();
                if (! $registry->has($tool->getName())) {
                    $registry->register(new FaRechteToolHuelle($tool));
                }
            } catch (\Throwable $e) {
                // Die Registrierung darf den Boot nie reißen — aber ein kaputtes Tool darf auch nicht still aus MCP
                // verschwinden. Darum Klasse + Fehler ins Log.
                Log::warning('[FoodAlchemist] MCP-Tool nicht registriert: ' . $toolClass . ' — ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Live-Scan für den Cache: Name + Metadaten je fehlerfrei baubarer Klasse. Kaputte Tools fehlen hier und werden bei
     * der Registrierung live versucht (und geloggt).
     *
     * @return array<string, array{name: string, meta: array}>
     */
    public static function metaLive(array $klassen): array
    {
        $out = [];
        foreach ($klassen as $toolClass) {
            try {
                $tool = new $toolClass();
                $out[ltrim($toolClass, '\\')] = [
                    'name' => $tool->getName(),
                    'meta' => $tool instanceof \Platform\Core\Contracts\ToolMetadataContract ? $tool->getMetadata() : [],
                ];
            } catch (\Throwable $e) {
                unset($e);   // Log folgt in registriereLive
            }
        }

        return $out;
    }
}
