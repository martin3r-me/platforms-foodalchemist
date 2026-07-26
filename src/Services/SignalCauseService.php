<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 21 · Tranche P · Punkt 5 (Etappe S3b-3) — die **Ursachen-Kette nach unten**.
 *
 * Die Signal-Zeile sagt WAS fehlt („Basisrezepte teil-unbepreist"), das Panel sagte
 * bisher WELCHE Objekte. Offen blieb das WARUM, und genau daran hängt die Entscheidung:
 * ein teil-unbepreistes Rezept, dessen GP nur den Lead-LA verloren hat, ist ein Klick;
 * eines, dessen GP gar keinen Lieferantenartikel hat, ist eine Einkaufs-Aufgabe. Beide
 * sehen im Cockpit identisch aus.
 *
 * Aufgelöst wird über drei Stufen (Spec §7 Punkt 5):
 *   Rezept → unbepreiste Zutat → GP → Lead-LA-Lage
 * und für Regelwerk-Befunde: Rezept → verletztes § → Deep-Link ins Wissens-Modul.
 *
 * **Per OBJEKT, nicht per Signal.** Ein Aggregat-Signal wie `br_ek_teil` deckt n
 * Rezepte ab; „welche GPs sind unbepreist" hat nur je Rezept eine Antwort. Deshalb
 * hängt die Kette an dem Objekt, das im Panel aufgeklappt ist (dieselbe Fläche wie die
 * objekt-zentrische Sicht aus S3a) — ein Klick liefert „was hat dieses Objekt noch"
 * UND „warum".
 *
 * Read-only: dieser Service mutiert nichts. Er rechnet auch keine Preise — die
 * Preis-Kaskade bleibt allein in {@see RecipeRecomputeService} (eine Regel-Stelle);
 * hier wird nur gefragt, welche Zeile sie als unbepreist ausgewiesen hat, und
 * anschließend die **Beschaffungs-Lage** des GP diagnostiziert (LAs vorhanden?
 * bepreist? Lead gesetzt?). Das ist eine andere Frage als „was kostet er".
 */
class SignalCauseService
{
    /** Wie viele unbepreiste Zutaten einzeln aufgeschlüsselt werden (darüber: „… und n weitere"). */
    public const ZUTATEN_LIMIT = 15;

    /**
     * Fall-Schlüssel aus {@see DataQualityService::namingBefundeFuer} → verletztes §.
     *
     * Der `slug` zeigt auf das Regelwerk im Wissens-Modul (`foodalchemist_knowledge_documents`);
     * die ID wird zur Laufzeit aufgelöst, weil sie je Umgebung anders ist. Fehlt das Dokument
     * (frische Installation ohne Wissens-Import), bleibt der §-Text stehen und der Link
     * entfällt — ein toter Link wäre schlechter als keiner.
     */
    private const NAMING_PARAGRAPHEN = [
        'leerraum' => [
            'paragraph' => 'Regelwerk_Basisrezepte §1',
            'slug' => 'regelwerk.regelwerk_basisrezepte',
            'regel' => 'Mehrfach-Leerzeichen im Namen — Rest aus Import/Split, nicht Teil der Benennung.',
        ],
        'trenner_rand' => [
            'paragraph' => 'Regelwerk_Basisrezepte §1',
            'slug' => 'regelwerk.regelwerk_basisrezepte',
            'regel' => 'Trennzeichen am Namensanfang oder -ende (| , ; : – -) — Rest aus Import/Split.',
        ],
        'grammatur' => [
            'paragraph' => 'Regelwerk_Verkaufsgerichte §1.2a (Basisrezepte §1.8)',
            'slug' => 'regelwerk.regelwerk_verkaufsgerichte',
            'regel' => 'Grammatur im Namen ist nur als Diskriminator zwischen sonst gleichnamigen '
                . 'Gerichten erlaubt. Diesen Zwilling gibt es nicht → die Angabe gehört ins Datenfeld.',
        ],
        'vk_marker' => [
            'paragraph' => 'Regelwerk_Verkaufsgerichte §1.2',
            'slug' => 'regelwerk.regelwerk_verkaufsgerichte',
            'regel' => 'Katalog-/Marker-Code im VK-Namen (CC: · STF: · MS: · (SG) · (BOX) · ADD ON · [FC]) — '
                . 'ersatzlos raus, die Information gehört in ein Feld.',
        ],
        'vk_praefix' => [
            'paragraph' => 'Regelwerk_Verkaufsgerichte §1.1',
            'slug' => 'regelwerk.regelwerk_verkaufsgerichte',
            'regel' => 'VK-Gericht ohne führendes [HG]-Hauptgruppen-Kürzel (Pipe-Skelett „[HG] A | B").',
        ],
    ];

    public function __construct(
        private DataQualityService $dq,
        private RecipeRecomputeService $recompute,
        private PriceService $preise,
    ) {
    }

    /**
     * Alle Ursachen-Blöcke zu EINEM Objekt. Leeres Array = nichts zu erklären
     * (das Objekt ist an dieser Stelle in Ordnung, der Befund liegt woanders).
     *
     * Bewusst ohne Signal-Parameter: die Kette gehört dem Objekt. Wer ein Rezept
     * öffnet, das gleichzeitig teil-unbepreist ist UND gegen das Naming-Regelwerk
     * verstößt, soll beides sehen — nicht nur das, worüber er hereingekommen ist.
     *
     * @param  string  $kind  'recipe'|'gp'
     * @return list<array<string,mixed>>
     */
    public function fuerObjekt(Team $team, string $kind, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        if ($kind === 'gp') {
            $gp = FoodAlchemistGp::visibleToTeam($team)->find($id);

            return $gp !== null ? array_values(array_filter([$this->gpBlock($team, $gp)])) : [];
        }
        if ($kind !== 'recipe') {
            return [];
        }

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find($id);
        if ($recipe === null) {
            return [];
        }

        return array_values(array_filter([
            $this->ekBlock($team, $recipe),
            $this->regelwerkBlock($team, $recipe),
        ]));
    }

    // ── Stufe 1–3: EK-Kette ────────────────────────────────────────────────

    /**
     * Rezept → unbepreiste Zutaten → GP → Lead-LA-Lage.
     *
     * Welche Zeile unbepreist ist, entscheidet NICHT dieser Service, sondern
     * {@see RecipeRecomputeService::zeilenKosten()} — dieselbe T3-Kaskade, die auch
     * `ek_n_ingredients_priced` füllt. Eine eigene Nachbildung wäre die zweite
     * Wahrheit, an der die Erklärung irgendwann von der Zahl abweicht.
     *
     * @return array<string,mixed>|null
     */
    private function ekBlock(Team $team, FoodAlchemistRecipe $recipe): ?array
    {
        $total = (int) ($recipe->ek_n_ingredients_total ?? 0);
        $priced = (int) ($recipe->ek_n_ingredients_priced ?? 0);
        if ($recipe->ek_total_eur !== null && $priced >= $total) {
            return null;                                    // EK löst vollständig auf
        }

        $recipe->loadMissing(['ingredients.gp', 'ingredients.unit', 'ingredients.referencedRecipe']);
        $kosten = $this->recompute->zeilenKosten($recipe);

        $glieder = [];
        $offen = 0;
        $gpCache = [];
        foreach ($recipe->ingredients as $z) {
            if (! array_key_exists($z->id, $kosten) || $kosten[$z->id] !== null) {
                continue;                                   // gefiltert (optional/ignored) oder bepreist
            }
            $offen++;
            if (count($glieder) >= self::ZUTATEN_LIMIT) {
                continue;
            }
            $glieder[] = $this->zutatUrsache($team, $z, $gpCache);
        }

        // Ungemappte Zutaten stehen gar nicht erst in der Kaskade — sie senken den EK
        // still, ohne die Teil-Quote zu bewegen. Darum getrennt gezählt und benannt.
        $ungemappt = (int) ($recipe->n_ingredients_unmapped ?? 0);

        if ($glieder === [] && $ungemappt === 0) {
            return null;
        }

        return [
            'art' => 'ek',
            'titel' => 'Warum die EK-Kette nicht auflöst',
            'kopf' => $total > 0
                ? $priced . ' von ' . $total . ' Zutaten bepreist'
                : 'Keine bepreisbare Zutat in der Kette',
            'glieder' => $glieder,
            'offen' => $offen,
            'gekappt' => max(0, $offen - count($glieder)),
            'ungemappt' => $ungemappt,
        ];
    }

    /**
     * Eine unbepreiste Zeile erklären — und, wo der GP der Grund ist, direkt die dritte
     * Stufe mitliefern (Lead-LA-Lage). Damit steht in einer Zeile, ob das ein Klick
     * (`fixbar`) oder eine Beschaffungs-Aufgabe ist.
     *
     * @param  array<int,array<string,mixed>>  $gpCache  je GP nur eine Beschaffungs-Abfrage
     * @return array<string,mixed>
     */
    private function zutatUrsache(Team $team, object $z, array &$gpCache): array
    {
        $basis = [
            'zutat' => (string) ($z->raw_text ?: ($z->gp?->name ?? '—')),
            'menge' => trim((string) $z->quantity . ' ' . (string) ($z->unit?->slug ?? '')),
            'gp_id' => null,
            'gp_name' => null,
            'recipe_id' => null,
            'fixbar' => false,
        ];

        if ($z->referenced_recipe_id !== null) {
            $sub = $z->referencedRecipe;

            $basis['recipe_id'] = $sub?->id !== null ? (int) $sub->id : null;

            return $basis + [
                'ursache' => 'Sub-Rezept ohne eigenen EK',
                'weiter' => $sub !== null
                    ? 'Das Sub-Rezept „' . $sub->name . '" hat selbst keinen EK je kg — die Kette bricht eine Ebene tiefer.'
                    : 'Referenziertes Sub-Rezept nicht auffindbar.',
            ];
        }

        $gp = $z->gp;
        if ($gp === null) {
            return $basis + [
                'ursache' => 'Zutat auf keinen GP gemappt',
                'weiter' => 'Ohne GP gibt es keine Preisquelle — im Rezept-Editor mappen.',
            ];
        }

        $lage = $gpCache[$gp->id] ??= $this->gpBeschaffung($team, $gp);
        $basis['gp_id'] = (int) $gp->id;
        $basis['gp_name'] = (string) $gp->name;

        // Wichtig: ein FEHLENDER Lead-LA bricht die EK-Kette NICHT — die T3-Kaskade fällt
        // auf den Durchschnitt über die aktiven, bepreisten LAs zurück
        // ({@see RecipeRecomputeService::preisProGrammFuer}). Preis-blockierend sind darum
        // nur die zwei Lagen, in denen es GAR KEINEN bepreisten LA gibt. Steht hier trotzdem
        // eine unbepreiste Zeile, liegt es an der Menge/Einheit — die Lead-Frage wird dann
        // als Nebenbefund angehängt statt als Ursache behauptet.
        if ($lage['loest_auf']) {
            return $basis + [
                'ursache' => 'Menge nicht umrechenbar',
                'weiter' => 'Der GP hat eine Preisquelle, aber die Einheit „' . ($z->unit?->slug ?? '?')
                    . '" liefert kein Gewicht (kein Stückgewicht am GP / Menge 0 / „qs").'
                    . ($lage['status'] !== 'ok' ? ' Nebenbefund: ' . lcfirst($lage['ursache']) . '.' : ''),
            ];
        }

        return $basis + [
            'ursache' => $lage['ursache'],
            'weiter' => $lage['weiter'],
            'fixbar' => $lage['fixbar'],
        ];
    }

    // ── Stufe 3 einzeln: der GP selbst ─────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function gpBlock(Team $team, FoodAlchemistGp $gp): ?array
    {
        $lage = $this->gpBeschaffung($team, $gp);
        if ($lage['status'] === 'ok') {
            return null;
        }

        return [
            'art' => 'gp',
            'titel' => 'Warum dieser GP nicht auf einen Preis auflöst',
            'kopf' => $lage['ursache'],
            'glieder' => [[
                'zutat' => (string) $gp->name,
                'menge' => '',
                'ursache' => $lage['ursache'],
                'weiter' => $lage['weiter'],
                'gp_id' => (int) $gp->id,
                'gp_name' => (string) $gp->name,
                'recipe_id' => null,
                'fixbar' => $lage['fixbar'],
            ]],
            'offen' => 1,
            'gekappt' => 0,
            'ungemappt' => 0,
        ];
    }

    /**
     * Beschaffungs-Lage eines GP — die dritte Stufe der Kette.
     *
     * Bewusst KEINE Preisrechnung: gefragt wird, ob überhaupt ein bepreister
     * Lieferantenartikel existiert und ob der Lead darauf zeigt. Das trennt die zwei
     * Fälle, die im Cockpit identisch aussehen und völlig verschiedene Arbeit sind —
     * „Lead zeigt ins Leere" (ein Klick, der `lead_la`-Fixer kann es) gegen „es gibt
     * keinen bepreisten LA" (Einkauf muss ran).
     *
     * `loest_auf` sagt, ob die T3-Kaskade an diesem GP überhaupt einen Preis findet —
     * und das ist NICHT dasselbe wie „Lead gesetzt": ohne Lead mittelt die Kaskade über
     * die aktiven bepreisten LAs. Wer das gleichsetzt, behauptet in der EK-Erklärung eine
     * Ursache, die keine ist.
     *
     * @return array{status:string,ursache:string,weiter:string,fixbar:bool,loest_auf:bool}
     */
    private function gpBeschaffung(Team $team, FoodAlchemistGp $gp): array
    {
        $las = DB::table('foodalchemist_supplier_items as si')
            ->join('foodalchemist_supplier_item_structures as s', 's.supplier_item_id', '=', 'si.id')
            ->where('s.gp_id', $gp->id)
            ->whereNull('s.deleted_at')
            ->whereNull('si.deleted_at')
            ->select('si.id', 'si.is_discontinued')
            ->get();

        $n = $las->count();
        if ($n === 0) {
            return ['status' => 'kein_la', 'ursache' => 'GP ohne Lieferantenartikel',
                'weiter' => 'Kein einziger LA ist auf diesen GP strukturiert — Beschaffungs-Lücke, kein Fix möglich.',
                'fixbar' => false, 'loest_auf' => false];
        }

        $bepreist = [];
        foreach ($las as $la) {
            if (! $la->is_discontinued && $this->preisLoestAuf((int) $la->id)) {
                $bepreist[] = (int) $la->id;
            }
        }
        $nB = count($bepreist);

        if ($nB === 0) {
            return ['status' => 'kein_preis', 'ursache' => 'Kein Lieferantenartikel mit gültigem Preis',
                'weiter' => $n . ' LA verknüpft, aber keiner mit aktivem Preis > 0 (gesperrt/ausgelistet/ohne Preiszeile) '
                    . '— Preispflege oder Einkauf.',
                'fixbar' => false, 'loest_auf' => false];
        }

        $lead = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;
        if ($lead === null) {
            return ['status' => 'kein_lead', 'ursache' => 'Kein Lead-Lieferantenartikel gesetzt',
                'weiter' => $nB . ' von ' . $n . ' LA sind bepreist — der Lead-LA-Fixer kann einen davon setzen. '
                    . 'Die EK-Kette rechnet solange mit dem Durchschnitt statt mit einem gewählten Artikel.',
                'fixbar' => true, 'loest_auf' => true];
        }
        if (! in_array($lead, $bepreist, true)) {
            return ['status' => 'lead_ohne_preis', 'ursache' => 'Lead-LA ohne gültigen Preis',
                'weiter' => 'Der gesetzte Lead-LA (#' . $lead . ') löst nicht auf, ' . $nB . ' andere schon '
                    . '— der Lead-LA-Fixer kann umhängen. Die EK-Kette weicht solange auf den Durchschnitt aus.',
                'fixbar' => true, 'loest_auf' => true];
        }

        return ['status' => 'ok', 'ursache' => 'GP löst auf einen Preis auf',
            'weiter' => 'Lead-LA #' . $lead . ' ist bepreist.', 'fixbar' => false, 'loest_auf' => true];
    }

    private function preisLoestAuf(int $laId): bool
    {
        $p = $this->preise->activeFor($laId);

        return $p !== null && (float) $p->price > 0;
    }

    // ── Regelwerk-Deep-Link ────────────────────────────────────────────────

    /**
     * Verletzte §§ mit Sprung ins Wissens-Modul.
     *
     * Der Link ist echt und nicht dekorativ: die Regelwerke liegen als Dokumente in
     * `foodalchemist_knowledge_documents` (Slug `regelwerk.*`), der Wissens-Browser
     * nimmt `?doc=<id>` aus der URL. Aufgelöst wird über den Slug, weil die ID je
     * Umgebung abweicht; ohne Dokument bleibt der §-Text ohne Link stehen.
     *
     * @return array<string,mixed>|null
     */
    private function regelwerkBlock(Team $team, FoodAlchemistRecipe $recipe): ?array
    {
        $faelle = $this->dq->namingBefundeFuer($team, (int) $recipe->id);
        if ($faelle === []) {
            return null;
        }

        $glieder = [];
        foreach ($faelle as $fall) {
            $def = self::NAMING_PARAGRAPHEN[$fall] ?? null;
            if ($def === null) {
                continue;
            }
            $glieder[] = [
                'fall' => $fall,
                'paragraph' => $def['paragraph'],
                'regel' => $def['regel'],
                'url' => $this->regelwerkUrl($def['slug']),
            ];
        }

        return $glieder === [] ? null : [
            'art' => 'regelwerk',
            'titel' => 'Verletzte Benennungs-Regel',
            'kopf' => count($glieder) === 1 ? '1 Regel' : count($glieder) . ' Regeln',
            'glieder' => $glieder,
        ];
    }

    private function regelwerkUrl(string $slug): ?string
    {
        // Route-Guard: das Modul kann ohne registrierte Routen laufen (Konsolen-/Tool-Pfade,
        // Tests). Eine RouteNotDefinedException wäre hier ein Absturz für eine Beigabe.
        if (! \Illuminate\Support\Facades\Route::has('foodalchemist.knowledge.index')) {
            return null;
        }
        $id = DB::table('foodalchemist_knowledge_documents')
            ->where('slug', $slug)->whereNull('deleted_at')->value('id');

        return $id !== null ? route('foodalchemist.knowledge.index', ['doc' => (int) $id]) : null;
    }
}
