<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use RuntimeException;

/**
 * Spec 19 E5.1 — Leitstelle: die abgeleitete 7-Schritt-Checkliste + der Kapitel-Stand
 * + der heterogene Speisen-Baum. Alles READ-ONLY und aus dem Bestand abgeleitet
 * (nichts persistiert — die 7 User-Phasen sind KEINE PHASEN, s. PhaseService).
 *
 * - `checkliste(Team,$fb)`: 7 Arbeits-Schritte (Bedarf→Preise) mit Status
 *   (offen|teil|erledigt) + Sprungziel (Tab + Anker) für den Alpine-Event-Bus (E5.2).
 *   „Versand"/Foodbook-Freigabe ist NIE Teil der Checkliste (UX 1) — das Go-Gate ist
 *   kapitelweise (Entscheidung 2), die Foodbook-Freigabe bleibt der PhaseService.
 * - `kapitelStand(Kapitel)`: aufgelöste Kapitel-Sicht für die Kapitel-Planung-Rail
 *   (Ziele + Zielgruppen + Aggregat + WE-Ampel + Inhalts-/Ideen-Zähler + Anlage-Stand).
 * - `speisenBaum($fb)`: Kapitel → [Paket→Gerichte | Gericht direkt | Ideen] mit Badges.
 *
 * Die dish_ideas-Tabelle (M4/E6.1) existiert bei E5.1 NOCH NICHT — alle Ideen-Zähler
 * sind schema-guarded (Schema::hasTable) und liefern 0, bis E6 sie anlegt.
 */
class LeitstelleService
{
    public function __construct(private FoodbookService $foodbooks) {}

    private const DISH_IDEAS_TABLE = 'foodalchemist_dish_ideas';

    /** Inhalts-Blocktypen, die ein Kapitel „befüllt" machen (Paket + Einzelgericht). */
    private const INHALT_TYPEN = ['concept_ref', 'recipe_ref'];

