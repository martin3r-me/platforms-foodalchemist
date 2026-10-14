<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\VerweisRegister;

/**
 * Spec 82 §12 D · Durchstich Schritt 5: Kündigung und Zurückziehen als Ablauf.
 *
 * 1. „Genutzt" = in einer AKTIVEN Ausgabe des Mandanten bzw. seiner Unter-Teams (Foodbook, Speisekarte, Speiseplan nicht
 *    archiviert; Angebot nicht angenommen/abgelehnt; Konzept nicht archiviert) oder als Zutat in einem eigenen Rezept.
 *    Entwürfe zählen. Archivierte Ausgaben und historische Angebote bekommen KEINE Kopie, sie behalten die Fassung.
 * 2. Tiefe Kopie ohne Doppel: die ganze benötigte Kette, jedes (Basis-)Rezept genau einmal, alle Verweise auf diese Kopie,
 *    Ersatz-Beziehungen kommen mit. Kopie = normale Arbeitszeile des Mandanten (kopie_von_id = Familie, Stand = jetzt),
 *    danach mit SEINEN Preisen neu gerechnet.
 * 3. Zweites Abo: Was über ein anderes, weiter aktives Abo sichtbar bleibt, wird nicht kopiert und verschwindet nicht.
 * 4. Vorschau vor der Ausführung (bestaetigt = false).
 *
 * Fassungs-Zeilen werden nie gelöscht: eingefrorene Stände (Snapshots, archivierte Ausgaben) bleiben auflösbar.
 *
 * Spec 82 Abo Konzepte/Pakete/Formate, PR 6 (Übergabe Lisa): derselbe Ablauf für ALLE Arten des Abos — Rezept, Paket, Konzept (auch kind=paket); das Format
 * folgt mit PR 7. Alles läuft über (Art, ID), nie über die ID allein (Rezept 12 ≠ Paket 12). „Genutzt“ je Art steht im {@see VerweisRegister} (dieselben Filter
 * wie das Folgen, PR 5). Kopie von unten nach oben: erst alle Köpfe, dann die Inhalte (Rezepte samt Darreichungen → Pakete → Konzepte), danach mit den Preisen
 * des Mandanten gerechnet (Rezepte → Paket-Preise → Konzept-Caches). Die Kette wird, wie bei Rezepten heute, vollständig kopiert — auch Teile, die über ein
 * zweites Abo sichtbar blieben (bewusst gleich; „mitkopiert“ nennt sie). Titelbild und Galerie eines Konzepts werden in den Speicher des Mandanten mitkopiert (fehlt die Datei: Kopie ohne Bild + Hinweis, nie ein Abbruch).
 */
class AboKuendigungService
{
    /** Ausgabe-Positionen → Pfad zum Eltern-Objekt und dessen „aktiv"-Regel. */
    private const AUSGABEN = [
        'foodbook' => ['foodalchemist_foodbook_blocks AS x', 'foodalchemist_foodbook_chapters AS k', 'x.chapter_id', 'k.foodbook_id', 'foodalchemist_foodbooks AS p'],
        'speisekarte' => ['foodalchemist_menu_card_items AS x', 'foodalchemist_menu_card_sections AS k', 'x.section_id', 'k.menu_card_id', 'foodalchemist_menu_cards AS p'],
        'angebot' => ['foodalchemist_offer_blocks AS x', 'foodalchemist_offer_chapters AS k', 'x.chapter_id', 'k.offer_id', 'foodalchemist_offers AS p'],
    ];

    /** Arten im Abo, in Kopier-Reihenfolge (unten zuerst). Das Format folgt mit PR 7. */
    private const ARTEN = ['recipe', 'paket', 'concept'];

    private const TABELLE = ['recipe' => 'foodalchemist_recipes', 'paket' => 'foodalchemist_packages', 'concept' => 'foodalchemist_concepts'];

    /** @var list<string> Hinweise des laufenden Ablaufs (z. B. fehlende Bilddatei) — nie ein Abbruch */
    private array $hinweise = [];

    public function __construct(
        private FaRechte $rechte,
        private AboService $abos,
        private PlattformProtokoll $protokoll,
    ) {}

    /**
     * Abo kündigen. Darf: der Master mit Rolle „Freigeben" oder ein Admin des Mandanten (sein Oberteam). Ohne
     * bestaetigt = nur die Vorschau.
     *
     * @return array{vorschau: array<string,mixed>, ausgefuehrt: bool, kopien?: array<int,int>, kopien_je_art?: array<string,array<int,int>>, umgehaengt?: int, hinweise?: list<string>}
     */
    public function kuendigen(Team $handelnd, int $aboId, ?User $actor, bool $bestaetigt, bool $systemLauf = false): array
    {
        $abo = FoodAlchemistAbo::findOrFail($aboId);
        if (! $systemLauf) {
            $this->pruefeRecht($handelnd, $abo, $actor);
        }
        if ($abo->status !== FoodAlchemistAbo::AKTIV) {
            throw new \RuntimeException('Dieses Abo ist nicht mehr aktiv.');
        }
        $mandant = Team::findOrFail($abo->mandant_team_id);
        $this->hinweise = [];
        $vorher = $this->abos->huelle((int) $mandant->id);
        $nachher = $this->abos->huelle((int) $mandant->id, ohneAboId: (int) $abo->id);
        $vorschau = $this->vorschau($mandant, $this->wegfallend($vorher, $nachher), $nachher, (int) $abo->id);
        if (! $bestaetigt) {
            return ['vorschau' => $vorschau, 'ausgefuehrt' => false];
        }

        $ergebnis = DB::transaction(function () use ($abo, $mandant, $vorschau, $actor) {
            [$kopien, $umgehaengt] = $this->kopierenUndUmhaengen($mandant, $vorschau['genutzt_je_art'], $vorschau['bleibt_je_art']);
            $abo->forceFill(['status' => FoodAlchemistAbo::GEKUENDIGT, 'beendet_at' => now(), 'beendet_von' => $actor?->id])->save();

            return [$kopien, $umgehaengt];
        });
        $this->abos->neuBerechnen((int) $mandant->id);
        $this->nachrechnen($ergebnis[0]);
        $this->protokoll->schreibe('abo_gekuendigt', ['mandant_team_id' => $mandant->id, 'abo_id' => $abo->id,
            'kopiert' => $this->zaehle($ergebnis[0]), 'umgehaengt' => $ergebnis[1], 'verschwindet' => $this->zaehle($vorschau['verschwindet_je_art'])], $actor, $abo);

        // `kopien` bleibt die Rezept-Map (Aufrufer MandantBeenden/UI/Tests), `kopien_je_art` trägt alle Arten
        return ['vorschau' => $vorschau, 'ausgefuehrt' => true, 'kopien' => $ergebnis[0]['recipe'], 'kopien_je_art' => $ergebnis[0], 'umgehaengt' => $ergebnis[1], 'hinweise' => $this->hinweise];
    }

