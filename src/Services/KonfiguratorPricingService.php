<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorPaketMenge;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorMengenRechner;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorPaketVorschlag;

/**
 * Spec 94 · Die eine Rechnung des Konfigurators: VK-Vorschlag der Leistungen, Pakete schnüren, All-in je Konzept,
 * Tageszeit und Pax-Paket, Day-into-Night, Add-ons, Randkontrolle und Paketgrenzen-Vorschlag (deterministisch, ohne KI).
 * Oberfläche, MCP-Tools und Kundenlink rufen nur diesen Service; Speisenpreise liest er aus dem Concept.
 *
 * Tageszeiten: `lunch`, `dinner`, `day_night`. Ein „Fall“ ist ein Hauptkonzept zu einer Tageszeit, ein Day-into-Night-Paar
 * (Lunch von k1, Dinner von k2) oder ein Add-on.
 */
class KonfiguratorPricingService
{
    public function __construct(
        private CatalogPricingService $katalog,
        private KonfiguratorMengenRechner $rechner,
        private KonfiguratorPaketVorschlag $vorschlag,
    ) {
    }

    // ── VK der Leistungen ────────────────────────────────────────────────────────

    /**
     * VK-Vorschlag aus Aufschlagsklasse (Bereich `leistung`) und Basissatz — dieselbe Formel wie bei den Speisen.
     *
     * @return array{vorschlag:?float, aufschlag_pct:?float, klasse_id:?int, warnungen:list<string>}
     */
    public function vkVorschlag(Team $team, float $ekSatz, ?int $markupClassId): array
    {
        $klasse = $markupClassId !== null ? FoodAlchemistMarkupClass::visibleToTeam($team)->whereKey($markupClassId)->first() : null;
        $r = $this->katalog->klassenVk($team, $ekSatz, $klasse, $this->katalog->enterpriseBaseRate($team), 'leistung');

        return [
            'vorschlag' => $r['calculated'],
            'aufschlag_pct' => $r['calculated'] !== null && $ekSatz > 0 ? round(($r['calculated'] / $ekSatz - 1) * 100, 1) : null,
            'klasse_id' => $r['class']?->id,
            'warnungen' => $r['warnings'],
        ];
    }

    /** Gültiger VK-Satz einer Vorlage/Leistung: fest bei `fixed`, sonst Vorschlag aus der Klasse. */
    public function vkSatz(Team $team, object $leistung): ?float
    {
        if (($leistung->vk_modus ?? 'auto') === 'fixed') {
            return $leistung->vk_satz !== null ? round((float) $leistung->vk_satz, 2) : null;
        }

        return $this->vkVorschlag($team, (float) $leistung->ek_satz, $leistung->markup_class_id !== null ? (int) $leistung->markup_class_id : null)['vorschlag'];
    }

    // ── Pakete ───────────────────────────────────────────────────────────────────

    /** Referenz-Pax eines Pakets: Mitte des Bereichs, abgerundet. */
    public function referenzPax(int $von, int $bis): int
    {
        return intdiv($von + $bis, 2);
    }

