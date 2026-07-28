<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * M7-06 / D-5 §4.4 + V-15: Bulk-Autopilot — der Job erzeugt VORSCHLÄGE in
 * die Review-Liste (nie Auto-Persistenz, GL-07); Übernahme einzeln/alle
 * bleibt interaktiv und respektiert Override-First. Schritte hier: die
 * implementierten Feld-KIs (description · kategorie · geschmack) — weitere
 * Orchestrator-Schritte docken über SCHRITTE an, sobald ihre Accept-Pfade
 * existieren (Registry-Prompts stehen seit M7-04).
 */
class BulkEnrichService
{
    public const SCHRITTE = ['description', 'category', 'geschmack'];

    /**
     * Spec 03 L1b: die VK-Schrittfolge — bewusst NICHT `SCHRITTE`. `category` ist
     * dort die 186er-Rezept-Kategorie (Basisrezept-Ebene); am Gericht heißt die
     * Klassifikation `speisen_klasse` und läuft über eine eigene Taxonomie. Der
     * Schritt-Name ist überall das Suffix seines Prompt-Keys (`recipe.description`,
     * `vk.wording`, `vk.plating`, `vk.speisen_klasse`) — so bleibt ablesbar, welche
     * Registry-Zeile ein Vorschlag erzeugt hat.
     */
    public const SCHRITTE_VK = ['description', 'wording', 'plating', 'speisen_klasse'];

    /** Die Teilmenge, die es nur am Gericht gibt — auf einem Basisrezept ehrlicher Fehler statt Unsinn. */
    private const NUR_GERICHT = ['wording', 'plating', 'speisen_klasse'];

    /** GP-Bulk-Autopilot-Schritte (Feld-KIs mit vorhandenem Accept-Pfad). */
    public const SCHRITTE_GP = ['condition', 'tags', 'allergene', 'naehrwerte'];

    /**
     * Spec 03 L7: WAS schreibt ein Schritt? Bisher stand diese Zuordnung nur
     * implizit im `match`-Block von `uebernehmen()`. Der One-Shot braucht sie
     * explizit, um die Schrittfolge auf echte LÜCKEN zu schneiden (ein schon
     * gefülltes Feld nicht erneut bezahlen). `source` ist null bei Feldern ohne
     * Lineage-Trio (`taste_direction` = Auto-Apply-Ausnahme, GL-07 §4.3).
     */
    public const ZIELFELDER = [
        'description' => ['feld' => 'description', 'source' => 'description_source'],
        'category' => ['feld' => 'category_id', 'source' => 'category_source'],
        'geschmack' => ['feld' => 'taste_direction', 'source' => null],
        'wording' => ['feld' => 'sales_wording_standard', 'source' => 'sales_wording_source'],
        'plating' => ['feld' => 'plating_text', 'source' => 'plating_source'],
        'speisen_klasse' => ['feld' => 'dish_class_id', 'source' => 'dish_class_source'],
    ];

    public function __construct(private AiGatewayService $ki)
    {
    }

    /**
     * Spec 03 L7: welche Schritte füllen an diesem Rezept noch eine Lücke?
     *
     * Ein Schritt fällt raus, wenn sein Ziel-Feld schon einen Wert trägt — egal
     * ob von Hand, vom Generator oder aus einem früheren Lauf. Das ist der
     * Unterschied zwischen „✨ Alles anreichern" (der Mensch will alle Felder neu
     * vorgeschlagen bekommen und entscheidet je Zeile) und der One-Shot-Kaskade
     * (die übernimmt selbst und darf darum nur Leerstellen anfassen). Ein
     * unbekannter Schritt bleibt drin — er soll in `proposeFeld()` laut scheitern,
     * nicht hier still verschwinden.
     *
     * @param  list<string>  $schritte
     * @return list<string>
     */
    public function luecken(FoodAlchemistRecipe $r, array $schritte): array
    {
        return array_values(array_filter($schritte, function (string $s) use ($r) {
            $ziel = self::ZIELFELDER[$s] ?? null;
            if ($ziel === null) {
                return true;
            }
            $wert = $r->getAttribute($ziel['feld']);

            return $wert === null || $wert === '';
        }));
    }

