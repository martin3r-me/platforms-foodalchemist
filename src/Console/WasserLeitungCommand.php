<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * LEITUNGSWASSER — Regelwerk-Erweiterung §11.2a + das fehlende Grundprodukt.
 *
 * Ausgangslage: `MatchHeuristics::defaultGpAlias()` leitet bares »Wasser«/»Leitungswasser«
 * seit dem 2026-08-18 auf ein GP namens »Wasser: Leitung« — und ist im eigenen Kommentar
 * als „inert, solange das GP nicht existiert" markiert. Es existierte nicht. Folge: 64
 * Rezepte rechnen Leitungswasser als «Wasser: still, 0,5 l, Bio» — also als zugekauftes
 * Bio-Flaschenwasser mit Einkaufspreis.
 *
 * Warum das GP nicht einfach nachgelegt werden konnte: die LA-First-Doktrin ist auf
 * Tool-Ebene erzwungen (es gibt kein `gps.POST`; `gps.MINT_FROM_LA` braucht einen
 * Lieferantenartikel, `gp_proposals.POST` schreibt ausdrücklich KEIN GP). Leitungswasser hat
 * per Definition keinen Artikel. Und `requires_la = 0` war im Regelwerk an `is_derivat = 1`
 * gekoppelt — Leitungswasser ist kein Nebenprodukt eines Mutter-GPs.
 *
 * Dominique hat die Regelwerk-Erweiterung am 2026-09-03 freigegeben. Dieser Befehl setzt
 * BEIDES um, in dieser Reihenfolge (das Regelwerk steht über dem Code, CLAUDE.md):
 *   1. §11.2a ins Dossier — `requires_la = 0` gilt auch für selbst-gestellte, kostenfreie
 *      Ware, mit Bedingungen und Abgrenzung, damit daraus kein Freibrief wird.
 *   2. Das GP »Wasser: Leitung« (requires_la = 0, is_platzhalter = 0).
 *
 * NICHT enthalten: das Umhängen der 64 Bestandsrezepte. Das ändert bestehende
 * Kalkulationen und ist ein eigener Entscheid (Muster: WaWi-Skript 217).
 *
 * Idempotent: beide Schritte prüfen erst, ob sie schon getan sind.
 */
class WasserLeitungCommand extends Command
{
    protected $signature = 'foodalchemist:wasser-leitung
        {--team=6 : Besitzer-Team des neuen GPs}
        {--apply : wirklich schreiben (ohne: Trockenlauf)}';

    protected $description = 'Regelwerk §11.2a (selbst-gestellte Ware) + GP »Wasser: Leitung« anlegen';

    private const GP_NAME = 'Wasser: Leitung';

    private const DOSSIER_SLUG = 'regelwerk-gp-112-derivate-nebenprodukt-derivate';

    private const MARKER = '§11.2a';

    /** Der Regelwerk-Zusatz. Bewusst hier im Diff lesbar statt als Migrations-Blob. */
    private const ABSCHNITT = <<<'MD'

#### §11.2a Selbst-gestellte / kostenfreie Ware (`requires_la = 0` OHNE Derivat-Status) — NEU 2026-09-03

> **`requires_la = 0` gilt nicht nur für Derivate, sondern auch für Ware, die der Betrieb selbst stellt und die nicht bestellt wird.**

Bis hierher war `requires_la = 0` an `is_derivat = 1` gekoppelt. Das ist zu eng: Leitungswasser ist kein Nebenprodukt eines Mutter-GPs (§11.2), wird aber auch nicht zugekauft. Ohne diese Erweiterung bleibt es entweder ohne GP — und matcht dann auf gekauftes Flaschenwasser — oder es müsste als Derivat modelliert werden, was fachlich falsch wäre.

**Bedingungen (ALLE müssen zutreffen):**

- Die Ware entsteht **nicht** aus einem Mutter-GP (sonst §11.2, `is_derivat = 1`).
- Sie wird **nicht bestellt** und hat keinen Einkaufspreis — die Kosten stecken im Betriebsaufwand, nicht im Wareneinsatz.
- Sie ist im Rezept eine **echte, mengenrelevante Zutat** (nicht bloß eine Notiz).

**Folgen für die Felder:** `is_derivat = 0`, `derivat_von_gp_id = NULL`, `requires_la = 0`, `is_platzhalter = 0`.

`is_platzhalter` bleibt bewusst `0`: ein Platzhalter markiert etwas UNGEMAPPTES, das noch aufzulösen ist. Selbst-gestellte Ware ist aufgelöst — sie hat nur keinen Lieferanten. (Präzedenz WaWi-Skript 217 setzte Mineralwasser auf `is_platzhalter = 1`; das war für den Platzhalter-Zweck richtig, hält den §5-Alias aber inert und ist hier ausdrücklich NICHT das Modell.)

**Abgrenzung — kein Freibrief:**

- Flaschen-/Mineralwasser, gekaufte Eiswürfel, Sprudel → normale GPs mit `requires_la = 1`. Der §5-Alias greift ausdrücklich nur bei BAREM »Wasser«/»Leitungswasser«.
- „Hat gerade keinen Lieferanten" ist **kein** Fall von §11.2a. Das bleibt `requires_la = 1` plus Beschaffungs-Wunsch (`gp_proposals`).
- Preis: `preis_default_netto = NULL`. Ein `requires_la = 0`-GP trägt über den Lead-LA automatisch 0 € bei — genau das Gewollte, kein Sonderpfad in der Kalkulation.

**Bekannte Fälle:** `Wasser: Leitung`, Eis aus Leitungswasser.
MD;