    /**
     * 7-Schritt-Checkliste, aus dem Foodbook-Bestand abgeleitet. Jeder Schritt:
     * {key, nr, label, status, tab, anker, hinweis?}. Status ist heuristisch (die
     * Phasen sind soft/springbar) — er signalisiert Fortschritt, blockt nichts.
     *
     * @return list<array{key:string, nr:int, label:string, status:string, tab:string, anker:string, hinweis:?string}>
     */
    public function checkliste(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $fb = $this->ladeFoodbook($team, $fb);
        $kapitel = $fb->chapters;
        $kapitelAnzahl = $kapitel->count();

        // ── Signale ──────────────────────────────────────────────────────────
        $hatPersonen = $fb->personen !== null && (int) $fb->personen > 0;
        $defaults = 0;
        $defaults += $fb->targetGroups->count() > 0 ? 1 : 0;
        $defaults += $this->gesetzt($fb->default_event_type_id) ? 1 : 0;
        $defaults += $this->gesetzt($fb->default_serving_form_id) ? 1 : 0;
        $defaults += $this->gesetzt($fb->default_niveau) ? 1 : 0;
        $defaults += $this->gesetzt($fb->target_food_cost_pct) ? 1 : 0;

        $maxTiefe = $this->maxTiefe($kapitel);
        $mitZielen = $kapitel->filter(fn ($k) => $this->kapitelHatZiele($k))->count();

        $ideenGesamt = $this->ideenAnzahlFuerFoodbook($kapitel->pluck('id')->all());

        $mitInhalt = 0;
        $bepreist = 0;
        foreach ($kapitel as $k) {
            $inhalt = $this->inhaltsBloecke($k)->count();
            $hatInhalt = $inhalt > 0 || $k->released_at !== null;
            if ($hatInhalt) {
                $mitInhalt++;
                $agg = $this->foodbooks->kapitelAggregat($team, $k);
                if ($agg['vk_pro_person'] > 0 || $agg['pauschal'] > 0) {
                    $bepreist++;
                }
            }
        }

        // ── Schritte ─────────────────────────────────────────────────────────
        $steps = [];

        // 1 Bedarf → briefing/bedarf
        $steps[] = $this->step('bedarf', 1, 'Bedarf', 'briefing', 'bedarf',
            $this->stufe($hatPersonen && $defaults > 0, $hatPersonen || $defaults > 0),
            (! $hatPersonen && $defaults === 0) ? 'Gästezahl + mind. eine Default-Dimension (Zielgruppe/Eventtyp/Servierform/Niveau/WE-Ziel) fehlen.' : null);

        // 2 Struktur → planung/kapitel (binär)
        $steps[] = $this->step('struktur', 2, 'Struktur', 'planung', 'kapitel',
            $kapitelAnzahl > 0 ? 'erledigt' : 'offen',
            $kapitelAnzahl === 0 ? 'Noch keine Kapitel — Gerüst anwenden oder Kapitel anlegen.' : null);

        // 3 Tiefe → planung/kapitel (flach = teil, n-tief = erledigt; Unterkapitel optional)
        $tiefeStatus = $kapitelAnzahl === 0 ? 'offen' : ($maxTiefe >= 2 ? 'erledigt' : 'teil');
        $steps[] = $this->step('tiefe', 3, 'Tiefe', 'planung', 'kapitel', $tiefeStatus,
            $tiefeStatus === 'teil' ? 'Kapitel bislang flach — Unterkapitel sind optional.' : null);

        // 4 Kapitel-Aufbau (Ziele/Dimensionen je Kapitel) → planung/kapitel
        $steps[] = $this->step('kapitel_aufbau', 4, 'Kapitel-Aufbau', 'planung', 'kapitel',
            $this->anteilStufe($mitZielen, $kapitelAnzahl),
            $kapitelAnzahl > 0 && $mitZielen < $kapitelAnzahl ? ($kapitelAnzahl - $mitZielen) . ' Kapitel ohne Ziele/Dimensionen.' : null);

        // 5 Kreativ (Skizzen) → kreativ/ideen (optional; binär auf Idee-Vorhandensein)
        $steps[] = $this->step('kreativ', 5, 'Kreativ', 'kreativ', 'ideen',
            $ideenGesamt > 0 ? 'erledigt' : 'offen',
            $ideenGesamt === 0 ? 'Skizzen-Ebene optional — freie Ideen oder Bestands-Vorschläge sammeln.' : null);

        // 6 Anlegen (Kapitel-Go → Konzepte/Blöcke) → planung/anlegen
        $steps[] = $this->step('anlegen', 6, 'Anlegen', 'planung', 'anlegen',
            $this->anteilStufe($mitInhalt, $kapitelAnzahl),
            $kapitelAnzahl > 0 && $mitInhalt < $kapitelAnzahl ? ($kapitelAnzahl - $mitInhalt) . ' Kapitel ohne Inhalt (Paket/Einzelgericht).' : null);

        // 7 Preise → preise/preise (Bezug: befüllte Kapitel)
        $steps[] = $this->step('preise', 7, 'Preise', 'preise', 'preise',
            $this->anteilStufe($bepreist, $mitInhalt),
            $mitInhalt > 0 && $bepreist < $mitInhalt ? ($mitInhalt - $bepreist) . ' befüllte Kapitel noch ohne Preis.' : null);

        return $steps;
    }