    /**
     * Master zieht ein Objekt (Rezept, Paket, Konzept) aus einer abonnierten Sammlung zurück (§8: Löschen eines abonnierten Objekts ist gesperrt, nur
     * Zurückziehen). Jeder betroffene Mandant läuft denselben Ablauf wie bei einer Kündigung.
     *
     * @param  'recipe'|'paket'|'concept'  $typ
     * @return array{mandanten: list<array{mandant_team_id:int, vorschau: array<string,mixed>, kopien?: array<int,int>, kopien_je_art?: array<string,array<int,int>>}>, ausgefuehrt: bool}
     */
    public function zurueckziehen(Team $master, int $sammlungId, int $objektId, ?User $actor, bool $bestaetigt, string $typ = 'recipe'): array
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Zurückziehen gibt es nur im Master-Team.');
        }
        if (! in_array($typ, self::ARTEN, true)) {
            throw new \RuntimeException('Zurückziehen gibt es für Rezepte, Pakete und Konzepte.');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Abo-Objekt zurückziehen');
        if (! DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sammlungId)->where('typ', $typ)->where('objekt_id', $objektId)->exists()) {
            throw new \RuntimeException('Das Objekt steht nicht in dieser Sammlung.');
        }

        $this->hinweise = [];
        $mandanten = [];
        $mandantIds = DB::table('foodalchemist_abos')->where('sammlung_id', $sammlungId)->where('status', FoodAlchemistAbo::AKTIV)
            ->distinct()->pluck('mandant_team_id')->map(fn ($v) => (int) $v)->all();
        foreach ($mandantIds as $mid) {
            $vorher = $this->abos->huelle($mid);
            $ohne = ['sammlung_id' => $sammlungId, 'objekt_id' => $objektId, 'typ' => $typ];
            $nachher = $this->abos->huelle($mid, ohneSammlungsObjekt: $ohne);
            $mandanten[$mid] = ['mandant_team_id' => $mid, 'vorschau' => $this->vorschau(Team::findOrFail($mid), $this->wegfallend($vorher, $nachher), $nachher, null, $ohne)];
        }
        if (! $bestaetigt) {
            return ['mandanten' => array_values($mandanten), 'ausgefuehrt' => false];
        }

        $alleKopien = [];
        DB::transaction(function () use ($sammlungId, $objektId, $typ, &$mandanten, &$alleKopien) {
            foreach ($mandanten as $mid => $m) {
                [$kopien, $umgehaengt] = $this->kopierenUndUmhaengen(Team::findOrFail($mid), $m['vorschau']['genutzt_je_art'], $m['vorschau']['bleibt_je_art']);
                $mandanten[$mid]['kopien'] = $kopien['recipe'];
                $mandanten[$mid]['kopien_je_art'] = $kopien;
                $mandanten[$mid]['umgehaengt'] = $umgehaengt;
                foreach ($kopien as $t => $map) {
                    $alleKopien[$t] = ($alleKopien[$t] ?? []) + $map;
                }
            }
            DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sammlungId)->where('typ', $typ)->where('objekt_id', $objektId)->delete();
        });
        $this->abos->neuBerechnenAlle();
        $this->nachrechnen($alleKopien + ['recipe' => [], 'paket' => [], 'concept' => []]);
        $this->protokoll->schreibe('abo_objekt_zurueckgezogen', ['sammlung_id' => $sammlungId, 'typ' => $typ, 'objekt_id' => $objektId,
            'mandanten' => array_keys($mandanten)], $actor, $this->modellFuer($typ)::mitFassungen()->find($objektId));

        return ['mandanten' => array_values($mandanten), 'ausgefuehrt' => true, 'hinweise' => $this->hinweise];
    }

    /** Was beim Wechsel von `$vorher` auf `$nachher` aus der Hülle fällt, je Art. @return array<string, list<int>> */
    private function wegfallend(array $vorher, array $nachher): array
    {
        $out = [];
        foreach (self::ARTEN as $t) {
            $out[$t] = array_values(array_diff($vorher[$t] ?? [], $nachher[$t] ?? []));
        }

        return $out;
    }

    /** @param array<string, array<int|string, mixed>> $jeArt */
    private function zaehle(array $jeArt): int
    {
        return array_sum(array_map('count', $jeArt));
    }

    /** @return class-string<FoodAlchemistRecipe|FoodAlchemistPaket|FoodAlchemistConcept> */
    private function modellFuer(string $typ): string
    {
        return match ($typ) {
            'paket' => FoodAlchemistPaket::class,
            'concept' => FoodAlchemistConcept::class,
            default => FoodAlchemistRecipe::class,
        };
    }

    /**
     * Was eine Kündigung/ein Zurückziehen beim Mandanten bewirkt: aus den wegfallenden Fassungen die genutzten (bekommen eine tiefe Kopie) und die
     * ungenutzten (verschwinden).
     *
     * Familien-Regel (Kurator 13.10.): Auch ÄLTERE Fassungen derselben Familien zählen, wenn ein lebender Verweis auf sie zeigt (z. B. eigenes Rezept bleibt
     * bei Allergen-Änderung bewusst auf Fassung 3, Paket 4d; Konzepte/Pakete ebenso seit PR 5). Kopiert wird je tatsächlich referenzierter Fassung — nie still
     * auf die neueste gehoben, sonst ändert sich das Allergenbild. Ungenutzte ältere Fassungen „verschwinden“ nicht: Sie waren nie in der Hülle.
     *
     * Schlüssel: `genutzt_ids`/`mitkopiert_ids`/`verschwindet_ids` bleiben REZEPT-IDs (Aufrufer UI #353, MandantBeenden, Tests); dieselben Mengen über alle
     * Arten stehen in `*_je_art` (Art → IDs); die Einträge `genutzt`/`mitkopiert`/`verschwindet` tragen `typ`.
     *
     * „Bleibt über anderes Abo“ (Entscheid Dominique 14.10., PR 6b): Was nach der Kündigung über ein anderes aktives Abo sichtbar bleibt, wird NICHT kopiert und
     * NICHT umgehängt — es bleibt auf der Fassung verknüpft und bekommt weiter Updates. Sichtbarkeit gilt je FAMILIE (die Hülle eines Abos trägt nur die neueste
     * Fassung; ein Verweis auf eine ältere derselben Familie bleibt, die Aktualisierung regelt das Folgen/„Übernehmen“ aus PR 5). Eigene vierte Liste `bleibt`.
     *
     * @param  array<string, list<int>>|list<int>  $wegfallend  Fassungs-IDs je Art, die danach nicht mehr in der Hülle stehen (eine reine ID-Liste = Rezepte)
     * @param  array<string, list<int>>  $nachher  Hülle des Mandanten NACH der Kündigung (leer = nichts bleibt sichtbar, wie vor PR 6b)
     * @param  ?array{sammlung_id:int, objekt_id:int, typ:string}  $ohneSammlungsObjekt  Kontext der Hülle `$nachher` bei „Zurückziehen“ (für die Auskunft „über welche Sammlung“)
     * @return array<string, mixed>
     */
    public function vorschau(Team $mandant, array $wegfallend, array $nachher = [], ?int $ohneAboId = null, ?array $ohneSammlungsObjekt = null): array
    {
        $wegfallend = $this->jeArt($wegfallend);
        $teams = RecomputeTeamRecipesJob::teamUndNachfahren((int) $mandant->id);
        $kandidaten = $nutzung = $genutzt = [];
        foreach (self::ARTEN as $t) {
            $kandidaten[$t] = array_values(array_unique(array_merge($wegfallend[$t], $this->aeltereFassungen($t, $wegfallend[$t]))));
            $nutzung[$t] = $kandidaten[$t] === [] ? [] : $this->nutzung($t, $kandidaten[$t], $teams);
            $genutzt[$t] = array_values(array_filter($kandidaten[$t], fn ($id) => isset($nutzung[$t][$id])));
        }
        // Sichtbarkeit je FAMILIE (Lisa, PR 6b): ein genutztes Objekt, dessen Familie nach der Kündigung über ein anderes Abo in der Hülle steht, bleibt auf seiner
        // Fassung verknüpft (z. B. die ältere Fassung, auf der eine Allergen-Sperre aus 4d/PR 5 hält) — keine Kopie; das Folgen/„Übernehmen“ regelt die Aktualisierung.
        $bleibtWurzel = [];
        foreach (self::ARTEN as $t) {
            $bleibtWurzel[$t] = $this->sichtbarBleibend($t, $genutzt[$t], $nachher[$t] ?? []);
            $genutzt[$t] = array_values(array_diff($genutzt[$t], $bleibtWurzel[$t]));
        }
        // Unter-Fassungen genutzter Objekte gehen in der tiefen Kopie mit — sie „verschwinden“ nicht; was über ein anderes Abo sichtbar bleibt, wird nicht kopiert
        $kette = $this->kette($genutzt);
        $mit = $verschwindet = $bleibt = [];
        foreach (self::ARTEN as $t) {
            $mitAlle = array_values(array_diff($kette[$t], $genutzt[$t]));
            $bleibt[$t] = array_values(array_unique(array_merge($this->sichtbarBleibend($t, $mitAlle, $nachher[$t] ?? []), $bleibtWurzel[$t])));
            $mit[$t] = array_values(array_diff($mitAlle, $bleibt[$t]));
            $verschwindet[$t] = array_values(array_diff($wegfallend[$t], $genutzt[$t], $mitAlle, $bleibt[$t]));
        }
        $via = $this->zuordnungBleibt($mandant, $bleibt, $ohneAboId, $ohneSammlungsObjekt);
        $namen = $this->anzeigeNamen(array_map(fn ($t) => array_merge($genutzt[$t], $mit[$t], $bleibt[$t], $verschwindet[$t]), array_combine(self::ARTEN, self::ARTEN)));   // Zusatz „(Fassung n)“ nur bei mehreren angezeigten Fassungen einer Familie
        foreach (self::ARTEN as $t) {
            sort($genutzt[$t]);
            sort($mit[$t]);
            sort($bleibt[$t]);
            sort($verschwindet[$t]);
        }
        $eintraege = function (array $jeArt, ?array $wo = null, ?array $viaMap = null) use ($namen) {
            $out = [];
            foreach (self::ARTEN as $t) {
                foreach ($jeArt[$t] as $id) {
                    $out[] = ['typ' => $t, 'id' => $id, 'name' => (string) ($namen[$t][$id] ?? '?')]
                        + ($wo === null ? [] : ['wo' => $wo[$t][$id] ?? []]) + ($viaMap === null ? [] : ['via' => $viaMap[$t][$id] ?? []]);
                }
            }

            return $out;
        };

        return [
            'genutzt_ids' => $genutzt['recipe'],
            'mitkopiert_ids' => $mit['recipe'],
            'verschwindet_ids' => $verschwindet['recipe'],
            'bleibt_ids' => $bleibt['recipe'],
            'genutzt_je_art' => $genutzt,
            'mitkopiert_je_art' => $mit,
            'verschwindet_je_art' => $verschwindet,
            'bleibt_je_art' => $bleibt,
            'genutzt' => $eintraege($genutzt, $nutzung),
            'mitkopiert' => $eintraege($mit),
            'verschwindet' => $eintraege($verschwindet),
            'bleibt' => $eintraege($bleibt, null, $via),
        ];
    }

    /**
     * Welche Kettenglieder nach der Kündigung SICHTBAR bleiben — je FAMILIE: die Familie des Glieds steht in der Hülle `$nachher` (auch wenn dort nur die neueste
     * Fassung steht und das Glied eine ältere ist).
     *
     * @param  list<int>  $glieder  Fassungs-IDs der Kette (ohne die Wurzeln)
     * @param  list<int>  $nachherIds  Hülle nach der Kündigung, diese Art
     * @return list<int>
     */
    private function sichtbarBleibend(string $typ, array $glieder, array $nachherIds): array
    {
        if ($glieder === [] || $nachherIds === []) {
            return [];
        }
        $tab = self::TABELLE[$typ];
        $familien = DB::table($tab)->whereIn('id', $nachherIds)->pluck('fassung_von_id')->map(fn ($v) => (int) $v)->flip();

        return DB::table($tab)->whereIn('id', $glieder)->get(['id', 'fassung_von_id'])
            ->filter(fn ($r) => $r->fassung_von_id !== null && $familien->has((int) $r->fassung_von_id))
            ->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
    }

    /**
     * Über welche Sammlung(en) bleibt ein Glied sichtbar? Je weiter aktivem Abo die Hülle des einzelnen Abos (huelle … nurAboId), je Familie.
     *
     * @param  array<string, list<int>>  $bleibt
     * @return array<string, array<int, list<string>>>  Art → Fassungs-ID → Sammlungsnamen
     */
    private function zuordnungBleibt(Team $mandant, array $bleibt, ?int $ohneAboId, ?array $ohneSammlungsObjekt): array
    {
        $out = array_fill_keys(self::ARTEN, []);
        if ($this->zaehle($bleibt) === 0) {
            return $out;
        }
        $familie = [];
        foreach (self::ARTEN as $t) {
            $familie[$t] = $bleibt[$t] === [] ? [] : DB::table(self::TABELLE[$t])->whereIn('id', $bleibt[$t])->pluck('fassung_von_id', 'id')->map(fn ($v) => (int) $v)->all();
        }
        $abos = DB::table('foodalchemist_abos AS a')->join('foodalchemist_sammlungen AS s', 's.id', '=', 'a.sammlung_id')
            ->where('a.mandant_team_id', $mandant->id)->where('a.status', FoodAlchemistAbo::AKTIV)
            ->when($ohneAboId !== null, fn ($q) => $q->where('a.id', '!=', $ohneAboId))->get(['a.id', 's.name']);
        foreach ($abos as $abo) {
            $h = $this->abos->huelle((int) $mandant->id, $ohneAboId, $ohneSammlungsObjekt, (int) $abo->id);
            foreach (self::ARTEN as $t) {
                if ($bleibt[$t] === [] || ($h[$t] ?? []) === []) {
                    continue;
                }
                $famInAbo = DB::table(self::TABELLE[$t])->whereIn('id', $h[$t])->pluck('fassung_von_id')->map(fn ($v) => (int) $v)->flip();
                foreach ($bleibt[$t] as $id) {
                    if ($famInAbo->has($familie[$t][$id] ?? -1)) {
                        $out[$t][$id][] = (string) $abo->name;
                    }
                }
            }
        }

        return array_map(fn ($je) => array_map(fn ($namen) => array_values(array_unique($namen)), $je), $out);
    }

    /**
     * Eine reine ID-Liste (Bestandsaufrufer, Rezepte) oder eine Zuordnung Art → IDs auf die volle Form bringen.
     *
     * @return array<string, list<int>>
     */
    private function jeArt(array $x): array
    {
        $out = array_fill_keys(self::ARTEN, []);
        if ($x !== [] && array_is_list($x)) {
            $out['recipe'] = array_values(array_map('intval', $x));

            return $out;
        }
        foreach (self::ARTEN as $t) {
            $out[$t] = array_values(array_map('intval', $x[$t] ?? []));
        }

        return $out;
    }

    /**
     * Ältere (nicht mehr in der Hülle stehende) Fassungen der Familien, deren Fassungen wegfallen.
     *
     * @param  list<int>  $wegfallend
     * @return list<int>
     */
    private function aeltereFassungen(string $typ, array $wegfallend): array
    {
        if ($wegfallend === []) {
            return [];
        }
        $tab = self::TABELLE[$typ];
        $familien = DB::table($tab)->whereIn('id', $wegfallend)->whereNotNull('fassung_von_id')->pluck('fassung_von_id');

        return DB::table($tab)->where('ist_fassung', true)->whereIn('fassung_von_id', $familien)->whereNull('deleted_at')
            ->whereNotIn('id', $wegfallend)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Name je Fassung; trägt eine Familie mehr als eine der Fassungen, bekommt jede den Zusatz „(Fassung n)“, damit die Kopien beim Mandanten
     * unterscheidbar sind.
     *
     * @param  array<string, list<int>>  $jeArt
     * @return array<string, array<int,string>>  Art → ID → Name
     */
    private function anzeigeNamen(array $jeArt): array
    {
        $out = [];
        foreach (self::ARTEN as $t) {
            $ids = $jeArt[$t] ?? [];
            $zeilen = $ids === [] ? collect() : DB::table(self::TABELLE[$t])->whereIn('id', array_unique($ids))->get(['id', 'name', 'fassung_von_id', 'fassung_nr']);
            $jeFamilie = $zeilen->countBy(fn ($r) => (int) ($r->fassung_von_id ?? -$r->id));
            $out[$t] = $zeilen->mapWithKeys(fn ($r) => [(int) $r->id => (string) $r->name
                .($jeFamilie[(int) ($r->fassung_von_id ?? -$r->id)] > 1 && $r->fassung_nr !== null ? ' (Fassung '.$r->fassung_nr.')' : '')])->all();
        }

        return $out;
    }

    /**
     * „Genutzt“ je Art (D4: Entwürfe zählen; Historie behält die Fassung). Rezepte: wie bisher (Ausgaben, Konzept-Slots, Zutat in eigenem Rezept) plus
     * Paket-Gerichte eigener Pakete. Pakete und Konzepte: das Verweis-Register (dieselben Filter wie das Folgen, PR 5).
     *
     * @param  list<int>  $ids  Fassungs-IDs
     * @param  list<int>  $teams  Mandant + Unter-Teams
     * @return array<int, list<string>>  Fassungs-ID => Fundstellen
     */
    private function nutzung(string $typ, array $ids, array $teams): array
    {
        $treffer = [];
        $merke = function (iterable $rows, string $wo) use (&$treffer) {
            foreach ($rows as $r) {
                $treffer[(int) $r->rid][] = $wo;
            }
        };
        if ($typ !== 'recipe') {
            foreach (VerweisRegister::quellenFuer($typ) as $quelle) {
                $r = VerweisRegister::REFERENZEN[$quelle];
                $merke(VerweisRegister::basis($quelle)->whereIn('x.'.$r['spalte'], $ids)->whereIn('x.team_id', $teams)
                    ->select('x.'.$r['spalte'].' AS rid')->distinct()->get(), mb_strtolower($r['wo']));
            }

            return array_map(fn ($w) => array_values(array_unique($w)), $treffer);
        }
        foreach (self::AUSGABEN as $art => [$tab, $kap, $kapFk, $parentFk, $parent]) {
            $q = DB::table($tab)->join($kap, 'k.id', '=', $kapFk)->join($parent, 'p.id', '=', $parentFk)
                ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)
                ->whereNull('x.deleted_at')->whereNull('p.deleted_at');
            $art === 'angebot'
                ? $q->whereNotIn('p.status', ['angenommen', 'abgelehnt'])        // D1: historische Angebote behalten die Fassung
                : $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'));
            $merke($q->select('x.sales_recipe_id AS rid')->distinct()->get(), $art);
        }
        $merke(DB::table('foodalchemist_menu_plan_entries AS x')->join('foodalchemist_menu_plans AS p', 'p.id', '=', 'x.menu_plan_id')
            ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at')
            ->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'))
            ->select('x.sales_recipe_id AS rid')->distinct()->get(), 'speiseplan');
        $merke(DB::table('foodalchemist_concept_slots AS x')->join('foodalchemist_concepts AS p', 'p.id', '=', 'x.concept_id')
            ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at')->where('p.ist_fassung', false)
            ->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'))
            ->select('x.sales_recipe_id AS rid')->distinct()->get(), 'konzept');
        $merke(DB::table('foodalchemist_package_dishes AS x')->join('foodalchemist_packages AS p', 'p.id', '=', 'x.package_id')
            ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at')->where('p.ist_fassung', false)
            ->select('x.sales_recipe_id AS rid')->distinct()->get(), 'paket');
        // als Zutat in einem EIGENEN Rezept (Arbeitszeile) des Mandanten bzw. seiner Unter-Teams
        $merke(DB::table('foodalchemist_recipe_ingredients AS z')->join('foodalchemist_recipes AS r', 'r.id', '=', 'z.recipe_id')
            ->whereIn('z.referenced_recipe_id', $ids)->whereIn('r.team_id', $teams)->where('r.ist_fassung', false)
            ->whereNull('z.deleted_at')->whereNull('r.deleted_at')
            ->select('z.referenced_recipe_id AS rid')->distinct()->get(), 'zutat');

        return array_map(fn ($w) => array_values(array_unique($w)), $treffer);
    }

    /**
     * Tiefe Kopie der genutzten Fassungen samt Unter-Fassungen (jede genau einmal, je Art) und Umhängen der lebenden Verweise.
     *
     * @param  array<string, list<int>>  $wurzeln
     * @param  array<string, list<int>>  $bleibt  Kettenglieder, die über ein anderes Abo sichtbar bleiben (nicht kopieren)
     * @return array{0: array<string, array<int,int>>, 1: int}  [Art → (Fassungs-ID => Kopie-ID), Zahl umgehängter Verweise]
     */
    private function kopierenUndUmhaengen(Team $mandant, array $wurzeln, array $bleibt = []): array
    {
        $wurzeln = $this->jeArt($wurzeln);
        $bleibt = $this->jeArt($bleibt);
        $map = array_fill_keys(self::ARTEN, []);
        if ($this->zaehle($wurzeln) === 0) {
            return [$map, 0];
        }
        $kette = $this->kette($wurzeln);
        foreach (self::ARTEN as $t) {
            $kette[$t] = array_values(array_diff($kette[$t], $bleibt[$t]));   // PR 6b: was über ein anderes Abo sichtbar bleibt, wird nicht kopiert — Verweise bleiben auf der Fassung
        }
        $namen = $this->anzeigeNamen($kette);

        // 1. erst alle Köpfe (Rezepte, Pakete, Konzepte), dann die Inhalte — Verweise zeigen auf die Kopien
        foreach ($kette['recipe'] as $fid) {
            $map['recipe'][$fid] = $this->kopiereKopf(FoodAlchemistRecipe::mitFassungen()->findOrFail($fid), $mandant, $namen['recipe'][$fid] ?? null);
        }
        foreach ($kette['paket'] as $fid) {
            $map['paket'][$fid] = $this->kopiereKopfPaket(FoodAlchemistPaket::mitFassungen()->findOrFail($fid), $mandant, $namen['paket'][$fid] ?? null);
        }
        foreach ($kette['concept'] as $fid) {
            $map['concept'][$fid] = $this->kopiereKopfKonzept(FoodAlchemistConcept::mitFassungen()->findOrFail($fid), $mandant, $namen['concept'][$fid] ?? null);
        }
        // 2. Inhalte von unten nach oben: Rezepte (samt Darreichungen) → Pakete → Konzepte
        $darMap = [];
        foreach ($kette['recipe'] as $fid) {
            $this->kopiereInhalt(FoodAlchemistRecipe::mitFassungen()->findOrFail($fid), $map['recipe'][$fid], $map['recipe'], $mandant, $darMap);
        }
        $this->kopiereErsatz($kette['recipe'], $map['recipe'], $mandant, $bleibt['recipe']);
        foreach ($kette['paket'] as $fid) {
            $this->kopiereInhaltPaket(FoodAlchemistPaket::mitFassungen()->findOrFail($fid), $map['paket'][$fid], $map, $darMap, $mandant);
        }
        foreach ($kette['concept'] as $fid) {
            $this->kopiereInhaltKonzept(FoodAlchemistConcept::mitFassungen()->findOrFail($fid), $map['concept'][$fid], $map, $darMap, $mandant);
        }

        // 3. lebende Verweise umhängen (Historie bleibt), je Art mit denselben Filtern wie „genutzt“
        $teams = RecomputeTeamRecipesJob::teamUndNachfahren((int) $mandant->id);
        $umgehaengt = 0;
        foreach (self::ARTEN as $t) {
            foreach ($map[$t] as $alt => $neu) {
                $umgehaengt += $this->haengeUm($t, $alt, $neu, $teams);
            }
        }

        return [$map, $umgehaengt];
    }

    /**
     * Ganze benötigte Kette: die Wurzeln und alle Unter-FASSUNGEN je Art (Nicht-Fassungen = global/Stamm bleiben Verweis). Konzept → Paket,
     * eingebettetes Paket-Konzept, Gericht; Paket → Gericht; Rezept → Unter-Rezept.
     *
     * @param  array<string, list<int>>  $wurzeln
     * @return array<string, list<int>>
     */
    private function kette(array $wurzeln): array
    {
        $kette = array_fill_keys(self::ARTEN, []);
        $offen = $this->jeArt($wurzeln);
        while ($this->zaehle($offen) > 0) {
            $neu = [];
            foreach (self::ARTEN as $t) {
                $neu[$t] = array_values(array_diff(array_unique($offen[$t]), $kette[$t]));
                $kette[$t] = array_merge($kette[$t], $neu[$t]);
            }
            $offen = array_fill_keys(self::ARTEN, []);
            if ($neu['concept'] !== []) {
                $slots = DB::table('foodalchemist_concept_slots')->whereIn('concept_id', $neu['concept'])->whereNull('deleted_at')->get(['package_id', 'embedded_concept_id', 'sales_recipe_id']);
                $offen['paket'] = array_merge($offen['paket'], $this->nurFassungen('paket', $slots->pluck('package_id')->filter()->all()));
                $offen['concept'] = array_merge($offen['concept'], $this->nurFassungen('concept', $slots->pluck('embedded_concept_id')->filter()->all()));
                $offen['recipe'] = array_merge($offen['recipe'], $this->nurFassungen('recipe', $slots->pluck('sales_recipe_id')->filter()->all()));
            }
            if ($neu['paket'] !== []) {
                $offen['recipe'] = array_merge($offen['recipe'], $this->nurFassungen('recipe', DB::table('foodalchemist_package_dishes')
                    ->whereIn('package_id', $neu['paket'])->whereNull('deleted_at')->pluck('sales_recipe_id')->all()));
            }
            if ($neu['recipe'] !== []) {
                $offen['recipe'] = array_merge($offen['recipe'], DB::table('foodalchemist_recipe_ingredients AS z')
                    ->join('foodalchemist_recipes AS r', 'r.id', '=', 'z.referenced_recipe_id')
                    ->whereIn('z.recipe_id', $neu['recipe'])->whereNull('z.deleted_at')->where('r.ist_fassung', true)
                    ->pluck('z.referenced_recipe_id')->map(fn ($v) => (int) $v)->all());
            }
        }

        return $kette;
    }

    /** @param list<int|string> $ids @return list<int> */
    private function nurFassungen(string $typ, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table(self::TABELLE[$typ])->whereIn('id', array_unique($ids))->where('ist_fassung', true)->whereNull('deleted_at')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function kopiereKopf(FoodAlchemistRecipe $fassung, Team $mandant, ?string $name = null): int
    {
        $kopie = $fassung->replicate(['uuid', 'legacy_id', 'recipe_key', 'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash',
            'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert', 'kopie_von_id', 'kopie_stand_at']);
        $kopie->forceFill([
            'team_id' => $mandant->id, 'ist_fassung' => false, 'recipe_key' => $this->freierKey($mandant, (string) $fassung->recipe_key),
            'kopie_von_id' => $fassung->fassung_von_id, 'kopie_stand_at' => now(),   // originalGeaendert() vergleicht gegen die Familie
        ] + ($name !== null ? ['name' => $name] : []))->save();

        return (int) $kopie->id;
    }

    /**
     * @param  array<int,int>  $map  Rezept-Fassung → Rezept-Kopie
     * @param  array<int,int>  $darMap  Darreichung der Fassung → Darreichung der Kopie (wird gefüllt; Pakete/Konzepte hängen ihre Form daran)
     */
    private function kopiereInhalt(FoodAlchemistRecipe $fassung, int $kopieId, array $map, Team $mandant, array &$darMap): void
    {
        $zutatMap = [];
        foreach ($fassung->ingredients()->get() as $z) {
            $neu = $z->replicate(['uuid', 'legacy_id']);
            $neu->forceFill(['recipe_id' => $kopieId, 'team_id' => $mandant->id,
                'referenced_recipe_id' => $z->referenced_recipe_id !== null ? ($map[(int) $z->referenced_recipe_id] ?? $z->referenced_recipe_id) : null])->save();
            $zutatMap[(int) $z->id] = (int) $neu->id;
        }
        foreach (FoodAlchemistRecipeStep::where('recipe_id', $fassung->id)->get() as $s) {
            $s->replicate(['uuid'])->forceFill(['recipe_id' => $kopieId, 'team_id' => $mandant->id])->save();
        }
        foreach (DB::table('foodalchemist_recipe_equipment')->where('recipe_id', $fassung->id)->get() as $e) {
            $zeile = (array) $e;
            unset($zeile['id']);
            DB::table('foodalchemist_recipe_equipment')->insert([...$zeile, 'recipe_id' => $kopieId]);
        }
        foreach (FoodAlchemistRecipeDarreichung::where('recipe_id', $fassung->id)->get() as $d) {
            $dk = $d->replicate(['uuid', 'legacy_id']);
            $dk->forceFill(['recipe_id' => $kopieId, 'team_id' => $mandant->id])->save();
            $darMap[(int) $d->id] = (int) $dk->id;   // D9: auch die angehängten Formen gehen mit (samt Deltas auf umgemappten Zutaten)
            foreach ($d->deltas()->get() as $delta) {
                if (isset($zutatMap[(int) $delta->recipe_ingredient_id])) {
                    $delta->replicate(['uuid', 'legacy_id'])->forceFill(['presentation_id' => $dk->id, 'team_id' => $mandant->id,
                        'recipe_ingredient_id' => $zutatMap[(int) $delta->recipe_ingredient_id]])->save();
                }
            }
        }
    }

    private function kopiereKopfPaket(FoodAlchemistPaket $fassung, Team $mandant, ?string $name = null): int
    {
        $kopie = $fassung->replicate(['uuid', 'legacy_id', 'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
            'aenderungsnotiz', 'fassung_allergen_geaendert', 'kopie_von_id', 'kopie_stand_at', 'offer_id']);
        $kopie->forceFill([
            'team_id' => $mandant->id, 'ist_fassung' => false, 'price_mode' => 'auto', 'price_stale' => false,
            'kopie_von_id' => $fassung->fassung_von_id, 'kopie_stand_at' => now(),   // originalGeaendert() vergleicht gegen die Familie
        ] + ($name !== null ? ['name' => $name] : []))->save();

        return (int) $kopie->id;
    }

    private function kopiereKopfKonzept(FoodAlchemistConcept $fassung, Team $mandant, ?string $name = null): int
    {
        $kopie = $fassung->replicate(['uuid', 'legacy_id', 'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
            'aenderungsnotiz', 'fassung_allergen_geaendert', 'kopie_von_id', 'kopie_stand_at', 'offer_id', 'template_source_id',
            'image_path', 'image_context_file_id']);   // das Bild liegt beim Master: unten in den Speicher des Mandanten kopiert
        $kopie->forceFill([
            'team_id' => $mandant->id, 'ist_fassung' => false, 'price_mode' => 'auto',
            'kopie_von_id' => $fassung->fassung_von_id, 'kopie_stand_at' => now(),
        ] + ($name !== null ? ['name' => $name] : []))->save();
        $this->kopiereKonzeptBilder($fassung, $kopie, $mandant);

        return (int) $kopie->id;
    }

    /**
     * Titelbild und Galerie in den Speicher des Mandanten mitkopieren (Entscheid Dominique 14.10.). Fehlt eine Datei, bleibt die Kopie ohne dieses Bild und es
     * gibt einen Hinweis — nie einen Abbruch der Kündigung.
     */
    private function kopiereKonzeptBilder(FoodAlchemistConcept $fassung, FoodAlchemistConcept $kopie, Team $mandant): void
    {
        $media = app(FoodAlchemistMediaService::class);
        if ($fassung->image_context_file_id !== null || trim((string) $fassung->image_path) !== '') {
            $m = $media->kopiereBild($fassung->image_context_file_id, $fassung->image_path, $mandant, 'foodalchemist.concept', (int) $kopie->id, "foodalchemist/concept/{$kopie->id}");
            if ($m !== null) {
                $kopie->forceFill(['image_context_file_id' => $m['context_file_id'], 'image_path' => $m['path']])->save();
            } else {
                $this->hinweise[] = 'Konzept „'.$kopie->name.'“: Die Bilddatei fehlt — die Kopie hat kein Titelbild.';
            }
        }
        foreach ($fassung->images()->get() as $bild) {
            $m = $media->kopiereBild($bild->context_file_id, $bild->path, $mandant, 'foodalchemist.concept', (int) $kopie->id, "foodalchemist/concept/{$kopie->id}/gallery");
            if ($m === null) {
                $this->hinweise[] = 'Konzept „'.$kopie->name.'“: Eine Galerie-Bilddatei fehlt — die Kopie hat dieses Bild nicht.';

                continue;
            }
            $bild->replicate(['uuid'])->forceFill(['concept_id' => $kopie->id, 'team_id' => $mandant->id, 'context_file_id' => $m['context_file_id'], 'path' => $m['path']])->save();
        }
    }

    /**
     * Paket-Gerichte der Fassung → Zeilen der Kopie: Rezept = Rezept-Kopie, Form = Form der Rezept-Kopie.
     *
     * @param  array<string, array<int,int>>  $map
     * @param  array<int,int>  $darMap
     */
    private function kopiereInhaltPaket(FoodAlchemistPaket $fassung, int $kopieId, array $map, array $darMap, Team $mandant): void
    {
        foreach ($fassung->dishes()->get() as $g) {
            $g->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at'])->forceFill([
                'package_id' => $kopieId, 'team_id' => $mandant->id,
                'sales_recipe_id' => $map['recipe'][(int) $g->sales_recipe_id] ?? $g->sales_recipe_id,
                'presentation_id' => $g->presentation_id !== null ? ($darMap[(int) $g->presentation_id] ?? $g->presentation_id) : null,
            ])->save();
        }
    }

    /**
     * Slots der Konzept-Fassung → Slots der Kopie (Paket, eingebettetes Paket-Konzept, Rezept, Form auf Kopien umgehängt), dazu Facetten und Sektor-Eignung.
     *
     * @param  array<string, array<int,int>>  $map
     * @param  array<int,int>  $darMap
     */
    private function kopiereInhaltKonzept(FoodAlchemistConcept $fassung, int $kopieId, array $map, array $darMap, Team $mandant): void
    {
        foreach ($fassung->slots()->get() as $s) {
            $s->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at'])->forceFill([
                'concept_id' => $kopieId, 'team_id' => $mandant->id,
                'package_id' => $s->package_id !== null ? ($map['paket'][(int) $s->package_id] ?? $s->package_id) : null,
                'embedded_concept_id' => $s->embedded_concept_id !== null ? ($map['concept'][(int) $s->embedded_concept_id] ?? $s->embedded_concept_id) : null,
                'sales_recipe_id' => $s->sales_recipe_id !== null ? ($map['recipe'][(int) $s->sales_recipe_id] ?? $s->sales_recipe_id) : null,
                'presentation_id' => $s->presentation_id !== null ? ($darMap[(int) $s->presentation_id] ?? $s->presentation_id) : null,
            ])->save();
        }
        $kopie = FoodAlchemistConcept::mitFassungen()->findOrFail($kopieId);
        $kopie->serviceMoments()->sync($fassung->serviceMoments()->allRelatedIds()->all());
        $kopie->seasons()->sync($fassung->seasons()->allRelatedIds()->all());
        $kopie->targetGroups()->sync($fassung->targetGroups()->allRelatedIds()->all());
        foreach ($fassung->sectorSuitabilities()->get() as $e) {
            $e->replicate(['uuid'])->forceFill(['concept_id' => $kopieId, 'team_id' => $mandant->id])->save();
        }
    }

    /**
     * Ersatz-Beziehungen der Kette kommen mit (§12 D2): Kanten des Masters zwischen Rezepten der Kette (über Fassung ODER
     * Familie adressiert) bzw. von einem Kettenrezept zu einem GP. Kanten zu fremden, nicht kopierten Master-Rezepten nicht.
     *
     * PR 6b (Lisa): Glieder, die über ein anderes Abo sichtbar bleiben (nicht kopiert), bilden die Kante auf SICH SELBST ab (Fassung) — sonst gingen Ersatz-Beziehungen
     * der Kopie zu weiter verknüpften Fassungen verloren.
     *
     * @param  list<int>  $kette  kopierte Kettenglieder
     * @param  array<int,int>  $map
     * @param  list<int>  $bleibt  nicht kopierte, weiter sichtbare Rezept-Fassungen
     */
    private function kopiereErsatz(array $kette, array $map, Team $mandant, array $bleibt = []): void
    {
        $ziel = $map;
        foreach (DB::table('foodalchemist_recipes')->whereIn('id', $kette)->pluck('fassung_von_id', 'id') as $fid => $familie) {
            if ($familie !== null) {
                $ziel[(int) $familie] = $map[(int) $fid];
            }
        }
        foreach (DB::table('foodalchemist_recipes')->whereIn('id', $bleibt)->pluck('fassung_von_id', 'id') as $fid => $familie) {
            $ziel[(int) $fid] = (int) $fid;
            if ($familie !== null && ! isset($ziel[(int) $familie])) {
                $ziel[(int) $familie] = (int) $fid;
            }
        }
        $ids = array_keys($ziel);
        $kanten = FoodAlchemistComponentEquivalent::query()
            ->where(fn ($w) => $w->where(fn ($q) => $q->where('source_kind', 'recipe')->whereIn('source_id', $ids))
                ->orWhere(fn ($q) => $q->where('alt_kind', 'recipe')->whereIn('alt_id', $ids)))
            ->get();
        foreach ($kanten as $k) {
            $quelle = $k->source_kind === 'recipe' ? ($ziel[(int) $k->source_id] ?? null) : (int) $k->source_id;
            $alt = $k->alt_kind === 'recipe' ? ($ziel[(int) $k->alt_id] ?? null) : (int) $k->alt_id;
            if ($quelle === null || $alt === null) {
                continue;
            }
            FoodAlchemistComponentEquivalent::firstOrCreate(
                ['team_id' => $mandant->id, 'source_kind' => $k->source_kind, 'source_id' => $quelle, 'alt_kind' => $k->alt_kind, 'alt_id' => $alt],
                ['umrechnungsfaktor' => $k->umrechnungsfaktor, 'standard_seite' => $k->standard_seite, 'match_confidence' => $k->match_confidence, 'notes' => $k->notes],
            );
        }
    }

    /**
     * Lebende Verweise von der Fassung auf die Kopie, mit denselben Filtern wie „genutzt“. Historie bleibt, Fassungs-Zeilen werden nie angefasst.
     *
     * @param  list<int>  $teams
     */
    private function haengeUm(string $typ, int $alt, int $neu, array $teams): int
    {
        if ($typ !== 'recipe') {
            $n = 0;
            foreach (VerweisRegister::quellenFuer($typ) as $quelle) {
                $r = VerweisRegister::REFERENZEN[$quelle];
                $ids = VerweisRegister::basis($quelle)->where('x.'.$r['spalte'], $alt)->whereIn('x.team_id', $teams)->pluck('x.id')->all();
                $n += DB::table($r['tab'])->whereIn('id', $ids ?: [0])->update([$r['spalte'] => $neu]);
            }

            return $n;
        }
        $n = 0;
        $treffer = $this->nutzung('recipe', [$alt], $teams);
        if (! isset($treffer[$alt])) {
            return 0;
        }
        foreach (self::AUSGABEN as $art => [$tab, $kap, $kapFk, $parentFk, $parent]) {
            $q = DB::table($tab)->join($kap, 'k.id', '=', $kapFk)->join($parent, 'p.id', '=', $parentFk)
                ->where('x.sales_recipe_id', $alt)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at');
            $art === 'angebot'
                ? $q->whereNotIn('p.status', ['angenommen', 'abgelehnt'])
                : $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'));
            $ids = $q->pluck('x.id')->all();
            $n += DB::table(explode(' AS ', $tab)[0])->whereIn('id', $ids ?: [0])->update(['sales_recipe_id' => $neu]);
        }
        foreach (['foodalchemist_menu_plan_entries' => ['foodalchemist_menu_plans', 'menu_plan_id', false], 'foodalchemist_concept_slots' => ['foodalchemist_concepts', 'concept_id', true],
            'foodalchemist_package_dishes' => ['foodalchemist_packages', 'package_id', true]] as $tab => [$parent, $fk, $eigene]) {
            $q = DB::table("{$tab} AS x")->join("{$parent} AS p", 'p.id', '=', "x.{$fk}")
                ->where('x.sales_recipe_id', $alt)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at');
            if ($eigene) {
                $q->where('p.ist_fassung', false);   // Pflichtpunkt #367: nie eine Fassung als Eltern-Objekt
            }
            if ($tab !== 'foodalchemist_package_dishes') {
                $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'));
            }
            $n += DB::table($tab)->whereIn('id', $q->pluck('x.id')->all() ?: [0])->update(['sales_recipe_id' => $neu]);
        }
        $n += DB::table('foodalchemist_recipe_ingredients AS z')
            ->whereIn('z.recipe_id', DB::table('foodalchemist_recipes')->whereIn('team_id', $teams)->where('ist_fassung', false)->whereNull('deleted_at')->select('id'))
            ->where('z.referenced_recipe_id', $alt)->whereNull('z.deleted_at')->update(['referenced_recipe_id' => $neu]);

        return $n;
    }

    /**
     * Kopien mit den Preisen des Mandanten neu rechnen: Rezepte (Kinder vor Eltern, Darreichungen danach) → Paket-Preise → Konzept-Caches (eingebettete
     * Paket-Konzepte vor den Konzepten). Ohne vollständigen EK „kein Preis“ (nie 0 €), das regeln die Preis-Dienste.
     *
     * @param  array<string, array<int,int>>  $map
     */
    private function nachrechnen(array $map): void
    {
        if ($this->zaehle($map) === 0) {
            return;
        }
        $rezepte = array_values($map['recipe'] ?? []);
        if ($rezepte !== []) {
            app(RecipeRecomputeService::class)->recomputeMany($rezepte);
            foreach (FoodAlchemistRecipeDarreichung::whereIn('recipe_id', $rezepte)->get() as $d) {
                app(DarreichungService::class)->recomputePreise($d);
            }
        }
        foreach (array_values($map['paket'] ?? []) as $id) {
            if (($p = FoodAlchemistPaket::find($id)) !== null) {
                app(PaketService::class)->recomputePrice($p);
            }
        }
        $konzepte = FoodAlchemistConcept::mitFassungen()->whereIn('id', array_values($map['concept'] ?? []))->get()->sortBy(fn ($c) => $c->kind === 'paket' ? 0 : 1);
        foreach ($konzepte as $c) {
            app(ConceptService::class)->recomputeCache($c);
        }
    }

    private function freierKey(Team $mandant, string $fassungKey): string
    {
        $basis = preg_replace('/@f\d+$/', '', $fassungKey) ?: 'rezept';
        $key = $basis;
        for ($n = 2; FoodAlchemistRecipe::mitFassungen()->withTrashed()->where('team_id', $mandant->id)->where('recipe_key', $key)->exists(); $n++) {
            $key = "{$basis}_{$n}";
        }

        return $key;
    }

    private function pruefeRecht(Team $handelnd, FoodAlchemistAbo $abo, ?User $actor): void
    {
        if ((int) $handelnd->id === (int) $abo->master_team_id) {
            $this->rechte->pruefe($actor, $handelnd, FaRolle::Freigeben, 'Abo kündigen');

            return;
        }
        if ((int) $this->rechte->kundenHauptTeam($handelnd)->id === (int) $abo->mandant_team_id && (int) $handelnd->id === (int) $abo->mandant_team_id) {
            $this->rechte->pruefe($actor, $handelnd, FaRolle::Admin, 'Abo kündigen');

            return;
        }
        throw new \RuntimeException('Kündigen darf nur das Master-Team oder das Oberteam des Mandanten.');
    }
}
