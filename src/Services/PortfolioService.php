<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Spec 33 · P3 — die gemeinsame Naht über die drei Ausgabeformen.
 *
 * Foodbook, Speisekarte und Speiseplan sind zusammen das Portfolio eines Betriebs, haben aber
 * drei getrennte, strukturgleich duplizierte Services. Dieser Dienst ist bewusst **nur ein
 * Leser darüber** — kein Umbau darunter. Die Duplikation aufzulösen ist ein eigenes Vorhaben;
 * hier soll niemand versehentlich anfangen, sie zu verstecken.
 *
 * Er beantwortet die Frage der Mehrbetriebs-Sicht: **wer fährt gerade was?** Und zwar durch
 * zwei Brillen — nach **Betrieb** (Standort) und nach **Kunde**. Beide Achsen sind je Ausgabe
 * optional; was in keine passt, wird getrennt ausgewiesen statt still zu verschwinden.
 *
 * Der eigentliche Wert steckt nicht in der Liste, sondern in dem, was daran nicht stimmt:
 * {@see self::konflikte} (zwei laufende Ausgaben derselben Art in derselben Zuordnung),
 * {@see self::luecken} (Zuordnung ohne laufende Ausgabe) und {@see self::ohneZuordnung}.
 */
class PortfolioService
{
    /** Die drei Ausgabeformen mit Klartext und Route für den Sprung in die Bearbeitung. */
    public const ARTEN = [
        'foodbook' => ['label' => 'Foodbook', 'route' => 'foodalchemist.foodbooks.index', 'param' => 'fb'],
        'speisekarte' => ['label' => 'Speisekarte', 'route' => 'foodalchemist.speisekarte.index', 'param' => 'sk'],
        'speiseplan' => ['label' => 'Speiseplan', 'route' => 'foodalchemist.speiseplan.index', 'param' => 'sp'],
    ];

    /**
     * Alle Ausgaben des Teams in EINER Zeilenform.
     *
     * @param  array{art?:string,outlet_id?:int,nur_laufend?:bool}  $filter
     * @return list<array<string,mixed>>
     */
    public function uebersicht(Team $team, mixed $stichtag = null, array $filter = []): array
    {
        $tag = Carbon::parse($stichtag ?? now())->startOfDay();
        $zeilen = [];

        foreach ($this->ausgaben($team, $filter['art'] ?? null) as $art => $liste) {
            foreach ($liste as $a) {
                $zeilen[] = $this->zeile($art, $a, $tag);
            }
        }

        if (($filter['outlet_id'] ?? null) !== null) {
            $zeilen = array_values(array_filter($zeilen, fn ($z) => $z['outlet_id'] === (int) $filter['outlet_id']));
        }
        if ($filter['nur_laufend'] ?? false) {
            $zeilen = array_values(array_filter($zeilen, fn ($z) => $z['laeuft']));
        }

        // Laufendes zuerst, dann nach Art und Name — die Steuerungsfrage ist „was ist jetzt an".
        usort($zeilen, fn ($a, $b) => [$b['laeuft'], $a['art'], mb_strtolower($a['name'])]
            <=> [$a['laeuft'], $b['art'], mb_strtolower($b['name'])]);

        return $zeilen;
    }

    /** @return list<array<string,mixed>> */
    public function laufendeAm(Team $team, mixed $stichtag = null): array
    {
        return $this->uebersicht($team, $stichtag, ['nur_laufend' => true]);
    }

    /**
     * Zwei gleichzeitig laufende Ausgaben **derselben Art in derselben Zuordnung**.
     *
     * Das ist ein Hinweis, kein Fehler: eine Übergangsphase oder eine Sonderkarte neben der
     * Standardkarte kann gewollt sein. Wer es nicht wollte, sieht es hier.
     *
     * @return list<array{brille:string,schluessel:string,zuordnung:string,art:string,ausgaben:list<array<string,mixed>>}>
     */
    public function konflikte(Team $team, mixed $stichtag = null): array
    {
        $out = [];

        foreach (['betrieb', 'kunde'] as $brille) {
            $gruppen = [];
            foreach ($this->laufendeAm($team, $stichtag) as $z) {
                $schluessel = $brille === 'betrieb' ? $z['outlet_id'] : $z['kunde_key'];
                if ($schluessel === null) {
                    continue;   // ohne Zuordnung kein Konflikt — das ist ein eigener Befund
                }
                $gruppen[$schluessel . '|' . $z['art']][] = $z;
            }

            foreach ($gruppen as $key => $ausgaben) {
                if (count($ausgaben) < 2) {
                    continue;
                }
                $out[] = [
                    'brille' => $brille,
                    'schluessel' => (string) $key,
                    'zuordnung' => $brille === 'betrieb'
                        ? ($ausgaben[0]['outlet_name'] ?? '—')
                        : ($ausgaben[0]['kunde'] ?? '—'),
                    'art' => $ausgaben[0]['art'],
                    'ausgaben' => $ausgaben,
                ];
            }
        }

        return $out;
    }

