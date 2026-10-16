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

    /** @param list<class-string>|null $klassen nur für Tests; Standard = die Liste */
    public static function registriere(object $registry, ?array $klassen = null): void
    {
        foreach ($klassen ?? self::klassen() as $toolClass) {
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
}