    /**
     * Pakete schnüren: Mengenregeln auf jedes Paket (oder eines) anwenden, je Grundausstattung, Hauptkonzept und Add-on.
     * Schreibt `regel_anzahl` immer, `anzahl` nur ohne Handwert. Idempotent. Setzt die Referenz-Pax neu, wenn sie nicht
     * von Hand gesetzt ist.
     *
     * @return array{pakete:int, zeilen:int, abweichungen:list<array{paket_id:int, leistung_id:int, konzept_id:?int, anzahl:float, regel:float}>}
     */
    public function schnueren(Team $team, FoodAlchemistKonfigurator $konfigurator, ?int $paketId = null): array
    {
        GeerbtNurLesenException::wennNichtEigen($konfigurator, $team, 'Konfigurator');
        $s = $this->stand($konfigurator);
        $abweichungen = [];
        $zeilen = 0;
        $anzahlPakete = 0;

        foreach ($s['pakete'] as $paket) {
            if ($paketId !== null && (int) $paket->id !== $paketId) {
                continue;
            }
            $anzahlPakete++;
            if (! $paket->referenz_manuell) {
                $paket->referenz_pax = $this->referenzPax((int) $paket->von_pax, (int) $paket->bis_pax);
            }
            $r = (int) $paket->referenz_pax;
            $soll = [];   // "leistung|kontext" => [anzahl-regel, kontext]

            [$grundSatz] = $this->satz($s, null, null);
            $basis = $this->rechner->rechne($s['leistungen'], $grundSatz, [], $r, $this->handwert($s, $paket, null));
            foreach ($basis as $id => $w) {
                $soll[$id . '|0'] = [$w['regel'], null];
            }
            foreach (array_merge($s['haupt'], $s['addons']) as $konzept) {
                [$satz, $ersetzt] = $this->satz($s, $konzept, null);
                $erg = $this->rechner->rechne($s['leistungen'], $satz, $ersetzt, $r, $this->handwert($s, $paket, (int) $konzept->id));
                foreach ($erg as $id => $w) {
                    $eigen = (int) ($s['leistungen'][$id]->konfigurator_konzept_id ?? 0) === (int) $konzept->id;
                    $abweichend = ! isset($basis[$id]) || $basis[$id]['regel'] !== $w['regel'];
                    if ($eigen || $abweichend || isset($s['mengen'][$paket->id][$id . '|' . $konzept->id])) {
                        $soll[$id . '|' . $konzept->id] = [$w['regel'], (int) $konzept->id];
                    }
                }
            }

            $bestand = $s['mengen'][$paket->id] ?? [];
            foreach ($soll as $schluessel => [$regel, $kontext]) {
                $leistungId = (int) explode('|', $schluessel)[0];
                $zeile = $bestand[$schluessel] ?? new FoodAlchemistKonfiguratorPaketMenge([
                    'team_id' => $konfigurator->team_id, 'paket_id' => $paket->id, 'leistung_id' => $leistungId,
                    'konfigurator_konzept_id' => $kontext, 'manuell' => false,
                ]);
                $zeile->regel_anzahl = $regel;
                if (! $zeile->manuell) {
                    $zeile->anzahl = $regel;
                } elseif ((float) $zeile->anzahl !== $regel) {
                    $abweichungen[] = ['paket_id' => (int) $paket->id, 'leistung_id' => $leistungId, 'konzept_id' => $kontext,
                        'anzahl' => (float) $zeile->anzahl, 'regel' => $regel];
                }
                $zeile->save();
                $zeilen++;
                unset($bestand[$schluessel]);
            }
            // Zeilen ohne Leistung im Satz mehr (gelöscht, umgehängt) räumen — Handwerte eingeschlossen, sie gehören zu nichts mehr.
            foreach ($bestand as $alt) {
                $alt->delete();   // Matrix-Zelle ohne SoftDeletes: hart gelöscht
            }
            $paket->geschnuert_at = now();
            $paket->save();
        }

        return ['pakete' => $anzahlPakete, 'zeilen' => $zeilen, 'abweichungen' => $abweichungen];
    }

    // ── Preise ───────────────────────────────────────────────────────────────────

