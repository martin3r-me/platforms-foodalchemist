<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AllergenValue;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistLabelTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistStorageBin;

/**
 * Spec 70 · Etiketten für Eigenproduktion (Basisrezept, Gericht), Anbruch (Grundprodukt) und Stellplatz.
 *
 * Inhalt kommt aus den vorhandenen Quellen — Allergene/Zusatzstoffe aus derselben Kaskade wie
 * Speisekarte und Speiseplan (Kürzel A–N, 1–18), GP-Allergene aus GpAggregateService (inkl. Vererbung
 * von den Artikeln), Zutatenliste nach LMIV (absteigend nach Gewicht, Allergene hervorgehoben).
 * Gestaltung frei über Vorlage + Betrieb (Logo) + Design; Pflichtfelder sind nicht abwählbar, Typ
 * „verkauf" erzwingt zusätzlich die LMIV-Pflichtangaben. Datumsfelder je Feld vorbelegt oder leer
 * (Schreiblinie zum Handschreiben).
 */
class EtikettService
{
    /** art: text | datum | fix (aus Daten, nicht frei) · pflicht: für welche Typen nicht abwählbar */
    public const FELDER = [
        'bezeichnung' => ['label' => 'Bezeichnung', 'art' => 'text', 'pflicht' => ['intern', 'verkauf']],
        'zusatz' => ['label' => 'Zusatzzeile', 'art' => 'text', 'pflicht' => []],
        'hergestellt_am' => ['label' => 'Hergestellt am', 'art' => 'datum', 'pflicht' => []],
        'eingefroren_am' => ['label' => 'Eingefroren am', 'art' => 'datum', 'pflicht' => []],
        'geoeffnet_am' => ['label' => 'Geöffnet am', 'art' => 'datum', 'pflicht' => []],
        'verbrauchen_bis' => ['label' => 'Verbrauchen bis', 'art' => 'datum', 'pflicht' => ['intern', 'verkauf']],
        'lagerung' => ['label' => 'Lagerung', 'art' => 'text', 'pflicht' => []],
        'menge' => ['label' => 'Menge', 'art' => 'text', 'pflicht' => ['verkauf']],
        'zutaten' => ['label' => 'Zutaten', 'art' => 'fix', 'pflicht' => ['verkauf']],
        'allergene' => ['label' => 'Allergene', 'art' => 'fix', 'pflicht' => ['intern', 'verkauf']],
        'zusatzstoffe' => ['label' => 'Zusatzstoffe', 'art' => 'fix', 'pflicht' => []],
        'kuerzel' => ['label' => 'Kürzel', 'art' => 'text', 'pflicht' => []],
        'charge' => ['label' => 'Charge', 'art' => 'text', 'pflicht' => []],
        'hersteller' => ['label' => 'Hersteller / Anschrift', 'art' => 'text', 'pflicht' => ['verkauf']],
    ];

    public const STANDARD_FELDER = [
        'intern' => ['bezeichnung', 'hergestellt_am', 'eingefroren_am', 'verbrauchen_bis', 'lagerung', 'menge', 'allergene', 'kuerzel'],
        'verkauf' => ['bezeichnung', 'zutaten', 'allergene', 'menge', 'verbrauchen_bis', 'lagerung', 'hersteller'],
    ];

    /** Maße in mm. Bögen ohne Seitenrand (Standard-Etikettenbögen sind randlos auf A4 verteilt). */
    public const FORMATE = [
        'a4_24' => ['label' => 'A4-Bogen 24 (3 × 8, 70 × 37 mm)', 'bogen' => true, 'spalten' => 3, 'zeilen' => 8, 'b' => 70.0, 'h' => 37.0],
        'a4_40' => ['label' => 'A4-Bogen 40 (4 × 10, 52,5 × 29,7 mm)', 'bogen' => true, 'spalten' => 4, 'zeilen' => 10, 'b' => 52.5, 'h' => 29.7],
        'rolle_62' => ['label' => 'Rolle 62 mm (Brother, 62 × 40 mm)', 'bogen' => false, 'b' => 62.0, 'h' => 40.0],
        'dymo_54' => ['label' => 'Dymo 54 × 25 mm', 'bogen' => false, 'b' => 54.0, 'h' => 25.0],
    ];

