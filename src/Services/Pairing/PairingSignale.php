<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AussageTyp;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 60 · P8: die vier Pairing-Signale. Ersetzen `widerspruch_wissen_graph` (das alte Signal
 * verglich Pairing-Dokumente mit dem Graphen; diese Dokumente gibt es nicht mehr).
 *
 *   pairing_wissen_pruefen       Anker-Wissen im Entwurf an einem viel genutzten Anker → anker-weise freigeben
 *   pairing_widerspruch_messung  Dossier sagt „Zerstört", Foodpairing misst 3★ → Beleg prüfen
 *   pairing_konflikt_im_gericht  zwei Bestandteile eines Gerichts stören sich (Kombinationslogik)
 *   pairing_wissensluecke        Anker trägt viele Rezepte, aber es gibt noch kein Anker-Wissen
 *
 * Anker-Wissen ist global. Die drei Wissens-Signale erscheinen darum nur beim Kurator
 * ({@see TeamScope::isMaster}) — nur er kann sie auflösen — und nur für Anker, die seine Rezepte
 * tragen (Kern-Anteil ≥ {@see RezeptProfil::KERN_FUER_BEDARF} %). Der Konflikt im Gericht gehört
 * dem Team, dessen Gericht es ist.
 *
 * Jeder Detektor läuft vollständig (kein Cap) und schließt danach, was nicht mehr zutrifft.
 */
final class PairingSignale
{
    /** Ab so vielen Rezepten (Anker als Kern) ist offenes Entwurfs-Wissen eine Arbeitsliste wert. */
    public const PRUEFEN_AB_REZEPTEN = 3;

    /** Ab so vielen Rezepten ist fehlendes Anker-Wissen eine Lücke, die Aussagen kostet. */
    public const LUECKE_AB_REZEPTEN = 10;

    private const QUELLE = 'detektor';

    public function __construct(
        private readonly SignalService $signals,
        private readonly Kombinationslogik $logik,
    ) {}

    public function alle(Team $team): int
    {
        return $this->wissenZurPruefung($team)
            + $this->widerspruchWissenMessung($team)
            + $this->wissensluecke($team)
            + $this->konfliktImGericht($team);
    }