    /**
     * Preis eines Falls in einem Paket. Ohne `$pax`: feste Paketmengen, geteilt durch die Referenz-Pax. Mit `$pax`: Mengen
     * nach Regel für genau diese Gästezahl (Handwerte gelten weiter), geteilt durch `$pax` — für Rand und Vorschlag.
     *
     * @param  array{typ:string, k?:int, t?:string, k1?:int, k2?:int, a?:int}  $fall
     * `gruppen` (Spec 94b): VK und EK pro Person je Speisen-Teil (speisen_tag | speisen_abend | speisen_extras) und je
     * Leistungskategorie (art kategorie, slug); Summe = vk_pp bzw. ek_pp. Der Kombi-Rabatt geht anteilig auf alle Gruppen.
     *
     * @return array{speisen_vk:float, speisen_ek:float, leistungen_vk:float, leistungen_ek:float, teiler:int, vk_pp:float, ek_pp:float, speisen_fehlen:bool, ungeschnuert:bool, gruppen:list<array{art:string, slug:?string, vk_pp:float, ek_pp:float}>}
     */
    public function rechneFall(FoodAlchemistKonfigurator $konfigurator, ?FoodAlchemistKonfiguratorPaket $paket, array $fall, ?int $pax = null, ?array $stand = null): array
    {
        $s = $stand ?? $this->stand($konfigurator);
        $teiler = $pax ?? (int) $paket->referenz_pax;
        $ungeschnuert = false;
        $posten = [];   // je Leistung: [anzahl, stunden-tageszeit]

        $mengen = function (?object $konzept, ?string $t) use ($s, $paket, $pax, &$ungeschnuert): array {
            [$satz, $ersetzt] = $this->satz($s, $konzept, $t);
            $kontext = $konzept !== null ? (int) $konzept->id : null;
            if ($pax !== null) {
                $erg = $this->rechner->rechne($s['leistungen'], $satz, $ersetzt, $pax, $paket !== null ? $this->handwert($s, $paket, $kontext) : null);

                return array_map(fn ($w) => $w['anzahl'], $erg);
            }
            $aus = [];
            foreach ($satz as $id) {
                $zeile = $s['mengen'][$paket->id][$id . '|' . ($kontext ?? 0)] ?? $s['mengen'][$paket->id][$id . '|0'] ?? null;
                $ungeschnuert = $ungeschnuert || $zeile === null;
                $aus[$id] = $zeile !== null ? (float) $zeile->anzahl : 0.0;
            }

            return $aus;
        };

        $speisenVk = 0.0;
        $speisenEk = 0.0;
        $speisenFehlen = false;
        $gruppen = [];   // Schlüssel → [art, slug, vk, ek] (Speisen je Person, Leistungen gesamt — unten durch den Teiler)
        $speise = function (?int $conceptId, string $art) use ($s, &$speisenVk, &$speisenEk, &$speisenFehlen, &$gruppen): void {
            $p = $conceptId !== null ? ($s['speisen'][$conceptId] ?? null) : null;
            $speisenFehlen = $speisenFehlen || $p === null || $p['vk'] === null;
            $speisenVk += (float) ($p['vk'] ?? 0);
            $speisenEk += (float) ($p['ek'] ?? 0);
            $gruppen[$art] ??= [$art, null, 0.0, 0.0];
            $gruppen[$art][2] += (float) ($p['vk'] ?? 0);
            $gruppen[$art][3] += (float) ($p['ek'] ?? 0);
        };

        if ($fall['typ'] === 'addon') {
            $a = $s['konzepte'][$fall['a']];
            $speise($a->dinner_concept_id ?? $a->lunch_concept_id, 'speisen_extras');
            foreach ($mengen($a, null) as $id => $n) {
                $posten[] = [$id, $n, $a->addon_tageszeit === 'lunch' ? 'lunch' : 'dinner'];
            }
        } elseif ($fall['typ'] === 'day_night') {
            $k1 = $s['konzepte'][$fall['k1']];
            $k2 = $s['konzepte'][$fall['k2']];
            $speise($k1->lunch_concept_id, 'speisen_tag');
            $speise($k2->dinner_concept_id, 'speisen_abend');
            $lunch = $mengen($k1, 'lunch');
            $dinner = $mengen($k2, 'dinner');
            foreach (array_unique(array_merge(array_keys($lunch), array_keys($dinner))) as $id) {
                if ($s['leistungen'][$id]->haeufigkeit === 'je_event') {
                    $posten[] = [$id, max($lunch[$id] ?? 0.0, $dinner[$id] ?? 0.0), 'day_night'];   // einmal, eine durchgehende Schicht
                } else {
                    if (isset($lunch[$id])) {
                        $posten[] = [$id, $lunch[$id], 'lunch'];
                    }
                    if (isset($dinner[$id])) {
                        $posten[] = [$id, $dinner[$id], 'dinner'];
                    }
                }
            }
        } else {
            $k = $s['konzepte'][$fall['k']];
            $speise($fall['t'] === 'lunch' ? $k->lunch_concept_id : $k->dinner_concept_id, $fall['t'] === 'lunch' ? 'speisen_tag' : 'speisen_abend');
            foreach ($mengen($k, $fall['t']) as $id => $n) {
                $posten[] = [$id, $n, $fall['t']];
            }
        }

        $lVk = 0.0;
        $lEk = 0.0;
        foreach ($posten as [$id, $n, $t]) {
            $l = $s['leistungen'][$id];
            $menge = $l->preis_je === 'stunde' ? $n * $this->stunden($s['k'], $l, $t) : $n;
            $lVk += $menge * (float) ($l->vk_satz ?? 0);
            $lEk += $menge * (float) $l->ek_satz;
            $g = 'k:' . $l->kategorie;
            $gruppen[$g] ??= ['kategorie', (string) $l->kategorie, 0.0, 0.0];
            $gruppen[$g][2] += $teiler > 0 ? $menge * (float) ($l->vk_satz ?? 0) / $teiler : 0.0;
            $gruppen[$g][3] += $teiler > 0 ? $menge * (float) $l->ek_satz / $teiler : 0.0;
        }

        $vkPp = $teiler > 0 ? $speisenVk + $lVk / $teiler : 0.0;
        $ekPp = $teiler > 0 ? $speisenEk + $lEk / $teiler : 0.0;
        $rabatt = $fall['typ'] === 'day_night' ? 1 - (float) $s['k']->kombi_rabatt_pct / 100 : 1.0;
        $vkPp *= $rabatt;   // Kombi-Rabatt auf den ganzen Konzeptanteil (Speisen + Leistungen, nicht Add-ons), EK bleibt

        return ['speisen_vk' => round($speisenVk, 4), 'speisen_ek' => round($speisenEk, 4), 'leistungen_vk' => round($lVk, 4), 'leistungen_ek' => round($lEk, 4),
            'teiler' => $teiler, 'vk_pp' => $vkPp, 'ek_pp' => $ekPp, 'speisen_fehlen' => $speisenFehlen, 'ungeschnuert' => $ungeschnuert,
            'gruppen' => array_values(array_map(fn ($g) => ['art' => $g[0], 'slug' => $g[1], 'vk_pp' => $teiler > 0 ? $g[2] * $rabatt : 0.0, 'ek_pp' => $teiler > 0 ? $g[3] : 0.0], $gruppen))];
    }