    /**
     * Zuordnungen ohne laufende Ausgabe — je Brille.
     *
     * In der Betriebsbrille sind das Standorte, an denen gerade nichts läuft. Das ist die
     * Frage, für die man eine Mehrbetriebs-Sicht überhaupt baut.
     *
     * @return list<array{schluessel:string|int,zuordnung:string,fehlende_arten:list<string>}>
     */
    public function luecken(Team $team, string $brille = 'betrieb', mixed $stichtag = null): array
    {
        $laufend = $this->laufendeAm($team, $stichtag);

        if ($brille === 'betrieb') {
            $zuordnungen = FoodAlchemistOutlet::where('team_id', $team->id)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name'])->mapWithKeys(fn ($o) => [(int) $o->id => (string) $o->name])->all();
            $keyVon = fn (array $z) => $z['outlet_id'];
        } else {
            // Kunden sind kein gepflegtes Vokabular — die Menge ergibt sich aus dem Bestand.
            $zuordnungen = [];
            foreach ($this->uebersicht($team, $stichtag) as $z) {
                if ($z['kunde_key'] !== null) {
                    $zuordnungen[$z['kunde_key']] = (string) $z['kunde'];
                }
            }
            $keyVon = fn (array $z) => $z['kunde_key'];
        }

        $out = [];
        foreach ($zuordnungen as $key => $name) {
            $vorhanden = [];
            foreach ($laufend as $z) {
                if ($keyVon($z) !== null && (string) $keyVon($z) === (string) $key) {
                    $vorhanden[$z['art']] = true;
                }
            }
            $fehlt = array_values(array_diff(array_keys(self::ARTEN), array_keys($vorhanden)));
            if ($fehlt !== []) {
                $out[] = ['schluessel' => $key, 'zuordnung' => $name, 'fehlende_arten' => $fehlt];
            }
        }

        return $out;
    }

    /**
     * Ausgaben ohne Betrieb UND ohne Kunde.
     *
     * Ohne diesen Block wären sie in beiden Brillen unsichtbar — genau die Art stiller Lücke,
     * die eine Übersicht wertlos macht.
     *
     * @return list<array<string,mixed>>
     */
    public function ohneZuordnung(Team $team, mixed $stichtag = null): array
    {
        return array_values(array_filter(
            $this->uebersicht($team, $stichtag),
            fn ($z) => $z['outlet_id'] === null && $z['kunde_key'] === null,
        ));
    }

    /**
     * Läuft in derselben Zuordnung schon eine Ausgabe derselben Art? Für den Hinweis beim
     * Aktivsetzen — Text oder `null`.
     */
    public function konfliktHinweis(Team $team, string $art, int $id, mixed $stichtag = null): ?string
    {
        foreach ($this->konflikte($team, $stichtag) as $k) {
            if ($k['art'] !== $art) {
                continue;
            }
            foreach ($k['ausgaben'] as $z) {
                if ($z['id'] !== $id) {
                    continue;
                }
                $andere = array_values(array_filter($k['ausgaben'], fn ($x) => $x['id'] !== $id));
                if ($andere === []) {
                    continue;
                }

                return 'Läuft parallel zu „' . $andere[0]['name'] . '" '
                    . ($k['brille'] === 'betrieb' ? 'im Betrieb ' : 'beim Kunden ') . $k['zuordnung'] . '.';
            }
        }

        return null;
    }

    // ── intern ────────────────────────────────────────────────────────────────

    /**
     * Die Ausgaben je Art, mit allem eager geladen, was die Zeilenform braucht.
     *
     * Beim Speiseplan zusätzlich `withMin`/`withMax` auf die Einträge: sein Gültigkeitsfenster
     * wird daraus abgeleitet, und ohne die Aggregate wäre jede Zeile eine eigene Abfrage
     * (klassischer N+1 in genau der Liste, für die dieser Dienst existiert).
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function ausgaben(Team $team, ?string $nurArt): array
    {
        $out = [];

        if ($nurArt === null || $nurArt === 'foodbook') {
            $out['foodbook'] = FoodAlchemistFoodbook::visibleToTeam($team)
                ->with('outlet:id,name')->withCount('chapters')->get();
        }
        if ($nurArt === null || $nurArt === 'speisekarte') {
            $out['speisekarte'] = FoodAlchemistSpeisekarte::visibleToTeam($team)
                ->with('outlet:id,name')->withCount('sections')->get();
        }
        if ($nurArt === null || $nurArt === 'speiseplan') {
            $out['speiseplan'] = FoodAlchemistSpeiseplan::visibleToTeam($team)
                ->with('outlet:id,name')->withCount('entries')
                ->withMin('entries', 'entry_date')->withMax('entries', 'entry_date')->get();
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function zeile(string $art, mixed $a, Carbon $tag): array
    {
        // Die drei Models heißen ihre Bezeichnung unterschiedlich (`label` vs. `name`) — das
        // ist der einzige Ort, an dem dieser Unterschied noch auftaucht.
        $name = (string) ($a->label ?? $a->name ?? ('#' . $a->id));
        $kunde = $a->kundeLabel();

        return [
            'art' => $art,
            'art_label' => self::ARTEN[$art]['label'],
            'id' => (int) $a->id,
            'name' => $name,
            'status' => $a->statusWert()->value,
            'status_label' => $a->statusWert()->label(),
            'zustand' => $a->laufZustand($tag),
            'grund' => $a->laufGrund($tag),
            'laeuft' => $a->laeuftAm($tag),
            'von' => $a->gueltigVon()?->toDateString(),
            'bis' => $a->gueltigBis()?->toDateString(),
            'outlet_id' => $a->outlet_id !== null ? (int) $a->outlet_id : null,
            'outlet_name' => $a->outlet?->name,
            'kunde' => $kunde,
            // Kunden haben kein Vokabular: die CRM-id ist der stabile Schlüssel, sonst der
            // normalisierte Freitext. Ohne Normalisierung wären „Klinikum West" und
            // „klinikum west " zwei Kunden.
            'kunde_key' => $a->crm_company_id !== null
                ? 'crm:' . (int) $a->crm_company_id
                : ($kunde !== null ? 'txt:' . mb_strtolower(trim($kunde)) : null),
            'n_positionen' => (int) ($a->chapters_count ?? $a->sections_count ?? $a->entries_count ?? 0),
            'route' => route(self::ARTEN[$art]['route'], [self::ARTEN[$art]['param'] => $a->id]),
        ];
    }
}