    /**
     * Aufgelöster Kapitel-Stand für die Kapitel-Planung-Rail (E5.3).
     *
     * @return array{
     *   kapitel_id:int, titel:string, parent_id:?int, depth:int,
     *   ziele:array, zielgruppen:list<array{id:int,name:string}>,
     *   aggregat:array, wareneinsatz:array,
     *   inhalt:array{pakete:int, einzel:int, ideen:int}, released:bool, released_at:?string
     * }
     */
    public function kapitelStand(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        // ownedKapitel (in kapitelZiele) erzwingt Team-Scope + Ownership.
        $ziele = $this->foodbooks->kapitelZiele($team, $kapitel);
        $fb = $kapitel->foodbook;
        $aggregat = $this->foodbooks->kapitelAggregat($team, $kapitel);
        $we = $this->foodbooks->wareneinsatzAmpel($team, $fb, $kapitel);

        $bloecke = $this->inhaltsBloecke($kapitel);
        $pakete = $bloecke->where('type', 'concept_ref')->count();
        $einzel = $bloecke->where('type', 'recipe_ref')->count();

        $kapitel->loadMissing('targetGroups:id,name');

        return [
            'kapitel_id' => (int) $kapitel->id,
            'titel' => (string) $kapitel->title,
            'parent_id' => $kapitel->parent_id !== null ? (int) $kapitel->parent_id : null,
            'depth' => $this->kapitelTiefe($kapitel),
            'ziele' => $ziele,
            'zielgruppen' => $kapitel->targetGroups->map(fn ($z) => ['id' => (int) $z->id, 'name' => (string) $z->name])->values()->all(),
            'aggregat' => $aggregat,
            'wareneinsatz' => $we,
            'inhalt' => [
                'pakete' => $pakete,
                'einzel' => $einzel,
                'ideen' => $this->ideenAnzahl((int) $kapitel->id),
            ],
            'released' => $kapitel->released_at !== null,
            'released_at' => $kapitel->released_at?->toIso8601String(),
        ];
    }

    /**
     * Heterogener Speisen-Baum: pro Kapitel dessen Pakete (concept_ref → Konzept-Slots),
     * Einzelgerichte (recipe_ref) und Ideen (Skizzen, Entwurf) — jeweils mit Typ-Badge
     * und Preis-Bezug (€/Gast beim Paket, €/Pos beim Einzel). Kundensicht-Filter ist NICHT
     * angewandt (Leitstelle = interne Planungssicht); Ideen sind Entwürfe.
     *
     * @return list<array{
     *   kapitel_id:int, titel:string, parent_id:?int, depth:int, released:bool,
     *   positionen:list<array{art:string, label:string, preis:?float, preis_einheit:?string, status:string, ref_id:?int, kinder?:list<array{label:string}>}>
     * }>
     */
    public function speisenBaum(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $fb = $this->ladeFoodbook($team, $fb, mitSlots: true);
        $baum = [];
        foreach ($fb->chapters as $k) {
            $positionen = [];
            foreach ($this->inhaltsBloecke($k) as $block) {
                if ($block->type === 'concept_ref') {
                    $concept = $block->concept;
                    $kinder = [];
                    if ($concept !== null) {
                        foreach ($concept->slots as $slot) {
                            $kinder[] = ['label' => $slot->dish?->name ?? ($slot->package?->name ?? $slot->label ?? 'Slot')];
                        }
                    }
                    $positionen[] = [
                        'art' => 'paket',
                        'label' => $concept?->name ?? ($block->label ?? 'Paket'),
                        'preis' => $concept?->price_per_person_cache !== null ? (float) $concept->price_per_person_cache : null,
                        'preis_einheit' => 'gast',
                        'status' => $k->released_at !== null ? 'angelegt' : 'entwurf',
                        'ref_id' => $concept !== null ? (int) $concept->id : null,
                        'kinder' => $kinder,
                    ];
                } else { // recipe_ref
                    $preis = $this->foodbooks->blockPreis($block);
                    $positionen[] = [
                        'art' => 'einzel',
                        'label' => $block->dish?->name ?? ($block->label ?? 'Gericht'),
                        'preis' => $preis['vk_pp'] > 0 ? $preis['vk_pp'] : ($preis['pauschal'] > 0 ? $preis['pauschal'] : null),
                        'preis_einheit' => 'position',
                        'status' => ($preis['vk_pp'] > 0 || $preis['pauschal'] > 0) ? 'bepreist' : ($k->released_at !== null ? 'angelegt' : 'entwurf'),
                        'ref_id' => $block->sales_recipe_id !== null ? (int) $block->sales_recipe_id : null,
                    ];
                }
            }
            foreach ($this->ideen((int) $k->id) as $idee) {
                $positionen[] = [
                    'art' => 'idee',
                    'label' => (string) ($idee->titel ?? 'Idee'),
                    'preis' => null,
                    'preis_einheit' => null,
                    'status' => $idee->generation_status === 'queued' ? 'ki_queue' : 'entwurf',
                    'ref_id' => (int) $idee->id,
                ];
            }

            $baum[] = [
                'kapitel_id' => (int) $k->id,
                'titel' => (string) $k->title,
                'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null,
                'depth' => $this->kapitelTiefe($k, $fb->chapters),
                'released' => $k->released_at !== null,
                'positionen' => $positionen,
            ];
        }

        return $baum;
    }