    /** Stunden einer Leistung zu einer Tageszeit: feste Stunden oder Einsatzzeit des Konfigurators plus Zuschlag. */
    public function stunden(FoodAlchemistKonfigurator $konfigurator, object $leistung, string $t): float
    {
        if ($leistung->stunden_quelle === 'fest') {
            return (float) ($leistung->stunden ?? 0);
        }
        $einsatz = match ($t) {
            'lunch' => $konfigurator->stunden_lunch,
            'dinner' => $konfigurator->stunden_dinner,
            default => $konfigurator->stunden_day_night,
        };

        return (float) $einsatz + (float) ($leistung->stunden_zuschlag ?? 0);
    }

    /** Preis pro Person auf die Rundung des Konfigurators (0,01 | 0,10 | 0,50 | 1,00). */
    public function runde(FoodAlchemistKonfigurator $konfigurator, float $preis): float
    {
        $schritt = (float) $konfigurator->rundung > 0 ? (float) $konfigurator->rundung : 0.01;

        return round(round($preis / $schritt) * $schritt, 2);
    }

    /**
     * Preisvorschau intern: je Paket und Fall Paketpreis, EK, DB, und an Unter- und Obergrenze der genaue Preis, die
     * Abweichung und der DB am Rand. Warnungen bei Toleranz, negativem DB, fehlenden Speisenpreisen, ungeschnürten Paketen.
     *
     * @return array{pakete:list<array>, warnungen:list<string>}
     */
    public function matrix(FoodAlchemistKonfigurator $konfigurator): array
    {
        $s = $this->stand($konfigurator);
        $toleranz = (float) $konfigurator->paket_toleranz_pct / 100;
        $warnungen = [];
        if ($s['pakete'] === []) {
            $warnungen[] = 'Noch keine Pax-Pakete: ohne Pakete gibt es keine Preise und keinen Link.';
        }
        foreach ($s['leistungen'] as $l) {
            if ((float) $l->ek_satz <= 0) {
                $warnungen[] = "Leistung „{$l->bezeichnung}“ hat keinen EK-Satz.";
            }
            if ($l->vk_satz === null) {
                $warnungen[] = "Leistung „{$l->bezeichnung}“ hat keinen VK-Satz (Basissatz oder Klasse fehlt).";
            } elseif ((float) $l->vk_satz < (float) $l->ek_satz) {
                $warnungen[] = "Leistung „{$l->bezeichnung}“: VK unter EK.";
            }
        }

        $namen = app(\Platform\FoodAlchemist\Services\Konfigurator\LeistungsKategorieService::class)->namen(\Platform\Core\Models\Team::findOrFail($s['k']->team_id));
        $pakete = [];
        foreach ($s['pakete'] as $paket) {
            $faelle = [];
            foreach ($this->faelle($s) as $fall) {
                $kern = $this->rechneFall($konfigurator, $paket, $fall, null, $s);
                $preis = $this->runde($konfigurator, $kern['vk_pp']);
                $rand = [];
                foreach (['von' => (int) $paket->von_pax, 'bis' => (int) $paket->bis_pax] as $seite => $p) {
                    $genau = $this->rechneFall($konfigurator, $paket, $fall, $p, $s);
                    $abw = $genau['vk_pp'] > 0 ? ($preis - $genau['vk_pp']) / $genau['vk_pp'] : null;
                    $rand[$seite] = ['pax' => $p, 'genau' => round($genau['vk_pp'], 2), 'abweichung_pct' => $abw !== null ? round($abw * 100, 1) : null,
                        'db' => round($preis - $genau['ek_pp'], 2)];
                    if ($abw !== null && abs($abw) > $toleranz + 1e-9) {
                        $warnungen[] = "{$paket->anzeigeName()} · {$this->fallName($s, $fall)}: bei {$p} Gästen weicht der Paketpreis um " . round($abw * 100, 1) . ' % vom genauen Preis ab.';
                    }
                    if ($rand[$seite]['db'] < 0) {
                        $warnungen[] = "{$paket->anzeigeName()} · {$this->fallName($s, $fall)}: negativer Deckungsbeitrag bei {$p} Gästen.";
                    }
                }
                if ($kern['speisen_fehlen']) {
                    $warnungen[] = "{$this->fallName($s, $fall)}: Speisenpreis im Concept fehlt.";
                }
                if ($kern['ungeschnuert']) {
                    $warnungen[] = "{$paket->anzeigeName()}: noch nicht geschnürt.";
                }
                $faelle[] = ['fall' => $fall, 'name' => $this->fallName($s, $fall), 'preis_pp' => $preis, 'ek_pp' => round($kern['ek_pp'], 2),
                    'db_pp' => round($preis - $kern['ek_pp'], 2), 'speisen_vk' => $kern['speisen_vk'], 'leistungen_vk_pp' => $kern['teiler'] > 0 ? round($kern['leistungen_vk'] / $kern['teiler'], 2) : 0.0,
                    'rand' => $rand,
                    // Spec 94b §5: Zusammenfassung je Gruppe (Speisen Tag/Abend/Extras, je Kategorie), Summe = preis_pp
                    'gruppen' => array_map(fn ($g) => $g + ['db_pp' => round($g['vk_pp'] - $g['ek_pp'], 2)],
                        \Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorAufteilung::aufteilen($kern['gruppen'], $preis, $namen))];
            }
            $pakete[] = ['paket_id' => (int) $paket->id, 'name' => $paket->anzeigeName(), 'von_pax' => (int) $paket->von_pax, 'bis_pax' => (int) $paket->bis_pax,
                'referenz_pax' => (int) $paket->referenz_pax, 'faelle' => $faelle];
        }

        return ['pakete' => $pakete, 'warnungen' => array_values(array_unique($warnungen))];
    }