    public function wissenZurPruefung(Team $team): int
    {
        $keys = [];
        if (TeamScope::isMaster($team)) {
            $nutzung = $this->nutzung($team, self::PRUEFEN_AB_REZEPTEN);
            $entwurf = $this->wissenJeAnker($nutzung->keys()->all(), WissensStatus::Entwurf);
            $namen = $this->namen(array_keys($entwurf));
            foreach ($entwurf as $id => $z) {
                $summe = array_sum($z);
                $key = 'pairing-wissen-pruefen-'.$id;
                $this->signals->erzeuge($team, SignalTyp::PairingWissenPruefen, SignalSeverity::Info,
                    ($namen[$id] ?? "Anker #{$id}").": {$summe} Wissens-Einträge im Entwurf · Kern in {$nutzung[$id]} Rezepten",
                    [
                        'dedup_key' => $key, 'ref_type' => 'pairing_anchor', 'ref_id' => $id, 'source' => self::QUELLE,
                        'description' => 'Aus dem Zutaten-Dossier ausgelesen, noch nicht geprüft. Die Aussagen zu diesem Anker '
                            .'tragen „aus Dossier, ungeprüft". Prüfen und anker-weise freigeben oder verwerfen.',
                        'payload' => ['anchor_id' => $id, 'eintraege' => $z, 'n_rezepte' => (int) $nutzung[$id],
                            'werkzeug' => 'foodalchemist.anker_wissen.GET → foodalchemist.anker_wissen.STATUS'],
                    ]);
                $keys[] = $key;
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::PairingWissenPruefen, self::QUELLE, $keys,
            'Anker-Wissen geprüft oder Anker nicht mehr tragend — automatisch geschlossen');

        return count($keys);
    }

    public function widerspruchWissenMessung(Team $team): int
    {
        $keys = [];
        if (TeamScope::isMaster($team)) {
            $genutzt = $this->nutzung($team, 1)->keys()->all();
            $faelle = $genutzt === [] ? collect() : DB::table('foodalchemist_anchor_beziehungen as b')
                ->join(AnkerGraph::TABELLE.' as h', fn ($j) => $j->on('h.anchor_a_id', '=', 'b.anchor_a_id')->on('h.anchor_b_id', '=', 'b.anchor_b_id'))
                ->where('b.art', 'konflikt')->where('b.status', '!=', WissensStatus::Verworfen->value)
                ->where('h.stufe', AnkerGraph::HARMONIERT)
                ->where(fn ($q) => $q->whereIn('b.anchor_a_id', $genutzt)->orWhereIn('b.anchor_b_id', $genutzt))
                ->orderBy('b.anchor_a_id')->orderBy('b.anchor_b_id')
                ->get(['b.anchor_a_id as a', 'b.anchor_b_id as b', 'b.status', 'b.beleg']);
            $namen = $this->namen($faelle->flatMap(fn ($f) => [(int) $f->a, (int) $f->b])->all());
            $gesehen = [];
            foreach ($faelle as $f) {
                [$x, $y] = [min((int) $f->a, (int) $f->b), max((int) $f->a, (int) $f->b)];
                $key = "pairing-widerspruch-{$x}-{$y}";
                if (isset($gesehen[$key])) {
                    continue;                                    // Dossier beider Seiten nennt denselben Konflikt
                }
                $gesehen[$key] = true;
                $this->signals->erzeuge($team, SignalTyp::PairingWiderspruchMessung, SignalSeverity::Warnung,
                    ($namen[$x] ?? "#{$x}").' und '.($namen[$y] ?? "#{$y}").': Dossier sagt „stört sich", Foodpairing misst 3★',
                    [
                        'dedup_key' => $key, 'ref_type' => 'pairing_anchor', 'ref_id' => (int) $f->a, 'source' => self::QUELLE,
                        'description' => 'Messung und Wissen widersprechen sich. Beides kann stimmen (gleiche Aromen, aber z. B. '
                            .'Textur oder Säure stört) — dann den Konflikt mit Grund behalten; sonst verwerfen.',
                        'payload' => ['anchor_a_id' => (int) $f->a, 'anchor_b_id' => (int) $f->b, 'wissen_status' => $f->status,
                            'beleg' => $f->beleg, 'messung' => 'stufe_3',
                            'werkzeug' => 'foodalchemist.anker_wissen.GET → foodalchemist.anker_wissen.STATUS'],
                    ]);
                $keys[] = $key;
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::PairingWiderspruchMessung, self::QUELLE, $keys,
            'Widerspruch aufgelöst (Konflikt verworfen oder nicht mehr genutzt) — automatisch geschlossen');

        return count($keys);
    }

    public function wissensluecke(Team $team): int
    {
        $keys = [];
        if (TeamScope::isMaster($team)) {
            $nutzung = $this->nutzung($team, self::LUECKE_AB_REZEPTEN);
            $mitWissen = $this->wissenJeAnker($nutzung->keys()->all(), null);
            $luecken = $nutzung->reject(fn ($n, $id) => isset($mitWissen[$id]));
            $namen = $this->namen($luecken->keys()->all());
            foreach ($luecken as $id => $n) {
                $key = 'pairing-wissensluecke-'.$id;
                $this->signals->erzeuge($team, SignalTyp::PairingWissensluecke, SignalSeverity::Info,
                    ($namen[$id] ?? "Anker #{$id}").": Kern in {$n} Rezepten, noch kein Anker-Wissen",
                    [
                        'dedup_key' => $key, 'ref_type' => 'pairing_anchor', 'ref_id' => (int) $id, 'source' => self::QUELLE,
                        'description' => 'Ohne Anker-Wissen gibt es für diesen Anker keine Bedarfe, keinen Kontrast und keine '
                            .'Konflikte — nur die gemessene Harmonie. Zutaten-Dossier auslesen (foodalchemist:anker-wissen import).',
                        'payload' => ['anchor_id' => (int) $id, 'n_rezepte' => (int) $n],
                    ]);
                $keys[] = $key;
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::PairingWissensluecke, self::QUELLE, $keys,
            'Anker-Wissen liegt vor oder Anker nicht mehr tragend — automatisch geschlossen');

        return count($keys);
    }

    /**
     * Gerichte des Teams, in denen zwei Bestandteile einen Konflikt haben. Vorfilter über das
     * Profil (beide Konflikt-Anker im Gericht), bestätigt durch die Kombinationslogik — dieselbe
     * Aussage, die das Panel zeigt.
     */
    public function konfliktImGericht(Team $team): int
    {
        $keys = [];
        $paare = DB::table('foodalchemist_anchor_beziehungen')->where('art', 'konflikt')
            ->where('status', '!=', WissensStatus::Verworfen->value)->get(['anchor_a_id', 'anchor_b_id']);
        if ($paare->isNotEmpty()) {
            $anker = $paare->flatMap(fn ($p) => [(int) $p->anchor_a_id, (int) $p->anchor_b_id])->unique()->values()->all();
            $jeRezept = DB::table('foodalchemist_recipe_profile_anker as pa')
                ->join('foodalchemist_recipes as r', 'r.id', '=', 'pa.recipe_id')
                ->where('r.team_id', $team->id)->whereNull('r.deleted_at')->where('r.is_sales_recipe', true)
                ->whereIn('pa.anchor_id', $anker)
                ->get(['pa.recipe_id', 'pa.anchor_id'])
                ->groupBy('recipe_id')->map(fn ($z) => array_flip($z->pluck('anchor_id')->map(fn ($i) => (int) $i)->all()));
            $kandidaten = $jeRezept->filter(fn ($a) => $paare->contains(fn ($p) => isset($a[(int) $p->anchor_a_id], $a[(int) $p->anchor_b_id])))->keys();

            foreach (FoodAlchemistRecipe::whereIn('id', $kandidaten)->orderBy('id')->get() as $gericht) {
                $konflikte = array_values(array_filter($this->logik->analysiere($gericht)['aussagen'],
                    fn ($x) => $x->typ === AussageTyp::Konflikt));
                if ($konflikte === []) {
                    continue;                                    // Konflikt liegt innerhalb EINES Bestandteils
                }
                $key = 'pairing-konflikt-gericht-'.$gericht->id;
                $this->signals->erzeuge($team, SignalTyp::PairingKonfliktImGericht, SignalSeverity::Warnung,
                    $gericht->name.': '.$konflikte[0]->text.(count($konflikte) > 1 ? ' (+'.(count($konflikte) - 1).')' : ''),
                    [
                        'dedup_key' => $key, 'ref_type' => 'recipe', 'ref_id' => (int) $gericht->id, 'source' => self::QUELLE,
                        'description' => 'Zwei Bestandteile des Gerichts stören sich laut Anker-Wissen. Bestandteil tauschen oder, '
                            .'wenn der Konflikt hier nicht greift, das Anker-Wissen korrigieren.',
                        'payload' => ['recipe_id' => (int) $gericht->id, 'konflikte' => array_map(fn ($x) => $x->toArray(), $konflikte)],
                    ]);
                $keys[] = $key;
            }
        }
        $this->signals->schliesseVerschwundene($team, SignalTyp::PairingKonfliktImGericht, self::QUELLE, $keys,
            'Kein Konflikt mehr im Gericht — automatisch geschlossen');

        return count($keys);
    }

    /**
     * Anker, die in mindestens $ab Rezepten des Teams Kern sind → anchor_id => Anzahl Rezepte.
     *
     * @return Collection<int, int>
     */
    private function nutzung(Team $team, int $ab): Collection
    {
        return DB::table('foodalchemist_recipe_profile_anker as pa')
            ->join('foodalchemist_recipes as r', 'r.id', '=', 'pa.recipe_id')
            ->where('r.team_id', $team->id)->whereNull('r.deleted_at')
            ->where('pa.anteil', '>=', RezeptProfil::KERN_FUER_BEDARF)
            ->groupBy('pa.anchor_id')->havingRaw('COUNT(*) >= ?', [$ab])
            ->orderByRaw('COUNT(*) DESC')->orderBy('pa.anchor_id')
            ->pluck(DB::raw('COUNT(*) as n'), 'pa.anchor_id')
            ->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n]);
    }

    /**
     * Wissen aus dem Dossier je Anker (abgeleitete Kontrast-Kanten und Nährwert-Eigenschaften
     * zählen nicht — sie sind kein ausgelesenes Wissen). Mit Status nur diese Einträge.
     *
     * @param  list<int>  $ids
     * @return array<int, array{bedarfe: int, eigenschaften: int, komponenten: int, beziehungen: int}>
     */
    private function wissenJeAnker(array $ids, ?WissensStatus $status): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $quellen = [
            'bedarfe' => [DB::table('foodalchemist_anchor_bedarfe'), 'anchor_id'],
            'eigenschaften' => [DB::table('foodalchemist_anchor_eigenschaften')->where('quelle', 'dossier'), 'anchor_id'],
            'komponenten' => [DB::table('foodalchemist_anchor_komponenten'), 'anchor_id'],
            'beziehungen' => [DB::table('foodalchemist_anchor_beziehungen')->where('grundlage', 'dossier'), 'anchor_a_id'],
        ];
        foreach ($quellen as $name => [$q, $spalte]) {
            $zeilen = $q->whereIn($spalte, $ids)->when($status !== null, fn ($q) => $q->where('status', $status->value))
                ->groupBy($spalte)->pluck(DB::raw('COUNT(*) as n'), $spalte);
            foreach ($zeilen as $id => $n) {
                $out[(int) $id] ??= ['bedarfe' => 0, 'eigenschaften' => 0, 'komponenten' => 0, 'beziehungen' => 0];
                $out[(int) $id][$name] = (int) $n;
            }
        }

        // Reihenfolge der Nutzung beibehalten (meistgenutzt zuerst)
        return array_replace(array_intersect_key(array_flip($ids), $out), $out);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function namen(array $ids): array
    {
        return $ids === [] ? [] : DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_unique($ids))
            ->pluck('display_de', 'id')->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
    }
}