    /**
     * Kapitel-Matrix für die Fortschritt-/Kalkulation-Rail (E5.3): pro Kapitel ein
     * Planungs-Status (hat_ziele/positionen/hat_inhalt/bepreist/released) + die
     * Wareneinsatz-Ampel. Read-only; teilt die Ziele-/Inhalts-Heuristik mit `checkliste()`
     * (dieselben privaten Helfer) und dient E5.4 (`leitstelle.GET`) als Kapitel-Sektion.
     *
     * @return list<array{kapitel_id:int, titel:string, parent_id:?int, depth:int,
     *   hat_ziele:bool, positionen:int, hat_inhalt:bool, bepreist:bool, released:bool,
     *   wareneinsatz:array}>
     */
    public function kapitelMatrix(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $fb = $this->ladeFoodbook($team, $fb);
        $rows = [];
        foreach ($fb->chapters as $k) {
            $inhalt = $this->inhaltsBloecke($k);
            $positionen = $inhalt->count() + $this->ideenAnzahl((int) $k->id);
            $hatInhalt = $inhalt->count() > 0 || $k->released_at !== null;
            $bepreist = false;
            if ($hatInhalt) {
                $agg = $this->foodbooks->kapitelAggregat($team, $k);
                $bepreist = $agg['vk_pro_person'] > 0 || $agg['pauschal'] > 0;
            }
            $rows[] = [
                'kapitel_id' => (int) $k->id,
                'titel' => (string) $k->title,
                'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null,
                'depth' => $this->kapitelTiefe($k, $fb->chapters),
                'hat_ziele' => $this->kapitelHatZiele($k),
                'positionen' => $positionen,
                'hat_inhalt' => $hatInhalt,
                'bepreist' => $bepreist,
                'released' => $k->released_at !== null,
                'wareneinsatz' => $this->foodbooks->wareneinsatzAmpel($team, $fb, $k),
            ];
        }

        return $rows;
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private function ladeFoodbook(Team $team, FoodAlchemistFoodbook $fb, bool $mitSlots = false): FoodAlchemistFoodbook
    {
        $mit = [
            'chapters' => fn ($q) => $q->orderBy('position'),
            'chapters.blocks' => fn ($q) => $q->orderBy('position'),
            'chapters.blocks.dish:id,name,sales_net',
            'chapters.targetGroups:id',
            'targetGroups:id',
        ];
        if ($mitSlots) {
            $mit['chapters.blocks.concept'] = fn ($q) => $q->with(['slots' => fn ($s) => $s->orderBy('position'), 'slots.dish:id,name', 'slots.package:id,name']);
        } else {
            $mit['chapters.blocks.concept'] = fn ($q) => $q->select('id', 'name', 'price_per_person_cache');
        }

        $loaded = FoodAlchemistFoodbook::visibleToTeam($team)->with($mit)->find($fb->id);
        if ($loaded === null) {
            throw new RuntimeException('Foodbook nicht gefunden oder nicht sichtbar für dieses Team.');
        }

        return $loaded;
    }

    /** Sichtbare Inhalts-Blöcke (Paket + Einzelgericht) eines Kapitels. */
    private function inhaltsBloecke(FoodAlchemistFoodbookKapitel $kapitel)
    {
        return $kapitel->blocks
            ->where('visible', true)
            ->whereIn('type', self::INHALT_TYPEN);
    }

    /** Hat das Kapitel eigene Ziele/Dimensionen (M3) oder Zielgruppen gestempelt? */
    private function kapitelHatZiele(FoodAlchemistFoodbookKapitel $kapitel): bool
    {
        foreach (['target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
            'serving_form_id', 'service_moment_id', 'pricing_mode', 'target_food_cost_pct'] as $feld) {
            if ($this->gesetzt($kapitel->{$feld})) {
                return true;
            }
        }

        return $kapitel->relationLoaded('targetGroups') ? $kapitel->targetGroups->count() > 0 : $kapitel->targetGroups()->exists();
    }

    private function gesetzt($wert): bool
    {
        return $wert !== null && $wert !== '';
    }

    /** Stufe aus zwei Booleschen: voll → erledigt, teil → teil, sonst offen. */
    private function stufe(bool $voll, bool $teil): string
    {
        return $voll ? 'erledigt' : ($teil ? 'teil' : 'offen');
    }

    /** Anteil-Stufe: 0/0 → offen, alle → erledigt, sonst teil. */
    private function anteilStufe(int $erfuellt, int $gesamt): string
    {
        if ($gesamt === 0 || $erfuellt === 0) {
            return 'offen';
        }

        return $erfuellt >= $gesamt ? 'erledigt' : 'teil';
    }

    private function step(string $key, int $nr, string $label, string $tab, string $anker, string $status, ?string $hinweis): array
    {
        return ['key' => $key, 'nr' => $nr, 'label' => $label, 'status' => $status, 'tab' => $tab, 'anker' => $anker, 'hinweis' => $hinweis];
    }

    /** Maximale Tiefe (Top = 1) über die flach geladene Kapitel-Collection. */
    private function maxTiefe($kapitel): int
    {
        $max = 0;
        foreach ($kapitel as $k) {
            $max = max($max, $this->kapitelTiefe($k, $kapitel));
        }

        return $max;
    }

    /** Tiefe eines Kapitels (Top = 1) via parent_id-Kette; nutzt geladene Collection wenn da. */
    private function kapitelTiefe(FoodAlchemistFoodbookKapitel $kapitel, $collection = null): int
    {
        $byId = $collection?->keyBy('id');
        $tiefe = 1;
        $node = $kapitel;
        $besucht = [];
        while ($node !== null && $node->parent_id !== null && ! isset($besucht[(int) $node->id])) {
            $besucht[(int) $node->id] = true;
            $tiefe++;
            $node = $byId?->get($node->parent_id) ?? $node->parent;
        }

        return $tiefe;
    }

    // ── Ideen (dish_ideas, M4/E6.1 — schema-guarded bis E6) ───────────────────

    private function ideenTabelleDa(): bool
    {
        return Schema::hasTable(self::DISH_IDEAS_TABLE);
    }

    private function ideenAnzahl(int $chapterId): int
    {
        if (! $this->ideenTabelleDa()) {
            return 0;
        }

        return (int) DB::table(self::DISH_IDEAS_TABLE)
            ->where('chapter_id', $chapterId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function ideenAnzahlFuerFoodbook(array $chapterIds): int
    {
        if (! $this->ideenTabelleDa() || $chapterIds === []) {
            return 0;
        }

        return (int) DB::table(self::DISH_IDEAS_TABLE)
            ->whereIn('chapter_id', $chapterIds)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Ideen-Zeilen eines Kapitels (Entwürfe) für den Speisen-Baum. Guarded — bis E6
     * leere Liste. Roh-DB, weil das Model (M4) hier noch nicht existiert.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function ideen(int $chapterId)
    {
        if (! $this->ideenTabelleDa()) {
            return collect();
        }

        return DB::table(self::DISH_IDEAS_TABLE)
            ->where('chapter_id', $chapterId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();
    }
}