    /**
     * Paketgrenzen vorschlagen (deterministisch, ohne KI): Pakete so breit wie möglich, solange der Preis an der
     * Referenz-Pax (Mitte) für jede Gästezahl im Paket und jeden Fall höchstens um die Toleranz vom genauen Preis abweicht.
     * Schreibt nichts.
     *
     * @return list<array{von_pax:int, bis_pax:int, referenz_pax:int, max_abweichung_pct:float, toleranz_gehalten:bool}>
     */
    public function paketgrenzenVorschlagen(FoodAlchemistKonfigurator $konfigurator, int $von, int $bis, ?float $toleranzPct = null, int $raster = 25, int $mindestbreite = 25): array
    {
        $s = $this->stand($konfigurator);
        $faelle = $this->faelle($s);
        $kurven = [];
        foreach ($faelle as $i => $fall) {
            for ($p = $von; $p <= $bis; $p++) {
                $kurven[$i][$p] = $this->rechneFall($konfigurator, null, $fall, $p, $s)['vk_pp'];
            }
        }

        return $this->vorschlag->grenzen($kurven, $von, $bis, ($toleranzPct ?? (float) $konfigurator->paket_toleranz_pct) / 100, $raster, $mindestbreite,
            fn (int $u, int $e) => $this->referenzPax($u, $e));
    }

