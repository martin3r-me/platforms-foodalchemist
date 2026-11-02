<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;

/**
 * Spec 94 · Kundensicht des Konfigurators für den Präsentations-Snapshot (Typ `konfigurator`). ALLOWLIST-Neubau:
 * Konzepte, Add-ons, Pakete (von/bis) und eine serverseitig gerechnete Preistabelle — VK pro Person je Paket und
 * Hauptkonzept/Tageszeit, Day-into-Night-Paar und Add-on. Kein EK, keine Aufschlagsklassen, keine Mengen, keine
 * Leistungszeilen (Leak-Test Spec94KundenlinkTest). Der Browser sucht nur das Paket zur Gästezahl und liest ab.
 */
final class KonfiguratorSnapshot
{
    public function __construct(private KonfiguratorPricingService $pricing)
    {
    }

    /** @return array{title:string, subtitle:?string, meta:array, body:array} */
    public function normalize(Team $team, FoodAlchemistKonfigurator $k, array $settings): array
    {
        $s = $this->pricing->stand($k);
        $runde = fn (float $p) => $this->pricing->runde($k, $p);

        $konzepte = [];
        foreach ($s['haupt'] as $h) {
            $konzepte[] = [
                'id' => (int) $h->id,
                'titel' => (string) ($h->kunden_titel ?: 'Konzept'),
                'claim' => $h->claim,
                // nur Identifier einfrieren, die URL signiert der Renderer frisch (wie hydrateImages)
                'bild' => ($h->bild_context_file_id || $h->bild_path) ? ['context_file_id' => $h->bild_context_file_id, 'path' => $h->bild_path] : null,
                'lunch' => $h->lunch_concept_id !== null,
                'dinner' => $h->dinner_concept_id !== null,
                'indikativ_lunch' => (bool) $h->indikativ_lunch,
                'indikativ_dinner' => (bool) $h->indikativ_dinner,
            ];
        }
        $addons = [];
        foreach ($s['addons'] as $a) {
            $addons[] = [
                'id' => (int) $a->id,
                'titel' => (string) ($a->kunden_titel ?: 'Add-on'),
                'claim' => $a->claim,
                'abschnitt' => $a->abschnitt ?: 'Extras',
                'tageszeit' => $a->addon_tageszeit ?: 'beide',
                'gebunden_an' => $a->gebunden_an_id !== null ? (int) $a->gebunden_an_id : null,
            ];
        }

        $pakete = [];
        $preise = [];
        foreach ($s['pakete'] as $i => $p) {
            $pakete[] = ['von' => (int) $p->von_pax, 'bis' => (int) $p->bis_pax, 'name' => $p->anzeigeName()];
            $tabelle = [];
            foreach ($s['haupt'] as $h) {
                foreach (['lunch' => $h->lunch_concept_id, 'dinner' => $h->dinner_concept_id] as $t => $cid) {
                    if ($cid !== null) {
                        $tabelle["k{$h->id}:{$t}"] = $runde($this->pricing->rechneFall($k, $p, ['typ' => 'einzel', 'k' => (int) $h->id, 't' => $t], null, $s)['vk_pp']);
                    }
                }
            }
            foreach ($s['haupt'] as $h1) {
                foreach ($s['haupt'] as $h2) {
                    if ($h1->lunch_concept_id !== null && $h2->dinner_concept_id !== null) {
                        $tabelle["dn{$h1->id}:{$h2->id}"] = $runde($this->pricing->rechneFall($k, $p, ['typ' => 'day_night', 'k1' => (int) $h1->id, 'k2' => (int) $h2->id], null, $s)['vk_pp']);
                    }
                }
            }
            foreach ($s['addons'] as $a) {
                $tabelle["a{$a->id}"] = $runde($this->pricing->rechneFall($k, $p, ['typ' => 'addon', 'a' => (int) $a->id], null, $s)['vk_pp']);
            }
            $preise[$i] = $tabelle;
        }

        // „ab“-Preis je Konzept: günstigste Tageszeit im größten Paket
        $letzte = $preise === [] ? [] : end($preise);
        foreach ($konzepte as &$kz) {
            $kandidaten = array_filter([$letzte["k{$kz['id']}:lunch"] ?? null, $letzte["k{$kz['id']}:dinner"] ?? null], fn ($v) => $v !== null);
            $kz['ab'] = $kandidaten !== [] ? min($kandidaten) : null;
        }
        unset($kz);

        return [
            'title' => (string) ($k->kunden_titel ?: $k->name),
            'subtitle' => $k->claim,
            'meta' => ['kicker' => $k->ort ?: 'Konfigurator', 'ort' => $k->ort, 'gueltig_bis' => $k->gueltig_bis?->format('d.m.Y')],
            'body' => [
                'intro' => $k->intro,
                'konzepte' => $konzepte,
                'addons' => $addons,
                'pakete' => $pakete,
                'preise' => $preise,
                'pax_vorgabe' => (int) $k->pax_vorgabe,
                'tageszeit_aktiv' => (bool) $k->tageszeit_aktiv,
                'kombi_rabatt_pct' => (float) $k->kombi_rabatt_pct,
                'anfrage' => true,
            ],
        ];
    }

    /**
     * Preis einer Auswahl aus der eingefrorenen Preistabelle (für die Anfrage — nie ein Preis aus dem Formular).
     *
     * @param  array{konzept:int, tageszeit:string, abend_konzept?:?int, addons?:list<int>, pax:int}  $auswahl
     * @return array{paket:int, paket_name:string, pro_person:float, gesamt:float}|null
     */
    public static function preisFuer(array $body, array $auswahl): ?array
    {
        $pax = (int) $auswahl['pax'];
        $index = null;
        foreach ($body['pakete'] ?? [] as $i => $p) {
            if ($pax >= $p['von'] && $pax <= $p['bis']) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return null;
        }
        $tabelle = $body['preise'][$index] ?? [];
        $k = (int) $auswahl['konzept'];
        $schluessel = match ($auswahl['tageszeit']) {
            'day_night' => 'dn' . $k . ':' . (int) ($auswahl['abend_konzept'] ?? $k),
            'lunch' => "k{$k}:lunch",
            default => "k{$k}:dinner",
        };
        if (! isset($tabelle[$schluessel])) {
            return null;
        }
        $pp = (float) $tabelle[$schluessel];
        foreach (array_unique(array_map('intval', $auswahl['addons'] ?? [])) as $a) {
            if (! isset($tabelle["a{$a}"])) {
                return null;
            }
            $pp += (float) $tabelle["a{$a}"];
        }

        return ['paket' => $index, 'paket_name' => (string) ($body['pakete'][$index]['name'] ?? ''), 'pro_person' => round($pp, 2), 'gesamt' => round($pp * $pax, 2)];
    }
}