    public const LAGERUNG = ['gekuehlt' => 'Gekühlt lagern (0–7 °C)', 'tiefgekuehlt' => 'Tiefgekühlt lagern (−18 °C)', 'trocken' => 'Trocken und kühl lagern'];

    // ── Vorlagen ────────────────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistLabelTemplate> */
    public function vorlagen(Team $team): Collection
    {
        $liste = FoodAlchemistLabelTemplate::where('team_id', $team->id)->orderByDesc('is_default')->orderBy('name')->get();
        if ($liste->isEmpty()) {
            $liste = collect([$this->speichern($team, null, ['name' => 'Standard (intern)', 'is_default' => true])]);
        }

        return $liste;
    }

    public function vorlage(Team $team, ?int $id): FoodAlchemistLabelTemplate
    {
        if ($id === null) {
            return $this->vorlagen($team)->first();
        }

        return FoodAlchemistLabelTemplate::where('team_id', $team->id)->findOrFail($id);
    }

    /** @param array<string, mixed> $daten */
    public function speichern(Team $team, ?int $id, array $daten): FoodAlchemistLabelTemplate
    {
        $v = $id !== null ? FoodAlchemistLabelTemplate::where('team_id', $team->id)->findOrFail($id) : new FoodAlchemistLabelTemplate(['team_id' => $team->id]);
        $typ = (string) ($daten['typ'] ?? $v->typ ?? 'intern');
        if (! in_array($typ, ['intern', 'verkauf'], true)) {
            throw new \RuntimeException('Typ muss intern oder verkauf sein.');
        }
        $format = (string) ($daten['format'] ?? $v->format ?? 'a4_24');
        if (! array_key_exists($format, self::FORMATE)) {
            throw new \RuntimeException('Unbekanntes Format.');
        }
        $name = trim((string) ($daten['name'] ?? $v->name ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Die Vorlage braucht einen Namen.');
        }
        $darstellung = (string) ($daten['allergen_darstellung'] ?? $v->allergen_darstellung ?? 'beides');
        if (! in_array($darstellung, ['kuerzel', 'klartext', 'beides'], true)) {
            throw new \RuntimeException('Allergen-Darstellung: kuerzel, klartext oder beides.');
        }
        $groesse = (string) ($daten['schriftgroesse'] ?? $v->schriftgroesse ?? 'm');
        if (! in_array($groesse, ['s', 'm', 'l'], true)) {
            throw new \RuntimeException('Schriftgröße: s, m oder l.');
        }
        $outletId = array_key_exists('outlet_id', $daten) ? ($daten['outlet_id'] !== null && $daten['outlet_id'] !== '' ? (int) $daten['outlet_id'] : null) : $v->outlet_id;
        if ($outletId !== null && ! FoodAlchemistOutlet::where('team_id', $team->id)->whereKey($outletId)->exists()) {
            throw new \RuntimeException('Betrieb nicht gefunden.');
        }
        $typWechsel = $v->exists && $v->typ !== $typ;
        $felder = $this->normalisiereFelder($typ, array_key_exists('felder', $daten) ? (array) $daten['felder'] : ($typWechsel || ! $v->exists ? null : $v->felder));

        $v->fill([
            'name' => mb_substr($name, 0, 120), 'typ' => $typ, 'format' => $format, 'outlet_id' => $outletId,
            'presentation_design' => array_key_exists('presentation_design', $daten) ? (($daten['presentation_design'] ?? '') !== '' ? (string) $daten['presentation_design'] : null) : $v->presentation_design,
            'felder' => $felder, 'allergen_darstellung' => $darstellung, 'schriftgroesse' => $groesse,
            'datum_gross' => (bool) ($daten['datum_gross'] ?? $v->datum_gross ?? true),
            'zeige_logo' => (bool) ($daten['zeige_logo'] ?? $v->zeige_logo ?? true),
            'fusstext' => array_key_exists('fusstext', $daten) ? (trim((string) $daten['fusstext']) !== '' ? mb_substr(trim((string) $daten['fusstext']), 0, 200) : null) : $v->fusstext,
            'is_default' => (bool) ($daten['is_default'] ?? $v->is_default ?? false),
        ]);
        $v->save();
        if ($v->is_default) {
            FoodAlchemistLabelTemplate::where('team_id', $team->id)->where('id', '!=', $v->id)->update(['is_default' => false]);
        }

        return $v->refresh();
    }

    public function loeschen(Team $team, int $id): void
    {
        FoodAlchemistLabelTemplate::where('team_id', $team->id)->findOrFail($id)->delete();
    }

    /**
     * Alle Katalogfelder in Vorlagen-Reihenfolge; Pflichtfelder des Typs immer an; Datums-Modus
     * vorbelegt|leer nur bei Datumsfeldern.
     *
     * @return list<array{key:string, an:bool, modus:?string}>
     */
    public function normalisiereFelder(string $typ, ?array $felder): array
    {
        $felder ??= array_map(fn ($k) => ['key' => $k, 'an' => true], self::STANDARD_FELDER[$typ]);
        $out = [];
        $gesehen = [];
        foreach ($felder as $f) {
            $k = is_array($f) ? (string) ($f['key'] ?? '') : (string) $f;
            if (! isset(self::FELDER[$k]) || isset($gesehen[$k])) {
                continue;
            }
            $gesehen[$k] = true;
            $out[] = $this->feld($typ, $k, is_array($f) ? (bool) ($f['an'] ?? true) : true, is_array($f) ? ($f['modus'] ?? null) : null);
        }
        foreach (array_keys(self::FELDER) as $k) {
            if (! isset($gesehen[$k])) {
                $out[] = $this->feld($typ, $k, false, null);
            }
        }

        return $out;
    }

    // ── Daten ───────────────────────────────────────────────────────────────

    /**
     * Inhalt eines Etiketts.
     *
     * @param  string  $quelle  recipe | gp | stellplatz
     * @param  array<string, mixed>  $eingabe  Overrides: bezeichnung, zusatz, hergestellt_am, eingefroren_am, geoeffnet_am,
     *                                          verbrauchen_bis, lagerung (gekuehlt|tiefgekuehlt|trocken), menge, kuerzel, charge
     */
    public function daten(Team $team, string $quelle, int $id, array $eingabe = [], ?FoodAlchemistLabelTemplate $vorlage = null): array
    {
        $kat = app(ConcepterAggregateService::class)->kennzeichnungKatalog();
        $heute = now()->startOfDay();
        $d = ['quelle' => $quelle, 'bezeichnung' => '', 'zusatz' => null, 'zutaten' => [], 'allergene' => [], 'spuren' => [], 'zusatzstoffe' => [],
            'allergene_unbekannt' => false, 'inhalt' => [], 'haltbar_gekuehlt' => null, 'haltbar_tk' => null, 'lagerung' => null];

        if ($quelle === 'recipe') {
            $r = FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients.gp', 'ingredients.referencedRecipe')->findOrFail($id);
            $k = app(ConcepterAggregateService::class)->kennzeichnungFromGerichte(collect([$r]));
            foreach ($k['allergene'] as $a) {
                $eintrag = ['code' => $kat['allergene'][$a['slug']]['code'] ?? '', 'label' => $a['label']];
                match ($a['status']) {
                    'enthalten' => $d['allergene'][] = $eintrag,
                    'spuren' => $d['spuren'][] = $eintrag,
                    'unbekannt' => $d['allergene_unbekannt'] = true,
                    default => null,
                };
            }
            foreach ($k['zusatzstoffe'] as $z) {
                if ($z['status'] === 'ja') {
                    $d['zusatzstoffe'][] = ['code' => $kat['zusatzstoffe'][$z['slug']]['code'] ?? '', 'label' => $z['label']];
                }
            }
            $d['bezeichnung'] = $this->rezeptname((string) $r->name);
            $d['zutaten'] = $this->zutatenliste($team, $r);
            $d['haltbar_gekuehlt'] = $r->shelf_life_chilled_days;
            $d['haltbar_tk'] = $r->shelf_life_frozen_days;
            $d['lagerung'] = 'gekuehlt';
        } elseif ($quelle === 'gp') {
            $gp = FoodAlchemistGp::visibleToTeam($team)->with('leadLa')->findOrFail($id);
            $agg = app(GpAggregateService::class);
            foreach ($agg->allergene($gp) as $slug => $info) {
                $wert = $info['value'] instanceof AllergenValue ? $info['value']->value : (string) $info['value'];
                $eintrag = ['code' => $kat['allergene'][$slug]['code'] ?? '', 'label' => FoodAlchemistItemAllergen::ALLERGENE[$slug] ?? $slug];
                match ($wert) {
                    'enthalten' => $d['allergene'][] = $eintrag,
                    'spuren' => $d['spuren'][] = $eintrag,
                    'unbekannt' => $d['allergene_unbekannt'] = true,
                    default => null,
                };
            }
            foreach ($agg->zusatzstoffe($gp) as $stoff => $wert) {
                if ((int) $wert === 3) {
                    $d['zusatzstoffe'][] = ['code' => $kat['zusatzstoffe'][$stoff]['code'] ?? '', 'label' => FoodAlchemistItemDeclaration::STOFFE[$stoff] ?? $stoff];
                }
            }
            $d['bezeichnung'] = $this->kurzname((string) $gp->name);
            $text = trim((string) ($gp->leadLa?->ingredients_supplier ?? ''));
            $d['zutaten'] = $text !== '' ? [['name' => $text, 'allergen' => false]] : [];
            $d['lagerung'] = in_array(mb_strtolower((string) $gp->condition), ['tk'], true) ? 'tiefgekuehlt' : (mb_strtolower((string) $gp->condition) === 'frisch' ? 'gekuehlt' : 'trocken');
        } elseif ($quelle === 'stellplatz') {
            $bin = FoodAlchemistStorageBin::where('team_id', $team->id)->with('location:id,name')->findOrFail($id);
            $d['bezeichnung'] = (string) $bin->name;
            $d['zusatz'] = trim(($bin->location?->name ?? '') . ($bin->zone ? ' · ' . (FoodAlchemistStorageBin::ZONEN[$bin->zone] ?? $bin->zone) : ''));
            $d['inhalt'] = \Platform\FoodAlchemist\Models\FoodAlchemistStorageBinItem::where('foodalchemist_storage_bin_items.team_id', $team->id)->where('storage_bin_id', $bin->id)
                ->join('foodalchemist_gps as g', 'g.id', '=', 'foodalchemist_storage_bin_items.gp_id')->orderBy('g.name')->pluck('g.name')
                ->map(fn ($n) => $this->kurzname((string) $n))->unique()->values()->all();
        } else {
            throw new \RuntimeException('Quelle muss recipe, gp oder stellplatz sein.');
        }

        // Eingaben überschreiben, Datumsfelder vorbelegen
        foreach (['bezeichnung', 'zusatz', 'menge', 'kuerzel', 'charge'] as $f) {
            if (isset($eingabe[$f]) && trim((string) $eingabe[$f]) !== '') {
                $d[$f] = mb_substr(trim((string) $eingabe[$f]), 0, 160);
            }
        }
        $d['menge'] ??= null;
        $d['kuerzel'] ??= null;
        $d['charge'] ??= null;
        if (isset($eingabe['lagerung']) && array_key_exists((string) $eingabe['lagerung'], self::LAGERUNG)) {
            $d['lagerung'] = (string) $eingabe['lagerung'];
        }
        $datum = fn (string $f) => isset($eingabe[$f]) && trim((string) $eingabe[$f]) !== '' ? Carbon::parse((string) $eingabe[$f])->startOfDay() : null;
        $hergestellt = $datum('hergestellt_am') ?? ($quelle === 'recipe' ? $heute : null);
        $eingefroren = $datum('eingefroren_am') ?? ($quelle === 'recipe' && $d['lagerung'] === 'tiefgekuehlt' ? $heute : null);
        $geoeffnet = $datum('geoeffnet_am') ?? ($quelle === 'gp' ? $heute : null);
        $bis = $datum('verbrauchen_bis');
        if ($bis === null && $quelle === 'recipe') {
            $bis = $d['lagerung'] === 'tiefgekuehlt'
                ? ($d['haltbar_tk'] !== null ? ($eingefroren ?? $heute)->copy()->addDays((int) $d['haltbar_tk']) : null)
                : ($d['haltbar_gekuehlt'] !== null ? ($hergestellt ?? $heute)->copy()->addDays((int) $d['haltbar_gekuehlt']) : null);
        }
        $d['datum'] = ['hergestellt_am' => $hergestellt, 'eingefroren_am' => $eingefroren, 'geoeffnet_am' => $geoeffnet, 'verbrauchen_bis' => $bis];

        $outlet = $vorlage?->outlet;
        $d['hersteller'] = isset($eingabe['hersteller']) && trim((string) $eingabe['hersteller']) !== ''
            ? trim((string) $eingabe['hersteller'])
            : ($outlet?->name ?? $team->name);

        return $d;
    }

    /**
     * Zutatenliste nach LMIV: absteigend nach Gewicht; zusammengesetzte Zutaten (Basisrezept) mit ihren
     * Zutaten in Klammern (eine Ebene); Zutaten mit Allergen „enthalten" werden hervorgehoben.
     *
     * @return list<array{name:string, allergen:bool, teile?:list<array{name:string, allergen:bool}>}>
     */
    public function zutatenliste(Team $team, FoodAlchemistRecipe $r, int $ebene = 0): array
    {
        $rc = app(RecipeRecomputeService::class);
        $agg = app(GpAggregateService::class);
        $zeilen = $r->ingredients->filter(fn ($z) => ! in_array($z->calc_mode, ['keine', 'nur_naehrwerte'], true) && ($z->gp_id !== null || $z->referenced_recipe_id !== null));
        $liste = [];
        foreach ($zeilen as $z) {
            $gramm = (float) $rc->grammJeZeile($z);
            if ($z->gp !== null) {
                $name = $this->kurzname((string) $z->gp->name);
                $allergen = collect($agg->allergene($z->gp))->contains(fn ($i) => ($i['value'] instanceof AllergenValue ? $i['value']->value : $i['value']) === 'enthalten');
                $liste[$name] ??= ['name' => $name, 'allergen' => false, 'g' => 0.0];
                $liste[$name]['g'] += $gramm;
                $liste[$name]['allergen'] = $liste[$name]['allergen'] || $allergen;
            } elseif ($z->referencedRecipe !== null) {
                $sub = $z->referencedRecipe;
                $name = $this->rezeptname((string) $sub->name);
                $allergen = collect(FoodAlchemistGp::ALLERGEN_FIELDS)->contains(fn ($slug) => ($sub->getAttribute('allergen_' . $slug) instanceof AllergenValue ? $sub->getAttribute('allergen_' . $slug)->value : (string) $sub->getAttribute('allergen_' . $slug)) === 'enthalten');
                $eintrag = ['name' => $name, 'allergen' => $allergen, 'g' => $gramm];
                if ($ebene === 0) {
                    $sub->loadMissing('ingredients.gp', 'ingredients.referencedRecipe');
                    $eintrag['teile'] = array_map(fn ($t) => ['name' => $t['name'], 'allergen' => $t['allergen']], $this->zutatenliste($team, $sub, 1));
                }
                $liste['rezept:' . $sub->id] = $eintrag;
            }
        }
        usort($liste, fn ($a, $b) => $b['g'] <=> $a['g']);

        return array_map(function ($e) {
            unset($e['g']);

            return $e;
        }, array_values($liste));
    }

    // ── Druck ───────────────────────────────────────────────────────────────

    /** Alles, was die Druckansicht braucht. */
    public function druck(Team $team, ?int $vorlageId, string $quelle, int $id, array $eingabe = [], int $anzahl = 1, int $startplatz = 1): array
    {
        $v = $this->vorlage($team, $vorlageId);
        $format = self::FORMATE[$v->format] ?? self::FORMATE['a4_24'];
        $platz = $format['bogen'] ? $format['spalten'] * $format['zeilen'] : 1;

        return [
            'vorlage' => $v,
            'format' => $format,
            'felder' => array_values(array_filter($this->normalisiereFelder((string) $v->typ, $v->felder), fn ($f) => $f['an'])),
            'daten' => $this->daten($team, $quelle, $id, $eingabe, $v),
            'anzahl' => max(1, min(500, $anzahl)),
            'startplatz' => $format['bogen'] ? max(1, min($platz, $startplatz)) : 1,
            'optik' => $this->optik($team, $v),
        ];
    }

    /** Gestaltung: Akzentfarbe + Schrift aus dem Design, Logo vom Betrieb. Druck auf weißem Papier. */
    public function optik(Team $team, FoodAlchemistLabelTemplate $v): array
    {
        $outlet = $v->outlet;
        $quelle = $v->presentation_design ?: ($outlet?->presentation_design ?: 'editorial');
        $tokens = app(PresentationDesignService::class)->resolveTokens($quelle, $team);
        $akzent = (string) ($tokens['palette']['primary'] ?? '');
        $serif = in_array($tokens['typography']['heading'] ?? 'sans', ['display-serif', 'serif'], true);

        return [
            'akzent' => preg_match('/^#[0-9a-f]{6}$/i', $akzent) ? $akzent : '#1a1712',
            'schrift_kopf' => $serif ? '"DejaVu Serif", Georgia, serif' : '"DejaVu Sans", Arial, sans-serif',
            // Logo des Betriebs, sonst Food-Alchemist-Wortmarke (Standard, Dominique 2026-10-08)
            'logo' => $v->zeige_logo
                ? (($outlet !== null ? app(FoodAlchemistMediaService::class)->dataUri($outlet->logo_context_file_id ?? null, $outlet->logo_path ?? null) : null) ?? $this->faLogo())
                : null,
        ];
    }

    /** Haltbarkeits-Vorschlag am Rezept setzen (nur eigene Rezepte des Teams). */
    public function haltbarkeitSetzen(Team $team, int $recipeId, mixed $gekuehlt, mixed $tk): FoodAlchemistRecipe
    {
        $r = FoodAlchemistRecipe::where('team_id', $team->id)->find($recipeId);
        if ($r === null) {
            throw new \RuntimeException('Haltbarkeit lässt sich nur an eigenen Rezepten speichern — dieses Rezept gehört einem anderen Team.');
        }
        $tage = function (mixed $v, string $was): ?int {
            $v = trim((string) ($v ?? ''));
            if ($v === '') {
                return null;
            }
            if (! ctype_digit($v) || (int) $v > 3650) {
                throw new \RuntimeException($was . ': ganze Tage zwischen 0 und 3650.');
            }

            return (int) $v;
        };
        $r->forceFill(['shelf_life_chilled_days' => $tage($gekuehlt, 'Haltbarkeit gekühlt'), 'shelf_life_frozen_days' => $tage($tk, 'Haltbarkeit TK')])->save();

        return $r;
    }

    /** Druck-URL (für Knöpfe und MCP). */
    public function druckUrl(string $quelle, int $id, ?int $vorlageId = null, array $eingabe = [], int $anzahl = 1, int $startplatz = 1, bool $pdf = false): string
    {
        return route('foodalchemist.etiketten.druck', array_filter([
            'quelle' => $quelle, 'id' => $id, 'vorlage' => $vorlageId, 'anzahl' => $anzahl, 'startplatz' => $startplatz > 1 ? $startplatz : null,
            'e' => array_filter($eingabe, fn ($v) => $v !== null && $v !== ''), 'pdf' => $pdf ? 1 : null,
        ], fn ($v) => $v !== null && $v !== []));
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private function faLogo(): ?string
    {
        $pfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';

        return is_file($pfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($pfad)) : null;
    }

    private function feld(string $typ, string $key, bool $an, ?string $modus): array
    {
        $pflicht = in_array($typ, self::FELDER[$key]['pflicht'], true);
        $istDatum = self::FELDER[$key]['art'] === 'datum';

        return ['key' => $key, 'an' => $pflicht || $an, 'pflicht' => $pflicht,
            'modus' => $istDatum ? (in_array($modus, ['vorbelegt', 'leer'], true) ? $modus : 'vorbelegt') : null];
    }

    /**
     * Rezeptname fürs Etikett: Regelwerk Basisrezepte §1 baut „Kategorie: Name" („Kartoffelbeilage:
     * Butterkartoffeln") → Teil NACH dem Doppelpunkt; Gerichte verlieren das Kürzel „[HG] ".
     */
    private function rezeptname(string $name): string
    {
        $name = trim((string) preg_replace('/^\[[^\]]{1,6}\]\s*/u', '', $name));
        $teile = explode(':', $name, 2);

        return isset($teile[1]) && trim($teile[1]) !== '' ? trim($teile[1]) : $name;
    }

    /** Grundprodukt „Butter: frisch, Bio" → Produktname vor dem Doppelpunkt (Eigenschaften sind fürs Etikett zu technisch). */
    private function kurzname(string $name): string
    {
        $teil = trim(explode(':', $name, 2)[0]);

        return $teil !== '' ? $teil : trim($name);
    }
}