    // ── Stand und Sätze ──────────────────────────────────────────────────────────

    /**
     * Alles, was die Rechnung braucht, einmal geladen.
     *
     * @return array{k:FoodAlchemistKonfigurator, leistungen:array<int,object>, konzepte:array<int,object>, haupt:list<object>, addons:list<object>, speisen:array<int,array{vk:?float,ek:?float}>, pakete:list<FoodAlchemistKonfiguratorPaket>, mengen:array<int,array<string,FoodAlchemistKonfiguratorPaketMenge>>}
     */
    public function stand(FoodAlchemistKonfigurator $konfigurator): array
    {
        $konfigurator->load(['leistungen', 'konzepte', 'pakete.mengen']);
        $konzepte = $konfigurator->konzepte->keyBy('id')->all();
        $ids = collect($konzepte)->flatMap(fn ($k) => [$k->lunch_concept_id, $k->dinner_concept_id])->filter()->unique()->values()->all();
        // Concepts sind verknüpft: Sicht ist am Konfigurator geprüft, die Concepts können aus der Team-Kette stammen.
        $speisen = FoodAlchemistConcept::query()->ohneTeamSicht('verweis')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => $this->speisenPreis($c)])->all();
        $mengen = [];
        foreach ($konfigurator->pakete as $paket) {
            foreach ($paket->mengen as $m) {
                $mengen[$paket->id][$m->leistung_id . '|' . ($m->konfigurator_konzept_id ?? 0)] = $m;
            }
        }

        return [
            'k' => $konfigurator,
            'leistungen' => $konfigurator->leistungen->keyBy('id')->all(),
            'konzepte' => $konzepte,
            'haupt' => array_values(array_filter($konzepte, fn ($k) => $k->rolle === 'haupt')),
            'addons' => array_values(array_filter($konzepte, fn ($k) => $k->rolle === 'addon')),
            'speisen' => $speisen,
            'pakete' => $konfigurator->pakete->all(),
            'mengen' => $mengen,
        ];
    }

    /** Speisen-VK und -EK je Person aus dem Concept: manueller Preis bei Preis-Modus manuell (nicht abgelaufen), sonst Cache. */
    public function speisenPreis(FoodAlchemistConcept $c): array
    {
        $abgelaufen = $c->price_override_expires_at !== null && $c->price_override_expires_at->isPast();
        $manuell = in_array($c->price_mode ?? 'auto', ['fixed', 'manuell'], true) && ! $abgelaufen && $c->price_per_person_manual !== null;
        $vk = $manuell ? $c->price_per_person_manual : $c->price_per_person_cache;

        return ['vk' => $vk !== null ? (float) $vk : null, 'ek' => $c->ek_per_person_cache !== null ? (float) $c->ek_per_person_cache : null];
    }

    /**
     * Aktive Leistungen eines Kontexts: Grundausstattung, ohne die von Zusatz-Leistungen des Konzepts ersetzten, plus
     * dessen Zusatz-Leistungen für die Tageszeit (`$t` null = alle). Add-on: nur seine eigenen Zusatz-Leistungen.
     *
     * @return array{0: list<int>, 1: array<int,int>} Satz und Ersetzungen (ersetzte Leistung → Ersatz)
     */
    private function satz(array $s, ?object $konzept, ?string $t): array
    {
        $gilt = fn ($l) => $t === null || in_array($l->gilt_fuer ?? 'beide', [null, 'beide', $t], true);
        if ($konzept !== null && $konzept->rolle === 'addon') {
            return [array_values(array_map(fn ($l) => (int) $l->id, array_filter($s['leistungen'], fn ($l) => (int) $l->konfigurator_konzept_id === (int) $konzept->id))), []];
        }
        $zusatz = $konzept !== null
            ? array_filter($s['leistungen'], fn ($l) => (int) $l->konfigurator_konzept_id === (int) $konzept->id && $gilt($l))
            : [];
        $ersetzt = [];
        foreach ($zusatz as $l) {
            if ($l->ersetzt_leistung_id !== null) {
                $ersetzt[(int) $l->ersetzt_leistung_id] = (int) $l->id;
            }
        }
        $grund = array_filter($s['leistungen'], fn ($l) => $l->konfigurator_konzept_id === null && ! isset($ersetzt[(int) $l->id]));

        return [array_values(array_map(fn ($l) => (int) $l->id, $grund + $zusatz)), $ersetzt];
    }

    /** Handwerte eines Pakets im Kontext: zuerst die Zeile des Konzepts, sonst die Grundzeile. */
    private function handwert(array $s, FoodAlchemistKonfiguratorPaket $paket, ?int $kontext): callable
    {
        $zeilen = $s['mengen'][$paket->id] ?? [];

        return function (int $id) use ($zeilen, $kontext): ?float {
            foreach ($kontext !== null ? [$id . '|' . $kontext, $id . '|0'] : [$id . '|0'] as $schluessel) {
                if (isset($zeilen[$schluessel]) && $zeilen[$schluessel]->manuell) {
                    return (float) $zeilen[$schluessel]->anzahl;
                }
            }

            return null;
        };
    }

    /** @return list<array> alle Fälle: je Hauptkonzept Lunch/Dinner (soweit Concept da) und Day-into-Night, dazu je Add-on */
    private function faelle(array $s): array
    {
        $faelle = [];
        foreach ($s['haupt'] as $k) {
            if ($k->lunch_concept_id !== null) {
                $faelle[] = ['typ' => 'einzel', 'k' => (int) $k->id, 't' => 'lunch'];
            }
            if ($k->dinner_concept_id !== null) {
                $faelle[] = ['typ' => 'einzel', 'k' => (int) $k->id, 't' => 'dinner'];
            }
            if ($k->lunch_concept_id !== null && $k->dinner_concept_id !== null) {
                $faelle[] = ['typ' => 'day_night', 'k1' => (int) $k->id, 'k2' => (int) $k->id];
            }
        }
        foreach ($s['addons'] as $a) {
            $faelle[] = ['typ' => 'addon', 'a' => (int) $a->id];
        }

        return $faelle;
    }

    private function fallName(array $s, array $fall): string
    {
        $name = fn (int $id) => $s['konzepte'][$id]->kunden_titel ?: ('Konzept ' . $id);

        return match ($fall['typ']) {
            'addon' => 'Add-on ' . $name($fall['a']),
            'day_night' => $name($fall['k1']) . ' Day-into-Night' . ($fall['k1'] !== $fall['k2'] ? ' + ' . $name($fall['k2']) : ''),
            default => $name($fall['k']) . ' ' . ($fall['t'] === 'lunch' ? 'Lunch' : 'Dinner'),
        };
    }
}
