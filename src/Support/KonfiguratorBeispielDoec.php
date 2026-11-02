<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\LeistungVorlageService;

/**
 * Spec 94 · Beispiel-Datensatz nach dem Vorbild DOEC „Diva.licious“ (OceanDiva 2026, `DOEC_AllIn_Preise_v2.xlsx`):
 * Leistungs-Vorlagen (Personal, Logistik, Equipment) mit den Mengenregeln der DOEC-Parameter, ein Konfigurator mit
 * Grundausstattung aus diesen Vorlagen und den vier DOEC-Paketen. Die Stundensätze sind Beispielwerte (in der Excel steht
 * nur die Summe je Stufe) und vor echtem Einsatz mit DOEC abzustimmen; der VK ist fest EK × 2,5 wie in der Excel.
 * Concepts verknüpft der Mensch im Editor (oder über `$konzepte`).
 */
final class KonfiguratorBeispielDoec
{
    private const BEGRUENDUNG = 'DOEC-Kalkulation: Service-Aufschlag 2,5× auf den EK (Beispielwert)';

    public const PAKETE = [[100, 199], [200, 499], [500, 799], [800, 1200]];

    /** @return list<array<string,mixed>> */
    public static function vorlagen(): array
    {
        $std = ['preis_je' => 'stunde', 'stunden_quelle' => 'einsatzzeit', 'haeufigkeit' => 'je_tageszeit'];

        return [
            ['kategorie' => 'personal', 'bezeichnung' => 'Servicekraft (Self-Service-Stationen)', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 30, 'ek_satz' => 32] + $std,
            ['kategorie' => 'personal', 'bezeichnung' => 'Küchenchef', 'mengen_regel' => 'fest', 'anzahl' => 1, 'ab_pax' => 100, 'ek_satz' => 55] + $std,
            ['kategorie' => 'personal', 'bezeichnung' => 'Sous Chef', 'mengen_regel' => 'stufen', 'stufen_json' => [['bis_pax' => 299, 'anzahl' => 1], ['bis_pax' => null, 'anzahl' => 2]], 'ek_satz' => 42] + $std,
            ['kategorie' => 'personal', 'bezeichnung' => 'F&B-Manager', 'mengen_regel' => 'fest', 'anzahl' => 1, 'ab_pax' => 200, 'ek_satz' => 50, 'haeufigkeit' => 'je_event'] + $std,
            ['kategorie' => 'personal', 'bezeichnung' => 'Auf- und Abbau-Crew (4 h + 3 h)', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 200, 'min' => 2,
                'preis_je' => 'stunde', 'stunden_quelle' => 'fest', 'stunden' => 7, 'haeufigkeit' => 'je_event', 'ek_satz' => 28],
            ['kategorie' => 'logistik', 'bezeichnung' => 'LKW-Tour Köln, 200 km (Maut, Diesel, Fahrer 6 h)', 'mengen_regel' => 'stufen',
                'stufen_json' => [['bis_pax' => 199, 'anzahl' => 1], ['bis_pax' => 799, 'anzahl' => 2], ['bis_pax' => null, 'anzahl' => 3]],
                'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 808],
            ['kategorie' => 'equipment', 'bezeichnung' => 'Equipment-Set Premium (je Gast)', 'mengen_regel' => 'schluessel', 'bezug' => 'gaeste', 'schluessel_n' => 1,
                'preis_je' => 'stueck', 'haeufigkeit' => 'je_event', 'ek_satz' => 6.2],
        ];
    }

    /**
     * Legt Vorlagen, Konfigurator, Grundausstattung und Pakete im Team an.
     *
     * @param  array<string, array{lunch?:int, dinner?:int}>  $konzepte  Kundentitel → Concept-Ids (optional)
     */
    public function anlegen(Team $team, array $konzepte = []): FoodAlchemistKonfigurator
    {
        $vorlagen = app(LeistungVorlageService::class);
        $konfig = app(KonfiguratorService::class);

        return DB::transaction(function () use ($team, $konzepte, $vorlagen, $konfig) {
            $ids = [];
            foreach (self::vorlagen() as $i => $v) {
                $ids[] = $vorlagen->anlegen($team, $v + ['position' => $i, 'vk_modus' => 'fixed', 'vk_satz' => round($v['ek_satz'] * 2.5, 2),
                    'vk_begruendung' => self::BEGRUENDUNG, 'notiz' => 'Beispiel DOEC Diva.licious, Sätze mit DOEC abstimmen'])->id;
            }
            $k = $konfig->anlegen($team, [
                'name' => 'Diva.licious 2026 (Beispiel DOEC)', 'kunden_titel' => 'Diva.licious', 'claim' => 'Connecting People by Flavour',
                'ort' => 'Köln, OceanDiva', 'pax_vorgabe' => 150, 'stunden_lunch' => 8, 'stunden_dinner' => 8, 'stunden_day_night' => 14,
                'kombi_rabatt_pct' => 5, 'paket_toleranz_pct' => 10, 'rundung' => 0.01,
            ]);
            foreach ($konzepte as $titel => $c) {
                $konfig->konzeptHinzu($team, $k->id, ['rolle' => 'haupt', 'kunden_titel' => $titel,
                    'lunch_concept_id' => $c['lunch'] ?? null, 'dinner_concept_id' => $c['dinner'] ?? null]);
            }
            $konfig->ausVorlagen($team, $k->id, $ids);
            foreach (self::PAKETE as [$von, $bis]) {
                $konfig->paketAnlegen($team, $k->id, ['von_pax' => $von, 'bis_pax' => $bis]);
            }

            return $k->fresh();
        });
    }
}
