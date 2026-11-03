<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;

/**
 * Spec 94b §3 · Bedarf einer Konfigurator-Auswahl für die Event-Planung (Übergabe an OLIVIA, kein eigener Planungsbildschirm).
 * Anzahl je Leistung aus der vorhandenen Mengenlogik (KonfiguratorPricingService::rechneFall mit genau dieser Gästezahl, Handwerte
 * gelten weiter), je Tageszeit als Einsatz; Komponenten-Bedarf = Menge × Anzahl (je Einheit) bzw. × Gäste bzw. × Stunden.
 * Verweise über uuid (Leistung, Vorlage, Non-Food-GP, Behälter, Concepts), nicht über Zahl-Ids. Wird am Angebot eingefroren
 * (`konfiguration_json.planung`) und dort nur gelesen.
 */
final class KonfiguratorPlanungService
{
    public const VERSION = 1;

    public function __construct(private KonfiguratorPricingService $pricing)
    {
    }

    /**
     * @param  array{konzept:int, tageszeit:string, abend_konzept?:?int, addons?:list<int>}  $auswahl
     * @return array{version:int, kopf:array, leistungen:list<array>}
     */
    public function bedarf(FoodAlchemistKonfigurator $k, array $auswahl, int $pax, array $kopf = []): array
    {
        $s = $this->pricing->stand($k);
        $paket = collect($s['pakete'])->first(fn ($p) => $pax >= (int) $p->von_pax && $pax <= (int) $p->bis_pax);
        $faelle = [$auswahl['tageszeit'] === 'day_night'
            ? ['typ' => 'day_night', 'k1' => (int) $auswahl['konzept'], 'k2' => (int) ($auswahl['abend_konzept'] ?? $auswahl['konzept'])]
            : ['typ' => 'einzel', 'k' => (int) $auswahl['konzept'], 't' => $auswahl['tageszeit']]];
        foreach ($auswahl['addons'] ?? [] as $a) {
            $faelle[] = ['typ' => 'addon', 'a' => (int) $a];
        }

        $einsaetze = [];
        if ($paket !== null) {
            foreach ($faelle as $fall) {
                foreach ($this->pricing->rechneFall($k, $paket, $fall, $pax, $s)['einsaetze'] as $id => $liste) {
                    $einsaetze[$id] = array_merge($einsaetze[$id] ?? [], $liste);
                }
            }
        }

        $leistungen = [];
        foreach ($einsaetze as $id => $liste) {
            $l = $s['leistungen'][$id];
            $anzahl = round(array_sum(array_column($liste, 'anzahl')), 2);
            if ($anzahl <= 0) {
                continue;
            }
            $stunden = $l->preis_je === 'stunde' ? round(array_sum(array_map(fn ($e) => $e['anzahl'] * (float) $e['stunden'], $liste)), 2) : null;
            $leistungen[] = [
                'uuid' => $l->uuid,
                'vorlage_uuid' => $l->vorlage_id !== null ? FoodAlchemistLeistungVorlage::query()->ohneTeamSicht('verweis')->whereKey($l->vorlage_id)->value('uuid') : null,
                'kategorie' => (string) $l->kategorie,
                'bezeichnung' => (string) $l->bezeichnung,
                'anzahl' => $anzahl,
                'stunden' => $stunden,
                'abrechnung' => (string) ($l->abrechnung ?: 'all_in'),
                'einsaetze' => array_values(array_filter($liste, fn ($e) => $e['anzahl'] > 0)),
                'planung' => $this->planung($l->planung_json, $anzahl, $pax, $stunden),
            ];
        }

        $konzeptIds = array_filter([(int) $auswahl['konzept'], (int) ($auswahl['abend_konzept'] ?? 0), ...array_map('intval', $auswahl['addons'] ?? [])]);
        $conceptIds = collect($konzeptIds)->map(fn ($id) => $s['konzepte'][$id] ?? null)->filter()
            ->flatMap(fn ($kz) => [$kz->lunch_concept_id, $kz->dinner_concept_id])->filter()->unique()->values()->all();

        return [
            'version' => self::VERSION,
            'kopf' => $kopf + [
                'gaeste' => $pax,
                'tageszeit' => $auswahl['tageszeit'],
                'paket' => $paket?->anzeigeName(),
                'concepts' => FoodAlchemistConcept::query()->ohneTeamSicht('verweis')->whereIn('id', $conceptIds)->pluck('uuid')->filter()->values()->all(),
            ],
            'leistungen' => $leistungen,
        ];
    }

    /** planung_json der Leistung unverändert plus je Komponente Bedarf und uuid-Verweise; null ohne Planungsdetails. */
    private function planung(?array $p, float $anzahl, int $pax, ?float $stunden): ?array
    {
        if ($p === null) {
            return null;
        }
        $p['komponenten'] = array_map(function ($c) use ($anzahl, $pax, $stunden) {
            $faktor = match ($c['je'] ?? 'einheit') {
                'gast' => (float) $pax,
                'stunde' => $stunden,
                default => $anzahl,
            };
            $c['bedarf'] = $faktor !== null ? round((float) $c['menge'] * $faktor, 2) : null;
            if (isset($c['gp_id'])) {
                $c['gp_uuid'] = FoodAlchemistGp::query()->ohneTeamSicht('verweis')->whereKey($c['gp_id'])->value('uuid');
            }
            if (isset($c['container_vocab_id'])) {
                $c['container_uuid'] = FoodAlchemistVocabContainer::query()->ohneTeamSicht('verweis')->whereKey($c['container_vocab_id'])->value('uuid');
            }

            return $c;
        }, $p['komponenten'] ?? []);

        return $p;
    }
}