    /**
     * Lauf-Zeile anlegen (Fortschritts-Anker für Polling + Review-Queue).
     * Herausgezogen für Spec 03 L7: die One-Shot-Kaskade läuft synchron im ohnehin
     * asynchronen Generier-Job und darf deshalb keinen zweiten Job dispatchen —
     * sie braucht die Lauf-Zeile ohne `starte()`. Ein Insert, eine Wahrheit.
     */
    public function laufAnlegen(Team $team, int $total, BulkRunType $type = BulkRunType::Enrich, array $context = []): int
    {
        return (int) FoodAlchemistBulkRun::starte($team->id, $type, $total, $context, Auth::id())->id;
    }

    /** Startet einen Run (Job ist queued; Sandbox/Tests: sync). */
    public function starte(Team $team, array $recipeIds, array $schritte = self::SCHRITTE, BulkRunType $type = BulkRunType::Enrich): int
    {
        $ids = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $recipeIds)->pluck('id')->all();
        // V-047: woran der Lauf arbeitete — die Schrittfolge ist der Gegenstand der
        // Anreicherung, die Arbeitsmenge ihr Umfang. Ohne beides sagt die Zeile nur
        // „enrich, 12 done" und ein zweiter Lauf mit anderer Schrittfolge sieht gleich aus.
        $runId = $this->laufAnlegen($team, count($ids), $type, ['schritte' => array_values($schritte)]);

        \Platform\FoodAlchemist\Jobs\BulkEnrichJob::dispatch($runId, $team->id, $ids, $schritte);

