<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPrinter;

/**
 * Spec 78 · Druckerprofile. Ein Drucker legt Format (Bogen/Rolle, Maß) und Feinkorrektur der Ränder fest; eine
 * Etikettenvorlage mit Drucker übernimmt beides. Gedruckt wird (Stufe 1) über den Druckdialog des Browsers.
 */
class DruckerService
{
    /** Kompatibilitätsliste: Modellgruppe → Typ + vorbelegtes Format (Keys aus EtikettService::FORMATE oder „eigen"). */
    public const MODELLE = [
        'brother_ql' => ['label' => 'Brother QL (Rolle 62 mm)', 'typ' => 'rolle', 'format' => 'rolle_62'],
        'zebra_zd' => ['label' => 'Zebra ZD (Rolle 4 Zoll)', 'typ' => 'rolle', 'format' => 'eigen', 'breite_mm' => 101.6, 'hoehe_mm' => 76.2],
        'dymo_lw' => ['label' => 'DYMO LabelWriter (54 × 25 mm)', 'typ' => 'rolle', 'format' => 'dymo_54'],
        'a4_buero' => ['label' => 'A4-Bürodrucker (Etikettenbögen)', 'typ' => 'bogen', 'format' => 'a4_24'],
        'eigen' => ['label' => 'Anderes Gerät (eigenes Maß)', 'typ' => 'rolle', 'format' => 'eigen'],
    ];

    public const ARBEITSPLAETZE = ['kueche' => 'Küche', 'lager' => 'Lager', 'buero' => 'Büro'];

    /** @return Collection<int, FoodAlchemistPrinter> */
    public function liste(Team $team): Collection
    {
        return FoodAlchemistPrinter::where('team_id', $team->id)->orderBy('arbeitsplatz')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $d */
    public function speichern(Team $team, ?int $id, array $d): FoodAlchemistPrinter
    {
        $p = $id !== null ? FoodAlchemistPrinter::where('team_id', $team->id)->findOrFail($id) : new FoodAlchemistPrinter(['team_id' => $team->id]);
        $name = trim((string) ($d['name'] ?? $p->name ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Der Drucker braucht einen Namen.');
        }
        $modell = array_key_exists((string) ($d['modell'] ?? ''), self::MODELLE) ? (string) $d['modell'] : ($p->modell ?: 'eigen');
        $vorgabe = self::MODELLE[$modell];
        $format = (string) ($d['format'] ?? '') ?: ($p->exists && $p->modell === $modell ? $p->format : $vorgabe['format']);
        if ($format !== 'eigen' && ! array_key_exists($format, EtikettService::FORMATE)) {
            throw new \RuntimeException('Unbekanntes Format.');
        }
        $mm = function (mixed $v, string $was, float $min, float $max): ?float {
            $roh = trim(str_replace(',', '.', (string) ($v ?? '')));
            if ($roh === '') {
                return null;
            }
            if (! is_numeric($roh) || (float) $roh < $min || (float) $roh > $max) {
                throw new \RuntimeException("{$was}: Zahl zwischen {$min} und {$max} mm.");
            }

            return round((float) $roh, 1);
        };
        $breite = $mm($d['breite_mm'] ?? ($format === 'eigen' ? ($vorgabe['breite_mm'] ?? null) : null), 'Breite', 15, 220);
        $hoehe = $mm($d['hoehe_mm'] ?? ($format === 'eigen' ? ($vorgabe['hoehe_mm'] ?? null) : null), 'Höhe', 10, 300);
        if ($format === 'eigen' && ($breite === null || $hoehe === null)) {
            throw new \RuntimeException('Bei eigenem Format bitte Breite und Höhe angeben.');
        }
        $arbeitsplatz = array_key_exists((string) ($d['arbeitsplatz'] ?? ''), self::ARBEITSPLAETZE) ? (string) $d['arbeitsplatz'] : null;
        $p->fill([
            'name' => mb_substr($name, 0, 120), 'modell' => $modell, 'format' => $format,
            'breite_mm' => $format === 'eigen' ? $breite : null, 'hoehe_mm' => $format === 'eigen' ? $hoehe : null,
            'versatz_x_mm' => $mm($d['versatz_x_mm'] ?? 0, 'Versatz links/rechts', -20, 20) ?? 0.0,
            'versatz_y_mm' => $mm($d['versatz_y_mm'] ?? 0, 'Versatz oben/unten', -20, 20) ?? 0.0,
            'outlet_id' => ! empty($d['outlet_id']) ? (int) $d['outlet_id'] : null,
            'arbeitsplatz' => $arbeitsplatz, 'is_default' => (bool) ($d['is_default'] ?? false) && $arbeitsplatz !== null,
            'notiz' => isset($d['notiz']) && trim((string) $d['notiz']) !== '' ? mb_substr(trim((string) $d['notiz']), 0, 200) : null,
        ]);
        $p->save();
        if ($p->is_default) {   // ein Standard je Arbeitsplatz
            FoodAlchemistPrinter::where('team_id', $team->id)->where('arbeitsplatz', $p->arbeitsplatz)->whereKeyNot($p->id)->update(['is_default' => false]);
        }

        return $p->refresh();
    }

    public function loeschen(Team $team, int $id): void
    {
        $p = FoodAlchemistPrinter::where('team_id', $team->id)->findOrFail($id);
        \Platform\FoodAlchemist\Models\FoodAlchemistLabelTemplate::where('team_id', $team->id)->where('printer_id', $p->id)->update(['printer_id' => null]);
        $p->delete();
    }

    /**
     * Wirksames Format eines Druckers im Schema von EtikettService::FORMATE (+ Versatz).
     *
     * @return array{label:string, bogen:bool, b:float, h:float, spalten?:int, zeilen?:int, versatz_x:float, versatz_y:float}
     */
    public function format(FoodAlchemistPrinter $p): array
    {
        $basis = $p->format !== 'eigen' && isset(EtikettService::FORMATE[$p->format])
            ? EtikettService::FORMATE[$p->format]
            : ['label' => 'Eigenes Format', 'bogen' => false, 'b' => (float) $p->breite_mm, 'h' => (float) $p->hoehe_mm];

        return $basis + ['versatz_x' => (float) $p->versatz_x_mm, 'versatz_y' => (float) $p->versatz_y_mm];
    }
}
