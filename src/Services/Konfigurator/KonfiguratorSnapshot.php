<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;

/**
 * Spec 94 · Kundensicht des Konfigurators für den Präsentations-Snapshot (Typ `konfigurator`). ALLOWLIST-Neubau:
 * Konzepte, Add-ons, Pakete (von/bis) und eine serverseitig gerechnete Preistabelle — VK pro Person je Paket und
 * Hauptkonzept/Tageszeit, Day-into-Night-Paar und Add-on. Kein EK, keine Aufschlagsklassen, keine Mengen, keine
 * Leistungszeilen (Leak-Test Spec94KundenlinkTest). Spec 94b §5: je Preis die VK-Aufteilung nach Gruppen (`aufteilung`, nur
 * Gruppenname und VK pro Person, Summe = Preis) und `zzgl` (Leistungen nach Aufwand: Titel, Satz, Einheit, geschätzte
 * Menge und Betrag je Paket — Schätzung an der Referenz-Pax, nie im Paketpreis; keine Regel, kein EK). Spec 94c 8c: Getränke nach
 * Verbrauch dort mit `art = verbrauch` und `deckel` (Satz = Preis je Gast, keine Mengen je Gast). Der Browser sucht nur das Paket zur Gästezahl und liest ab.
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
            $detail = $this->detail($h);
            $konzepte[] = [
                'id' => (int) $h->id,
                'titel' => (string) ($h->kunden_titel ?: 'Konzept'),
                'claim' => $h->claim,
                // nur Identifier einfrieren, die URL signiert der Renderer frisch (wie hydrateImages).
                // Stufe 5b: Überschreib-Bild am Konfigurator-Konzept, sonst Concept-Titelbild bzw. Galerie (wie im Foodbook)
                'bild' => $detail['bilder'][0] ?? null,
                'lunch' => $h->lunch_concept_id !== null,
                'dinner' => $h->dinner_concept_id !== null,
                'indikativ_lunch' => (bool) $h->indikativ_lunch,
                'indikativ_dinner' => (bool) $h->indikativ_dinner,
                'detail' => $detail,
            ];
        }
        $addons = [];
        foreach ($s['addons'] as $a) {
            $detail = $this->detail($a);   // dieselbe Speisen-Detailansicht wie bei den Hauptkonzepten
            $addons[] = [
                'id' => (int) $a->id,
                'titel' => (string) ($a->kunden_titel ?: 'Add-on'),
                'claim' => $a->claim,
                'bild' => $detail['bilder'][0] ?? null,
                'detail' => $detail,
                'abschnitt' => $a->abschnitt ?: 'Extras',
                'tageszeit' => $a->addon_tageszeit ?: 'beide',
                'gebunden_an' => $a->gebunden_an_id !== null ? (int) $a->gebunden_an_id : null,
            ];
        }

        // Spec 94c: Getränkepakete — Karten wie Extras, Speisen-Detail mit den Getränken; Dauer und Einsatzzeit nur für den Hinweis an der Karte
        $getraenke = [];
        foreach ($s['getraenke'] as $g) {
            $detail = $this->detail($g);
            $getraenke[] = [
                'id' => (int) $g->id,
                'titel' => (string) ($g->kunden_titel ?: 'Getränke'),
                'claim' => $g->claim,
                'bild' => $detail['bilder'][0] ?? null,
                'detail' => $detail,
                'gebunden_an' => $g->gebunden_an_id !== null ? (int) $g->gebunden_an_id : null,
                'je_stunde' => $g->getraenk_dauer === 'stunde' || $g->folgestunde_satz !== null,
                'verbrauch' => $g->getraenk_abrechnung === 'verbrauch',   // 8c: Karte zeigt „nach Verbrauch, ca. …“ statt Preis pro Person
                'stunden' => self::getraenkStunden($g, $k),
            ];
        }

        $pakete = [];
        $preise = [];
        $aufteilung = [];   // Spec 94b §5: je Paket und Preisschlüssel die VK-Aufteilung — nur Name und VK pro Person
        $namen = app(LeistungsKategorieService::class)->namen($team);
        $teile = function (array $kern, float $preis) use ($namen): array {
            return array_map(fn ($g) => ['name' => $g['name'], 'vk' => $g['vk_pp']],
                array_values(array_filter(KonfiguratorAufteilung::aufteilen($kern['gruppen'], $preis, $namen), fn ($g) => abs($g['vk_pp']) >= 0.005)));
        };
        $zzgl = [];   // Spec 94b §4: je Paket und Preisschlüssel „zzgl. nach Aufwand“ — Titel, Satz, Einheit, geschätzte Menge und Betrag
        // Spec 94c 8c: Getränke nach Verbrauch als Zeile `art = verbrauch` (Satz je Gast, Deckel) — das Skript rechnet sie mit der Gästezahl
        $aufwand = fn (array $kern): array => array_map(fn ($e) => ['titel' => $e['bezeichnung'], 'satz' => round($e['satz'], 2), 'einheit' => $e['einheit'],
            'menge' => $e['menge'], 'betrag' => $e['betrag']] + ($e['art'] === 'verbrauch' ? ['art' => 'verbrauch', 'deckel' => $e['deckel']] : []), $kern['einzel']);
        foreach ($s['pakete'] as $i => $p) {
            $pakete[] = ['von' => (int) $p->von_pax, 'bis' => (int) $p->bis_pax, 'name' => $p->anzeigeName()];
            $tabelle = [];
            $teilung = [];
            $zusatz = [];
            foreach ($s['haupt'] as $h) {
                foreach (['lunch' => $h->lunch_concept_id, 'dinner' => $h->dinner_concept_id] as $t => $cid) {
                    if ($cid !== null) {
                        $kern = $this->pricing->rechneFall($k, $p, ['typ' => 'einzel', 'k' => (int) $h->id, 't' => $t], null, $s);
                        $tabelle["k{$h->id}:{$t}"] = $runde($kern['vk_pp']);
                        $teilung["k{$h->id}:{$t}"] = $teile($kern, $tabelle["k{$h->id}:{$t}"]);
                        $zusatz["k{$h->id}:{$t}"] = $aufwand($kern);
                    }
                }
            }
            foreach ($s['haupt'] as $h1) {
                foreach ($s['haupt'] as $h2) {
                    if ($h1->lunch_concept_id !== null && $h2->dinner_concept_id !== null) {
                        $kern = $this->pricing->rechneFall($k, $p, ['typ' => 'day_night', 'k1' => (int) $h1->id, 'k2' => (int) $h2->id], null, $s);
                        $tabelle["dn{$h1->id}:{$h2->id}"] = $runde($kern['vk_pp']);
                        $teilung["dn{$h1->id}:{$h2->id}"] = $teile($kern, $tabelle["dn{$h1->id}:{$h2->id}"]);
                        $zusatz["dn{$h1->id}:{$h2->id}"] = $aufwand($kern);
                    }
                }
            }
            foreach ($s['addons'] as $a) {
                $kern = $this->pricing->rechneFall($k, $p, ['typ' => 'addon', 'a' => (int) $a->id], null, $s);
                $tabelle["a{$a->id}"] = $runde($kern['vk_pp']);
                $zusatz["a{$a->id}"] = $aufwand($kern);
            }
            foreach ($s['getraenke'] as $g) {   // Spec 94c: g{id}:lunch | dinner | dn
                foreach (['lunch' => 'lunch', 'dinner' => 'dinner', 'day_night' => 'dn'] as $t => $kurz) {
                    $kern = $this->pricing->rechneFall($k, $p, ['typ' => 'getraenk', 'g' => (int) $g->id, 't' => $t], null, $s);
                    if (! $kern['speisen_fehlen']) {
                        $tabelle["g{$g->id}:{$kurz}"] = $runde($kern['vk_pp']);
                        $zusatz["g{$g->id}:{$kurz}"] = $aufwand($kern);
                    }
                }
            }
            $preise[$i] = $tabelle;
            $aufteilung[$i] = $teilung;
            $zzgl[$i] = array_filter($zusatz);
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
            'meta' => ['kicker' => $k->ort ?: 'Konfigurator', 'ort' => $k->ort, 'gueltig_bis' => $k->gueltig_bis?->format('d.m.Y'),
                // Druckfassung: Betrieb als Rückfall ohne Logo, Kontakt = „Anfragen gehen an“ (nur Name und E-Mail)
                'betrieb' => $k->outlet_id ? $k->outlet?->name : null, 'kontakt' => $this->kontakt($k)],
            'body' => [
                'intro' => $k->intro,
                'konzepte' => $konzepte,
                'addons' => $addons,
                'getraenke' => $getraenke,
                'getraenke_pflicht' => (bool) $k->getraenke_pflicht,
                'pakete' => $pakete,
                'preise' => $preise,
                'aufteilung' => $aufteilung,
                'zzgl' => $zzgl,
                'pax_vorgabe' => (int) $k->pax_vorgabe,
                'tageszeit_aktiv' => (bool) $k->tageszeit_aktiv,
                // Bilder der Zeitfenster-Karten (08.11.2026): nur Datei-Kennungen, die URL löst der Block auf
                'tageszeit_bilder' => collect(['lunch', 'dinner', 'day_night'])->mapWithKeys(fn ($t) => [$t => $k->{"bild_{$t}_path"} || $k->{"bild_{$t}_context_file_id"}
                    ? ['context_file_id' => $k->{"bild_{$t}_context_file_id"} !== null ? (int) $k->{"bild_{$t}_context_file_id"} : null, 'path' => $k->{"bild_{$t}_path"}] : null])->all(),
                'reihenfolge' => in_array($k->reihenfolge, \Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator::REIHENFOLGEN, true) ? $k->reihenfolge : 'tageszeit_zuerst',
                'kombi_rabatt_pct' => (float) $k->kombi_rabatt_pct,
                'anfrage' => true,
            ],
        ];
    }

    /** @return array{name:string, email:?string}|null */
    private function kontakt(FoodAlchemistKonfigurator $k): ?array
    {
        $u = $k->verantwortlich_user_id !== null ? \Platform\Core\Models\User::find($k->verantwortlich_user_id) : null;

        return $u !== null ? ['name' => (string) $u->name, 'email' => $u->email ?: null] : null;
    }

    /**
     * Stufe 5b · Kundensicht eines Haupt- oder Add-on-Konzepts für die Detailansicht — wie im Foodbook: Gericht-Zeilen über die
     * Wording-Kette (Konfigurator-Überschreibung → Concept-Slot-Wording → Kundenname, WordingResolver::gerichtZeilen),
     * Gericht-Fotos am Gericht, Bilder: Überschreib-Bild am Konfigurator-Konzept, sonst Concept-Titelbild + Galerie.
     * Allergen-/Zusatzstoff-Codes je Gericht wie im Foodbook (ConcepterAggregateService). ALLOWLIST: nur Texte, Codes,
     * Bild-Identifier — keine Rezept-, Slot- oder Concept-Ids, keine Preise, keine internen Namen.
     *
     * @return array{beschreibung:?string, bilder:list<array>, zeiten:array<string,list<array>>, legende:list<array>}
     */
    private function detail(object $h): array
    {
        $wording = app(\Platform\FoodAlchemist\Services\WordingResolver::class);
        $kz = app(\Platform\FoodAlchemist\Services\ConcepterAggregateService::class);
        $katalog = $kz->kennzeichnungKatalog();
        $usedAlg = [];
        $usedZus = [];
        $bilder = [];
        if ($h->bild_context_file_id || $h->bild_path) {
            $bilder[] = ['context_file_id' => $h->bild_context_file_id, 'path' => $h->bild_path];
        }
        $beschreibung = null;
        $zeiten = [];
        foreach (['lunch' => $h->lunch_concept_id, 'dinner' => $h->dinner_concept_id] as $t => $cid) {
            if ($cid === null) {
                continue;
            }
            $c = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::query()->ohneTeamSicht('verweis')
                ->with(['slots.dish', 'slots.embeddedConcept.slots.dish', 'slots.package.dishes.dish', 'images'])->find($cid);
            if ($c === null) {
                continue;
            }
            $beschreibung ??= trim((string) ($c->description ?: $c->claim)) ?: null;
            if ($c->image_context_file_id || $c->image_path) {
                $bilder[] = ['context_file_id' => $c->image_context_file_id, 'path' => $c->image_path];
            }
            foreach ($c->images ?? [] as $gi) {
                if ($gi->context_file_id || $gi->path) {
                    $bilder[] = ['context_file_id' => $gi->context_file_id, 'path' => $gi->path];
                }
            }
            $zeilen = $wording->gerichtZeilen($c, $h);
            $ids = collect($zeilen)->pluck('recipe_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
            $gerichte = $ids !== [] ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()->ohneTeamSicht('verweis')->whereIn('id', $ids)->get()->keyBy('id') : collect();
            $zeiten[$t] = array_values(array_map(function ($z) use ($gerichte, $kz, $katalog, &$usedAlg, &$usedZus) {
                $g = ! empty($z['recipe_id']) ? $gerichte->get((int) $z['recipe_id']) : null;

                return [
                    'kind' => (string) ($z['type'] ?? 'gericht'),
                    'label' => (string) ($z['text'] ?? ''),
                    'indent' => (int) ($z['einrueckung'] ?? 0),
                    'codes' => $g !== null ? $kz->gerichtCodes($g, $usedAlg, $usedZus, $katalog) : [],
                    'image' => $g !== null && ($g->image_context_file_id || $g->image_path) ? ['context_file_id' => $g->image_context_file_id, 'path' => $g->image_path] : null,
                ];
            }, $zeilen));
        }
        $legende = [];
        foreach (array_keys($usedAlg) as $slug) {
            $legende[] = ['code' => $katalog['allergene'][$slug]['code'], 'label' => $katalog['allergene'][$slug]['label']];
        }
        foreach (array_keys($usedZus) as $slug) {
            $legende[] = ['code' => $katalog['zusatzstoffe'][$slug]['code'], 'label' => $katalog['zusatzstoffe'][$slug]['label']];
        }

        return ['beschreibung' => $beschreibung, 'bilder' => array_slice($bilder, 0, 5), 'zeiten' => $zeiten, 'legende' => $legende];
    }

    /**
     * Preis einer Auswahl aus der eingefrorenen Preistabelle (für die Anfrage — nie ein Preis aus dem Formular).
     *
     * @param  array{konzept:int, tageszeit:string, abend_konzept?:?int, addons?:list<int>, getraenke?:list<int>, pax:int}  $auswahl
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
        $kurz = ['lunch' => 'lunch', 'dinner' => 'dinner', 'day_night' => 'dn'][$auswahl['tageszeit']] ?? 'dinner';
        foreach (array_unique(array_map('intval', $auswahl['getraenke'] ?? [])) as $g) {   // Spec 94c: Pauschale zur Tageszeit
            if (! isset($tabelle["g{$g}:{$kurz}"])) {
                return null;
            }
            $pp += (float) $tabelle["g{$g}:{$kurz}"];
        }

        return ['paket' => $index, 'paket_name' => (string) ($body['pakete'][$index]['name'] ?? ''), 'pro_person' => round($pp, 2), 'gesamt' => round($pp * $pax, 2)];
    }

    /**
     * Stundenangabe der Getränke-Karte je Zeitfenster als Text (Dominique 08.11.2026): Tag und Abend rechnet je Zeitfenster mit seiner
     * Einsatzzeit ({@see \Platform\FoodAlchemist\Services\KonfiguratorPricingService::getraenkPauschale}), die Karte zeigt deshalb „8 + 8“
     * statt der Day-into-Night-Zeit. Ein fehlender Teil zählt nicht.
     *
     * @return array{lunch: string, dinner: string, day_night: string}
     */
    public static function getraenkStunden(object $g, object $k): array
    {
        $zahl = fn ($h) => rtrim(rtrim(number_format((float) $h, 2, ',', ''), '0'), ',');
        $teile = [];
        foreach (['lunch' => $k->stunden_lunch, 'dinner' => $k->stunden_dinner] as $tz => $h) {
            if ($g->getraenkConceptId($tz) !== null && (float) $h > 0) {
                $teile[$tz] = $zahl($h);
            }
        }

        return ['lunch' => $teile['lunch'] ?? '', 'dinner' => $teile['dinner'] ?? '', 'day_night' => implode(' + ', $teile)];
    }
}