        return $runId;
    }

    /**
     * Spec 03 L1b: Anreicherungs-Lauf am GERICHT — gleicher Vorschlags-Speicher und
     * gleiche Review-Mechanik, nur andere Schrittfolge. Die Arbeitsmenge wird hier
     * auf Verkaufsrezepte geschnitten: ein mitgegebenes Basisrezept fällt raus,
     * statt VK-Schritte auf der falschen Ebene zu fahren.
     */
    public function starteVk(Team $team, array $recipeIds, array $schritte = self::SCHRITTE_VK): int
    {
        $ids = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->whereIn('id', $recipeIds)->pluck('id')->all();

        return $this->starte($team, $ids, $schritte, BulkRunType::EnrichVk);
    }

    /** Job-Kern: ein Rezept × Schritte → Vorschläge (kein Fach-Write). */
    public function verarbeiteRezept(Team $team, int $runId, int $recipeId, array $schritte): void
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId);
        $fehler = false;
        foreach ($r === null ? [] : $schritte as $feld) {
            try {
                $vorschlag = $this->proposeFeld($team, $r, $feld);
                FoodAlchemistBulkProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $r->id, 'field' => $feld,
                    'value' => $vorschlag['value'],
                    'confidence' => $vorschlag['confidence'],
                    'reasoning' => $vorschlag['reasoning'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    // V-072: der GP-Zwilling wertet zusätzlich `[]` als leer. Die Abweichung
                    // ist bekannt und eingefroren — hier NICHT im Vorbeigehen angeglichen.
                    'status' => $vorschlag['value'] === null || $vorschlag['value'] === ''
                        ? BulkProposalStatus::Leer : BulkProposalStatus::Offen,
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                FoodAlchemistBulkProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'recipe_id' => $recipeId, 'field' => $feld,
                    'status' => BulkProposalStatus::Leer, 'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        }

        $this->zaehleFortschritt($runId, $fehler || $r === null);
    }

    /** @return array{wert: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int} */
    private function proposeFeld(Team $team, FoodAlchemistRecipe $r, string $feld): array
    {
        if (in_array($feld, self::NUR_GERICHT, true) && ! $r->is_sales_recipe) {
            throw new \RuntimeException("Bulk-Schritt [{$feld}] gilt nur fuer Verkaufsgerichte.");
        }
        if ($feld === 'speisen_klasse') {
            // Eine Wahrheit: Taxonomie-Aufbau, Aktiv-Filter und Klassen-Validierung
            // stehen im SpeisenKlassenService (Detail-Panel fährt denselben Weg).
            $c = app(SpeisenKlassenService::class)->classify($team, $r->id);

            return [
                'value' => $c['klasse_id'] === null ? null
                    : ['dish_class_id' => $c['klasse_id'], 'klasse_name' => $c['klasse_name']],
                'confidence' => $c['confidence'], 'reasoning' => $c['reasoning'], 'call_log_id' => $c['call_log_id'],
            ];
        }

        [$key, $kontext, $extract] = match ($feld) {
            'description' => ['recipe.description',
                ['name' => $r->name, 'description' => $r->description, 'zutaten' => $r->ingredients()->whereNull('deleted_at')->pluck('display_name')->all()],
                fn (array $w) => $w['description'] ?? null],
            'category' => ['recipe.category',
                ['name' => $r->name, 'category_id' => $r->category_id,
                    'kategorien' => FoodAlchemistRecipeCategory::orderBy('id')->limit(200)->pluck('label', 'id')->all()],
                fn (array $w) => $w['category_id'] ?? null],
            'geschmack' => ['recipe.geschmack',
                ['name' => $r->name, 'taste_direction' => $r->taste_direction],
                fn (array $w) => $w['taste_direction'] ?? null],
            // L1b: VK-Texte — derselbe Kontext-Zuschnitt wie die ✨-Einzelknöpfe im VkModal
            'wording' => ['vk.wording', $this->gerichtKontext($r),
                fn (array $w) => $w['sales_wording_standard'] ?? null],
            'plating' => ['vk.plating', $this->gerichtKontext($r) + ['portion_g' => $r->sales_quantity_per_unit_g],
                fn (array $w) => $w['preparation'] ?? $w['plating_text'] ?? null],   // Registry-Schema: {preparation}
            default => throw new \RuntimeException("Unbekannter Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id]);

        return [
            'value' => $extract($p->werte),
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /** Gericht-Kontext für die VK-Schritte (Name · Wording · Komponenten · Klasse). */
    private function gerichtKontext(FoodAlchemistRecipe $r): array
    {
        return [
            'name' => $r->name,
            'sales_wording_standard' => $r->sales_wording_standard,
            'komponenten' => $r->ingredients()->whereNull('deleted_at')
                ->with(['referencedRecipe:id,name', 'gp:id,name'])->get()
                ->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name)->all(),
            'speisen_klasse' => $r->dishClass?->label,
        ];
    }

    /** Review: EIN Vorschlag übernehmen (Override-First, Lineage ki, Stempel). */
    public function uebernehmen(Team $team, int $proposalId): bool
    {
        $prop = FoodAlchemistBulkProposal::whereKey($proposalId)->where('status', BulkProposalStatus::Offen)->first();
        if ($prop === null) {
            return false;
        }
        $r = FoodAlchemistRecipe::visibleToTeam($team)->find($prop->recipe_id);
        if ($r === null) {
            return false;
        }
        $wert = $prop->value;                                        // `array`-Cast = das bisherige json_decode

        if ($prop->field === 'speisen_klasse') {
            return $this->uebernehmeSpeisenKlasse($team, $r->id, $wert, $prop);
        }
        $text = fn () => is_string($wert) && trim($wert) !== '' ? trim($wert) : null;

        $update = match ($prop->field) {
            'description' => $r->description_source === 'manual' ? null
                : ['description' => (string) $wert, 'description_source' => 'ki', 'description_ai_confidence' => $prop->confidence],
            // L1b: VK-Texte mit eigenem Lineage-Paar; Gericht-Guard, damit ein
            // fehlgeleiteter Vorschlag nicht am Basisrezept landet.
            'wording' => $r->sales_wording_source === 'manual' || ! $r->is_sales_recipe || $text() === null ? null
                : ['sales_wording_standard' => $text(), 'sales_wording_source' => 'ki', 'sales_wording_ai_confidence' => $prop->confidence],
            'plating' => $r->plating_source === 'manual' || ! $r->is_sales_recipe || $text() === null ? null
                : ['plating_text' => $text(), 'plating_source' => 'ki', 'plating_ai_confidence' => $prop->confidence],
            'category' => $r->category_source === 'manual' || FoodAlchemistRecipeCategory::find((int) $wert) === null ? null
                : ['category_id' => (int) $wert, 'category_source' => 'ki', 'category_ai_confidence' => $prop->confidence],
            'geschmack' => in_array($wert, ['suess', 'herzhaft', 'neutral'], true)
                ? ['taste_direction' => $wert] : null,             // Auto-Apply-Ausnahme-Feld (GL-07 §4.3), kein Lineage-Trio
            default => null,
        };
        if ($update === null) {
            return false;                                            // Override-First / ungültig — Vorschlag bleibt offen
        }

        $r->update($update);
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /**
     * L1b-Accept der Speisen-Klasse: geht durch `SpeisenKlassenService::acceptKlasse`,
     * weil dort Override-First, Besitzer-Regel (D1) und Taxonomie-Validierung schon
     * stehen — und der Accept-Stempel mit. Ein Veto kommt dort als Exception; hier
     * bleibt der Vorschlag dann offen (Review kann später entscheiden).
     */
    private function uebernehmeSpeisenKlasse(Team $team, int $recipeId, mixed $wert, FoodAlchemistBulkProposal $prop): bool
    {
        $klasseId = is_array($wert) ? ($wert['dish_class_id'] ?? null) : $wert;
        if (! is_numeric($klasseId)) {
            return false;
        }
        try {
            app(SpeisenKlassenService::class)->acceptKlasse(
                $team, $recipeId, (int) $klasseId, (float) $prop->confidence, $prop->reasoning,
                $prop->call_log_id !== null ? (int) $prop->call_log_id : null,
            );
        } catch (\Throwable) {
            return false;                                            // manual / geerbt / ungültige Klasse
        }
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /** Review: »Alle übernehmen« eines Runs — Override-First gilt je Zeile. */
    public function alleUebernehmen(Team $team, int $runId): int
    {
        $n = 0;
        foreach (FoodAlchemistBulkProposal::where('run_id', $runId)->where('status', BulkProposalStatus::Offen)->orderBy('id')->pluck('id') as $id) {
            $n += $this->uebernehmen($team, (int) $id) ? 1 : 0;
        }

        return $n;
    }

    public function verwerfen(Team $team, int $proposalId): void
    {
        $prop = FoodAlchemistBulkProposal::find($proposalId);
        if ($prop !== null && FoodAlchemistRecipe::visibleToTeam($team)->whereKey($prop->recipe_id)->exists()) {
            $prop->update(['status' => BulkProposalStatus::Verworfen]);
            $this->ki->stempleRejected($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        }
    }

    /** Fortschritts-Polling (Browser-Pill). */
    public function status(Team $team, int $runId): ?FoodAlchemistBulkRun
    {
        return FoodAlchemistBulkRun::where('id', $runId)->where('team_id', $team->id)->first();
    }

    /**
     * Ein abgearbeitetes Element zählen und den Lauf schließen, sobald `done` das Soll
     * erreicht. V-032: bis hier stand derselbe Doppel-Update zweimal im selben Service
     * (Rezept- und GP-Pfad), jede neue Lauf-Art hätte ihn ein drittes Mal kopiert.
     * Die Zähler bleiben bewusst SQL-seitig (`done + 1`) — zwei parallele Job-Worker
     * dürfen sich nicht gegenseitig überschreiben.
     */
    private function zaehleFortschritt(int $runId, bool $fehler): void
    {
        FoodAlchemistBulkRun::whereKey($runId)->update([
            'done' => DB::raw('done + 1'),
            'failed' => DB::raw('failed + ' . ($fehler ? 1 : 0)),
            'updated_at' => now(),
        ]);
        FoodAlchemistBulkRun::whereKey($runId)->whereColumn('done', '>=', 'total')
            ->update(['status' => BulkRunStatus::Done->value, 'updated_at' => now()]);
    }

    public function offeneVorschlaege(Team $team, int $runId): int
    {
        return FoodAlchemistBulkProposal::where('run_id', $runId)->where('team_id', $team->id)
            ->where('status', BulkProposalStatus::Offen)->count();
    }

    // ── GP-Bulk-Autopilot (Pendant zum Rezept-Pfad, eigener Vorschlags-Speicher) ──

    /** Startet einen GP-Anreicherungs-Lauf (Job queued; Sandbox/Tests: sync). */
    public function starteGp(Team $team, array $gpIds, array $schritte = self::SCHRITTE_GP): int
    {
        $ids = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds)->pluck('id')->all();
        $runId = $this->laufAnlegen($team, count($ids), BulkRunType::EnrichGp, ['schritte' => array_values($schritte)]);

        \Platform\FoodAlchemist\Jobs\BulkEnrichGpJob::dispatch($runId, $team->id, $ids, $schritte);

        return $runId;
    }

    /** Job-Kern: ein GP × Schritte → Vorschläge (kein Fach-Write). */
    public function verarbeiteGp(Team $team, int $runId, int $gpId, array $schritte): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->with('commodity_group')->find($gpId);
        $fehler = false;
        foreach ($gp === null ? [] : $schritte as $feld) {
            try {
                $vorschlag = $this->proposeGpFeld($team, $gp, $feld);
                // V-072: hier zählt zusätzlich `[]` als leer — der Rezept-Zwilling kennt
                // den dritten Fall nicht. Bekannte Abweichung, eingefroren, nicht angeglichen.
                $leer = $vorschlag['value'] === null || $vorschlag['value'] === '' || $vorschlag['value'] === [];
                FoodAlchemistBulkGpProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'gp_id' => $gp->id, 'field' => $feld,
                    'value' => $vorschlag['value'],
                    'confidence' => $vorschlag['confidence'],
                    'reasoning' => $vorschlag['reasoning'],
                    'call_log_id' => $vorschlag['call_log_id'],
                    'status' => $leer ? BulkProposalStatus::Leer : BulkProposalStatus::Offen,
                ]);
            } catch (\Throwable $e) {
                $fehler = true;
                FoodAlchemistBulkGpProposal::create([
                    'team_id' => $team->id, 'run_id' => $runId, 'gp_id' => $gpId, 'field' => $feld,
                    'status' => BulkProposalStatus::Leer, 'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        }

        $this->zaehleFortschritt($runId, $fehler || $gp === null);
    }

    /** @return array{wert: mixed, confidence: ?float, reasoning: ?string, call_log_id: ?int} */
    private function proposeGpFeld(Team $team, FoodAlchemistGp $gp, string $feld): array
    {
        $basis = ['name' => $gp->name, 'condition' => $gp->condition, 'commodity_group' => $gp->commodity_group?->name];

        [$key, $kontext, $extract] = match ($feld) {
            'condition' => ['gp.condition', ['name' => $gp->name, 'condition' => $gp->condition ?: null],
                fn (array $w) => $w['condition'] ?? null],
            'tags' => ['gp.tags', ['name' => $gp->name,
                'tags' => collect(FoodAlchemistGp::TAG_FIELDS)->mapWithKeys(fn ($t) => [$t => $gp->getAttribute("tag_{$t}")])->filter(fn ($v) => $v !== null)->all()],
                fn (array $w) => $w['tags'] ?? null],
            'allergene' => ['gp.allergene', $basis,
                fn (array $w) => $w['allergene'] ?? null],
            'naehrwerte' => ['gp.naehrwerte', $basis,
                fn (array $w) => array_intersect_key($w, array_flip(['kcal', 'protein_g', 'fat_g', 'carbs_g', 'salt_g'])) ?: null],
            default => throw new \RuntimeException("Unbekannter GP-Bulk-Schritt [{$feld}]."),
        };

        $p = $this->ki->propose($key, $kontext, ['target_table' => 'foodalchemist_gps', 'target_id' => $gp->id]);

        return [
            'value' => $extract($p->werte),
            'confidence' => $p->confidence,
            'reasoning' => $p->reasoning,
            'call_log_id' => $p->callLogId,
        ];
    }

    /** Review: EIN GP-Vorschlag übernehmen (Override-First, Lineage ki, Stempel). */
    public function uebernehmenGp(Team $team, int $proposalId): bool
    {
        $prop = FoodAlchemistBulkGpProposal::whereKey($proposalId)->where('status', BulkProposalStatus::Offen)->first();
        if ($prop === null) {
            return false;
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($prop->gp_id);
        if ($gp === null || ! $gp->isOwnedBy($team)) {                 // D1: nur eigene GPs
            return false;
        }
        $wert = $prop->value;                                        // `array`-Cast = das bisherige json_decode
        $ok = false;

        if ($prop->field === 'condition') {
            $z = app(GpNamingService::class)->normalisiereZustand(is_array($wert) ? ($wert['condition'] ?? null) : $wert);
            if ($z !== null && in_array($z, GpNamingService::ZUSTAND_VOCAB, true) && $gp->condition_source !== 'manual') {
                $gp->update(['condition' => $z, 'condition_source' => 'ki', 'condition_ai_confidence' => $prop->confidence, 'condition_ai_reasoning' => $prop->reasoning]);
                $ok = true;
            }
        } elseif ($prop->field === 'tags' && is_array($wert) && $gp->tag_source !== 'manual') {
            $tagWerte = $wert['tags'] ?? $wert;
            $update = [];
            foreach (FoodAlchemistGp::TAG_FIELDS as $tag) {
                if (array_key_exists($tag, $tagWerte)) {
                    $update["tag_{$tag}"] = (bool) $tagWerte[$tag];
                }
            }
            if ($update !== []) {
                $gp->update([...$update, 'tag_source' => 'ki', 'tag_ai_confidence' => $prop->confidence, 'tag_ai_reasoning' => $prop->reasoning, 'tag_aggregated_at' => now()]);
                $ok = true;
            }
        } elseif ($prop->field === 'allergene' && is_array($wert)) {
            $update = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                $v = $wert['allergene'][$feld] ?? $wert[$feld] ?? null;
                // Override-First: nur setzen, wenn noch KEIN Override existiert (manuelle Werte bleiben)
                if (in_array($v, ['enthalten', 'spuren', 'nicht_enthalten'], true) && $gp->getAttribute("allergen_{$feld}") === null) {
                    $update["allergen_{$feld}"] = $v;
                }
            }
            if ($update !== []) {
                $gp->update([...$update, 'allergens_confidence' => $prop->confidence]);
                $ok = true;
            }
        } elseif ($prop->field === 'naehrwerte' && is_array($wert) && $gp->nutri_source !== 'manual') {
            $num = fn ($v) => is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : null;
            if ($num($wert['kcal'] ?? null) !== null) {                // kcal = Leit-Indikator (GL-08)
                $gp->update([
                    'nutri_kcal_per_100g' => $num($wert['kcal'] ?? null),
                    'nutri_protein_g_per_100g' => $num($wert['protein_g'] ?? null),
                    'nutri_fat_g_per_100g' => $num($wert['fat_g'] ?? null),
                    'nutri_carbs_g_per_100g' => $num($wert['carbs_g'] ?? null),
                    'nutri_salt_g_per_100g' => $num($wert['salt_g'] ?? null),
                    'nutri_source' => 'ki', 'nutri_ai_confidence' => $prop->confidence,
                ]);
                $ok = true;
            }
        }

        if (! $ok) {
            return false;                                              // Override-First / ungültig — Vorschlag bleibt offen
        }
        $this->ki->stempleAccepted($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        $prop->update(['status' => BulkProposalStatus::Uebernommen]);

        return true;
    }

    /** Review: »Alle übernehmen« eines GP-Runs — Override-First je Zeile. */
    public function alleUebernehmenGp(Team $team, int $runId): int
    {
        $n = 0;
        foreach (FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('status', BulkProposalStatus::Offen)->orderBy('id')->pluck('id') as $id) {
            $n += $this->uebernehmenGp($team, (int) $id) ? 1 : 0;
        }

        return $n;
    }

    public function verwerfenGp(Team $team, int $proposalId): void
    {
        $prop = FoodAlchemistBulkGpProposal::find($proposalId);
        if ($prop !== null && FoodAlchemistGp::visibleToTeam($team)->whereKey($prop->gp_id)->exists()) {
            $prop->update(['status' => BulkProposalStatus::Verworfen]);
            $this->ki->stempleRejected($prop->call_log_id !== null ? (int) $prop->call_log_id : null);
        }
    }

    /** Offene GP-Vorschläge eines Runs (Review-Zähler). */
    public function offeneGpVorschlaege(Team $team, int $runId): int
    {
        return FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('team_id', $team->id)
            ->where('status', BulkProposalStatus::Offen)->count();
    }

    /** GP-Vorschläge eines Runs fürs Review-Panel (mit Feld + Wert-Vorschau). */
    public function gpVorschlaege(Team $team, int $runId): \Illuminate\Support\Collection
    {
        return FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('team_id', $team->id)
            ->whereIn('status', [BulkProposalStatus::Offen, BulkProposalStatus::Uebernommen])
            ->orderBy('field')->get();
    }
}