    public function handle(KnowledgeEmbeddingService $emb): int
    {
        $apply = (bool) $this->option('apply');
        $teamId = (int) $this->option('team');
        $tag = $apply ? '✓' : '[dry-run]';

        // ── 1. Regelwerk zuerst — es steht über dem Code. ────────────────────
        $doc = DB::table('foodalchemist_knowledge_documents')
            ->where('slug', self::DOSSIER_SLUG)->whereNull('deleted_at')
            ->first(['id', 'slug', 'version', 'content_md', 'char_count']);
        if ($doc === null) {
            $this->error('Dossier ' . self::DOSSIER_SLUG . ' nicht gefunden — Regelwerk-Schritt nicht möglich.');

            return self::FAILURE;
        }

        if (str_contains((string) $doc->content_md, self::MARKER)) {
            $this->line("  {$tag} Regelwerk: §11.2a steht schon im Dossier — nichts zu tun.");
        } else {
            // Der Zusatz gehört VOR den Querverweis am Ende, nicht dahinter.
            $anker = '#### Querverweis';
            $neu = str_contains((string) $doc->content_md, $anker)
                ? str_replace($anker, ltrim(self::ABSCHNITT) . "\n\n" . $anker, (string) $doc->content_md)
                : rtrim((string) $doc->content_md) . "\n" . self::ABSCHNITT . "\n";
            // Die beiden Stellen, die requires_la an Derivate koppeln, mitziehen.
            $neu = str_replace(
                'ADD COLUMN requires_la INTEGER DEFAULT 1;  -- Derivate: 0',
                'ADD COLUMN requires_la INTEGER DEFAULT 1;  -- Derivate: 0; selbst-gestellte Ware: 0 (§11.2a)',
                $neu,
            );
            $neu = str_replace(
                'Kein Lieferanten-Match nötig (Derivate werden nicht zugekauft).',
                'Kein Lieferanten-Match nötig (Derivate werden nicht zugekauft). Dieselbe 0 gilt für selbst-gestellte, kostenfreie Ware — siehe §11.2a.',
                $neu,
            );

            $this->line(sprintf('  %s Regelwerk: §11.2a ergänzt (%d → %d Zeichen, v%d → v%d)',
                $tag, (int) $doc->char_count, mb_strlen($neu), (int) $doc->version, (int) $doc->version + 1));
            if ($apply) {
                DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update([
                    'content_md' => $neu,
                    'char_count' => mb_strlen($neu),
                    'content_hash' => hash('sha256', $neu),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
                // Semantik-Index nachziehen — sonst findet die Discovery den neuen Absatz nicht.
                $frisch = DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->first();
                $emb->queueDocument($frisch);
                $this->line('     Semantik-Index nachgezogen.');
            }
        }

        // ── 2. Das Grundprodukt. ────────────────────────────────────────────
        $vorhanden = FoodAlchemistGp::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('name', self::GP_NAME)->first(['id', 'requires_la', 'is_platzhalter']);
        if ($vorhanden !== null) {
            $this->line(sprintf('  %s GP: »%s« existiert schon (id=%d, requires_la=%s, is_platzhalter=%s).',
                $tag, self::GP_NAME, $vorhanden->id, (string) $vorhanden->requires_la, (string) $vorhanden->is_platzhalter));
        } else {
            $felder = [
                'team_id' => $teamId,
                'gp_key' => 'wasser_leitung',
                'name' => self::GP_NAME,
                'main_ingredient_slug' => 'wasser',
                'main_ingredient_display' => 'Wasser',
                'commodity_group_code' => '15',
                'sub_category' => '15.5 Wasser',
                'condition' => null,                             // wie »Wasser: still« — §9 kennt keinen Zustand »Leitung«
                'status' => 'approved',
                'is_derivat' => false,
                'derivat_von_gp_id' => null,
                'requires_la' => false,                          // §11.2a
                'is_platzhalter' => false,                       // aufgelöst, nicht ungemappt
            ];
            $this->line("  {$tag} GP: »" . self::GP_NAME . '« anlegen — ' . json_encode(
                array_intersect_key($felder, array_flip(['gp_key', 'commodity_group_code', 'sub_category', 'requires_la', 'is_platzhalter'])),
                JSON_UNESCAPED_UNICODE,
            ));
            if ($apply) {
                $gp = FoodAlchemistGp::create($felder);
                $this->line('     angelegt: id=' . $gp->id);
            }
        }

        // ── 3. Gegenprobe: feuert der §5-Alias jetzt? ───────────────────────
        if ($apply) {
            $ziel = FoodAlchemistGp::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('name', self::GP_NAME)->first(['id', 'name']);
            $this->line('');
            $this->line($ziel !== null
                ? '  Der §5-Alias («Wasser»/«Leitungswasser» → ' . self::GP_NAME . ') hat jetzt ein Ziel: id=' . $ziel->id
                : '  ⚠ Kein Ziel gefunden — der Alias bleibt inert.');
            $this->line('  Die 64 Bestandsrezepte behalten ihr altes GP — das ist ein eigener Entscheid.');
        } else {
            $this->line('');
            $this->line('  Trockenlauf. Mit --apply ausführen.');
        }

        return self::SUCCESS;
    }
}
