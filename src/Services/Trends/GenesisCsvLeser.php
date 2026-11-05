<?php

namespace Platform\FoodAlchemist\Services\Trends;

/** GENESIS Flat-File-CSV; unbekannte Strukturen werden abgelehnt, nie geraten. */
class GenesisCsvLeser
{
    /**
     * @param  string  $wertLabel  Teil des value_variable_label in Kleinbuchstaben (z. B. „verbraucherpreisindex“, „real“)
     * @param  list<string>  $zusatz  weitere Schlüssel, die in der Zeile stehen müssen (z. B. Originalwert statt bereinigt)
     * @param  string|null  $wertCode  value_variable_code (z. B. PRE011); gesetzt ⇒ ersetzt den Label-Filter
     */
    public function lesen(string $csv, string $code, string $wertLabel = 'verbraucherpreisindex', array $zusatz = [], ?string $wertCode = null): array
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($f);
        try {
            $kopf = fgetcsv($f, 0, ';', '"', '');
            if (! is_array($kopf) || array_diff(['time', 'value', 'value_unit', 'value_variable_label'], $kopf)) {
                throw new \RuntimeException('GENESIS liefert kein unterstütztes Flat-File-CSV. Zugang oder Tabellenformat prüfen.');
            }
            $punkte = [];
            while (($felder = fgetcsv($f, 0, ';', '"', '')) !== false) {
                if (count($felder) !== count($kopf)) {
                    if ($felder === [null]) {
                        continue;
                    }
                    throw new \RuntimeException('GENESIS-CSV enthält eine unvollständige Zeile.');
                }
                $r = array_combine($kopf, $felder);
                $codes = array_filter($r, fn ($k) => str_ends_with($k, '_variable_attribute_code'), ARRAY_FILTER_USE_KEY);
                if (! in_array($code, $codes, true) || array_diff($zusatz, $codes) !== []) {
                    continue;
                }
                if ($wertCode !== null ? ($r['value_variable_code'] ?? null) !== $wertCode : ! str_contains(mb_strtolower($r['value_variable_label']), mb_strtolower($wertLabel))) {
                    continue;
                }
                $monat = null;
                if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $r['time'])) {
                    $monat = $r['time'];
                } elseif (preg_match('/^\d{4}$/', $r['time'])) {
                    foreach ($codes as $c) {
                        if (preg_match('/^MONAT(0[1-9]|1[0-2])$/', $c, $m)) {
                            $monat = $r['time'].'-'.$m[1];
                        }
                    }
                }
                if ($monat === null || trim($r['value_unit']) === '') {
                    throw new \RuntimeException('GENESIS-Monat oder Indexbasis nicht eindeutig. Keine Werte übernommen.');
                }
                if ($monat > now()->format('Y-m')) {
                    continue;
                }
                $roh = trim($r['value']);
                $wert = preg_match('/^-?\d+(?:[,.]\d+)?$/D', $roh) ? (float) str_replace(',', '.', $roh) : null;
                if (isset($punkte[$monat])) {
                    throw new \RuntimeException('Mehrere GENESIS-Werte für denselben Monat. Keine Reihen vermischen.');
                }
                $punkte[$monat] = ['monat' => $monat, 'wert' => $wert, 'einheit' => $r['value_unit'], 'original' => $r];
            }
            if ($punkte === []) {
                throw new \RuntimeException('Keine passenden monatlichen GENESIS-Indexwerte erkannt.');
            }
            ksort($punkte);

            return array_values($punkte);
        } finally {
            fclose($f);
        }
    }
}
