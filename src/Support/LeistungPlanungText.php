<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 94b §3 · Planungsdetails als Text fürs Leistungs-Formular (wie die Stufen „bis Gäste; Anzahl“), eine Stelle für Vorlage und
 * Konfigurator-Leistung. Geprüft wird danach in LeistungsRegelPruefung::planung (auch für MCP).
 *  - Komponenten je Zeile: „Menge; je; Bezeichnung; Bezug“ — je = Einheit | Gast | Stunde, Bezug optional „GP 812“ bzw. „Behälter 14“.
 *  - Attribute je Zeile: „Schlüssel; Wert; Einheit“ (Einheit optional).
 */
final class LeistungPlanungText
{
    private const JE = ['einheit' => 'einheit', 'stück' => 'einheit', 'stueck' => 'einheit', 'einsatz' => 'einheit', 'gast' => 'gast', 'gäste' => 'gast',
        'gaeste' => 'gast', 'person' => 'gast', 'stunde' => 'stunde', 'std' => 'stunde', 'std.' => 'stunde', 'h' => 'stunde'];

    /** @return array{komponenten:string, attribute:string, notiz:string} */
    public static function alsText(?array $planung): array
    {
        $zahl = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        $je = ['einheit' => 'Einheit', 'gast' => 'Gast', 'stunde' => 'Stunde'];

        return [
            'komponenten' => collect($planung['komponenten'] ?? [])->map(fn ($k) => implode('; ', array_filter([
                $zahl($k['menge'] ?? 0), $je[$k['je'] ?? 'einheit'] ?? 'Einheit', (string) ($k['bezeichnung'] ?? ''),
                isset($k['gp_id']) ? 'GP ' . $k['gp_id'] : (isset($k['container_vocab_id']) ? 'Behälter ' . $k['container_vocab_id'] : null),
            ], fn ($v) => $v !== null)))->implode("\n"),
            'attribute' => collect($planung['attribute'] ?? [])->map(fn ($a) => implode('; ', array_filter([(string) $a['schluessel'], (string) ($a['wert'] ?? ''), $a['einheit'] ?? null],
                fn ($v) => $v !== null)))->implode("\n"),
            'notiz' => (string) ($planung['notiz_intern'] ?? ''),
        ];
    }

    /** @param  array{komponenten?:string, attribute?:string, notiz?:string}  $text */
    public static function ausText(array $text): ?array
    {
        $zeilen = fn (string $f) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($text[$f] ?? '')) ?: []), fn ($z) => $z !== ''));
        $komponenten = [];
        foreach ($zeilen('komponenten') as $z) {
            $teile = array_map('trim', explode(';', $z));
            $je = mb_strtolower(preg_replace('/^je\s+/iu', '', $teile[1] ?? '') ?: 'einheit');
            $bezug = $teile[3] ?? '';
            $komponenten[] = [
                'menge' => $teile[0] ?? '', 'je' => self::JE[$je] ?? $je, 'bezeichnung' => $teile[2] ?? '',
                'gp_id' => preg_match('/^gp\s*(\d+)$/iu', $bezug, $m) === 1 ? (int) $m[1] : null,
                'container_vocab_id' => preg_match('/^beh(?:ä|ae)lter\s*(\d+)$/iu', $bezug, $m) === 1 ? (int) $m[1] : null,
            ];
        }
        $attribute = array_map(function ($z) {
            $teile = array_map('trim', explode(';', $z));

            return ['schluessel' => $teile[0] ?? '', 'wert' => $teile[1] ?? '', 'einheit' => $teile[2] ?? null];
        }, $zeilen('attribute'));
        $notiz = trim((string) ($text['notiz'] ?? ''));

        return $komponenten === [] && $attribute === [] && $notiz === '' ? null
            : ['version' => 1, 'komponenten' => $komponenten, 'attribute' => $attribute, 'notiz_intern' => $notiz];
    }
}
