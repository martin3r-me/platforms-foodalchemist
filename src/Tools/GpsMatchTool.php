<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\LaFirstGpService;

/**
 * Phase 0: Zutat-Text → GP-Ground-Truth. Top-Match (GP oder Sub-Rezept) +
 * Kandidatenliste. Findet nichts Brauchbares (target=none): mit mint_if_missing=true
 * mintet der Tool LA-First einen GP aus passender LA (07·M3, tentative + LA-verknüpft);
 * ohne Flag / ohne passende LA → foodalchemist.gp_proposals.POST (Beschaffungs-Wunsch),
 * NIE einen GP frei erfinden (Doktrin: kein GP ohne LA).
 */
class GpsMatchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.MATCH';
    }

    public function getDescription(): string
    {
        return 'Matcht einen Zutat-Freitext gegen die Grundprodukte (GPs) und Sub-Rezepte des Teams. '
            . 'Liefert best_match (target gp|sub_recipe|none, score, band) + candidates (Top-k GPs). '
            . 'PFLICHT vor jeder Rezept-Zutat: nur gematchte gp_id/recipe_id verwenden. '
            . 'Bleibt die (lexikalische) Entscheidung unter dem Band, wird wie in der Generierung noch '
            . 'aus der semantisch-inklusiven Shortlist GEZOGEN (status=gezogen, mit origin + draw_floor) — '
            . 'ein Bestandstreffer wird also nicht als none gemeldet. '
            . 'Mit mint_if_missing=true wird NUR bei danach noch target=none LA-First ein GP aus passender LA '
            . 'gemintet (tentative, sofort verwendbar); ohne LA bleibt es none. '
            . 'Kein Treffer und kein Mint → foodalchemist.gp_proposals.POST (Beschaffungs-Wunsch), nie raten. '
            . 'Basisrezept-Zeilen (Typ-Präfix wie "Püree: …", "Jus: …"; basisrezept_zeile=true) bekommen nie ein GP: '
            . 'nur ein freigegebenes Unterrezept, sonst bleibt die Zeile eine Lücke (Basisrezept anlegen), kein Mint.'
            . ' Ablauf, Soll-Aspekte und geltende Regelwerke vorher: foodalchemist.ablauf.GET(vorgang="gp_aus_la_anlegen").';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zutat' => ['type' => 'string', 'description' => 'Zutat-Freitext, z. B. "Kürbispüree" oder "Zanderfilet ohne Haut"'],
                'main_ingredient_slug' => ['type' => 'string', 'description' => 'Optionaler Slug der Hauptzutat zur Präzisierung'],
                'k' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5, 'description' => 'Anzahl Kandidaten'],
                'mint_if_missing' => ['type' => 'boolean', 'default' => false, 'description' => 'Bei target=none LA-First ein GP aus passender LA minten (tentative). Ohne passende LA bleibt es none.'],
                'mode' => ['type' => 'string', 'enum' => ['gp_first', 'sub_recipe_first'],
                    'description' => 'Was zuerst gesucht wird: ein Grundprodukt (Default) oder ein bestehendes Sub-Rezept.'],
                'frische' => ['type' => 'string', 'enum' => ['neutral', 'fresh_first', 'frozen_first', 'preserved_first'],
                    'description' => 'Zustands-Präferenz, ebenfalls ein Tiebreak (s. `bio`). fresh_first bevorzugt frische Ware '
                        . 'und straft TK/konserviert ab; preserved_first dreht das um und bevorzugt zusätzlich verarbeitete '
                        . 'Formen (Convenience). frozen_first lässt frisch als Roh-Fallback zu.'],
                'bio' => ['type' => 'string', 'enum' => ['neutral', 'bio', 'conventional'],
                    'description' => 'Bio-Präferenz. WICHTIG — wirkt als TIEBREAK, nicht als Filter: sie entscheidet '
                        . 'zwischen gleich gut passenden Namen («Akazienhonig: trocken» vs. «…, Bio»), überstimmt aber '
                        . 'keinen deutlich besseren Namenstreffer. Wer Bio erzwingen muss, prüft `candidates` selbst. '
                        . 'Feld-primär über die bio-Spalte, Token nur als Fallback — «Biolandhof» im Namen macht kein Bio-Produkt.'],
                'prefer_raw' => ['type' => 'boolean', 'default' => false,
                    'description' => 'From Scratch: straft Schnitt-/Größen-Formen ab, damit die Roh-Grundform gewinnt '
                        . '(«Zwiebel» statt «Zwiebel geachtelt»).'],
                'commodity_group' => ['type' => 'string', 'description' => 'Optionaler Warengruppen-Code (Spec 16): verengt die LA-Suche beim Mint auf die WG-Lead-Lieferanten. Fehlt er → alle Leads.'],
                'bestand' => ['type' => 'string', 'enum' => ['hybrid', 'nur_bestand', 'komplett_neu'], 'default' => 'hybrid',
                    'description' => 'Reuse-Achse wie im Kreativ-Modus — steuert den Boden des Post-Match-Draws: '
                        . 'hybrid = 0,70 (Default), nur_bestand = 0,55 (breiter erden), komplett_neu = zieht NIE. '
                        . 'Denselben Wert setzen wie in der Session, sonst weicht das Tool-Urteil von der Generierung ab.'],
            ],
            'required' => ['zutat'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $zutat = trim((string) $arguments['zutat']);
        if ($zutat === '') {
            return ToolResult::error('zutat darf nicht leer sein.', 'VALIDATION_ERROR');
        }
        $slug = isset($arguments['main_ingredient_slug']) ? (string) $arguments['main_ingredient_slug'] : null;
        $svc = app(IngredientMatchService::class);

        // Spec 50 · E-2: Die Präferenzen kannte der SERVICE schon immer
        // ({@see IngredientMatchService::matchIngredient} + MatchHeuristics::variantRankResolved),
        // nur reichte das Tool sie nicht durch — es rief mit lauter Defaults auf. Deshalb musste
        // ein Agent am 2026-09-03 „Bio oder nicht?" als Rückfrage stellen und ein bereits
        // angelegtes Rezept nachkorrigieren. Das war keine Design-Entscheidung, sondern eine
        // fehlende Schema-Zeile. Unbekannte Werte fallen auf den neutralen Default zurück,
        // wie im Service.
        $mode = (string) ($arguments['mode'] ?? 'gp_first');
        $pref = (string) ($arguments['frische'] ?? 'neutral');
        $bio = (string) ($arguments['bio'] ?? 'neutral');
        $preferRaw = (bool) ($arguments['prefer_raw'] ?? false);

        $match = $svc->matchIngredient($team, $zutat, $slug, $mode, $pref, $preferRaw, $bio);

        // Spec 80 B4 (Live-Test demo 09.10.): eine Basisrezept-Zeile („Püree: Petersilienwurzel") wird nur ein
        // freigegebenes Unterrezept oder bleibt eine Lücke — nie ein rohes GP. Der Generator und syncIngredients
        // halten das; dieses Tool lieferte dem Agenten bisher trotzdem ein GP (Treffer, Draw oder Mint).
        $basisrezeptZeile = app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class)->istBasisrezeptZeile($zutat);
        if ($basisrezeptZeile && ($match['target'] ?? null) === 'gp') {
            $match = ['target' => 'none', 'status' => \Platform\FoodAlchemist\Enums\MatchBand::NoMatch,
                'gp_id' => null, 'gp_name' => null, 'recipe_id' => null, 'recipe_name' => null, 'score' => 0.0];
        }

        // Kandidaten EINMAL holen: sie speisen den Draw unten UND die Antwort. Vorher wurden sie
        // erst am Ende geholt — der gezogene Treffer wäre also nicht zwingend derselbe gewesen,
        // den der Aufrufer in `candidates` sieht.
        $k = min(10, max(1, (int) ($arguments['k'] ?? 5)));
        $kandidaten = $svc->candidatesFor($team, $zutat, $slug, $k);

        // ── POST-MATCH-DRAW (2026-09-07) ─────────────────────────────────────────────────
        // Der Generator zieht nach einer erfolglosen Matcher-Entscheidung noch aus der
        // semantisch-inklusiven Shortlist ({@see RecipeGeneratorService::ziehtAusBestand}).
        // Dieses Tool kannte den Zug NICHT und meldete `none` — bei „Tomaten, geschält, aus der
        // Dose" mit dem korrekten Pelati bei 0,769 in derselben Antwort. Zusammen mit der eigenen
        // Anleitung („kein Treffer → minten oder Beschaffungs-Wunsch") erzeugte das GP-Dubletten
        // und Phantom-Bedarf. Der Draw läuft darum HIER, und zwar VOR dem Mint.
        // Die Auswahl ist geteilt (drawKandidaten), die Prüfung ist hier: kein Eltern-Rezept,
        // also kein Zyklus-Schutz, nur Sichtbarkeit + Status.
        $bestand = (string) ($arguments['bestand'] ?? 'hybrid');
        $drawFloor = $svc->drawFloor($bestand);
        if (($match['target'] ?? null) === 'none') {
            $wantKind = ($mode === 'sub_recipe_first' || $basisrezeptZeile) ? 'sub' : 'gp';
            foreach ($svc->drawKandidaten($kandidaten, $wantKind, $bestand) as $c) {
                $gezogen = $wantKind === 'gp'
                    ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()->visibleToTeam($team)
                        ->whereIn('status', ['approved', 'tentative'])->where('is_platzhalter', false)
                        ->find($c['id'])
                    // Prüfung wie `RecipeGeneratorService::validiereProposedSub`: seit Spec 80 B4 nur
                    // freigegebene Basisrezepte (Bestand = freigegeben) plus Funktionsprüfung
                    // (`BestandsPassung`: Typ, Bestandteile). Vorher stub/draft/review mit — der Agent
                    // verknüpfte so Entwürfe, die der Generator ablehnt.
                    : \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->basis()
                        ->where('status', 'approved')->find($c['id']);
                if ($gezogen === null) {
                    continue;
                }
                if ($wantKind === 'sub' && \Platform\FoodAlchemist\Support\BestandsPassung::grund(
                    $zutat, (string) $gezogen->name,
                    $gezogen->spec_is_vegan !== null ? (bool) $gezogen->spec_is_vegan : null,
                    $gezogen->spec_is_vegetarian !== null ? (bool) $gezogen->spec_is_vegetarian : null,
                ) !== null) {
                    continue;
                }
                $match = [
                    'target' => $wantKind === 'gp' ? 'gp' : 'sub_recipe',
                    'status' => 'gezogen',   // Provenienz-Band: nicht der Name traf, die Bedeutung
                    'gp_id' => $wantKind === 'gp' ? (int) $gezogen->id : null,
                    'gp_name' => $wantKind === 'gp' ? (string) $gezogen->name : null,
                    'recipe_id' => $wantKind === 'gp' ? null : (int) $gezogen->id,
                    'recipe_name' => $wantKind === 'gp' ? null : (string) $gezogen->name,
                    'score' => round($c['score'], 4),
                    // Ohne diese zwei Felder wäre ein gezogener Treffer für den Aufrufer nicht
                    // von einem Namenstreffer zu unterscheiden.
                    'origin' => 'semantic',
                    'draw_floor' => $drawFloor,
                ];
                break;
            }
        }

        // 07·M3: mint-if-missing — Bestand-Miss + passende LA → LA-First-Mint (tentative),
        // damit der Rezept-Flow nicht bei GP-Lücken dead-endet. Ohne LA bleibt target=none.
        // WICHTIG: erst NACH dem Draw — sonst mintet das Tool an einem vorhandenen Bestandstreffer
        // vorbei und legt eine Dublette an.
        $minted = false;
        $wgHint = isset($arguments['commodity_group']) ? trim((string) $arguments['commodity_group']) : null;
        if (($match['target'] ?? null) === 'none' && ($arguments['mint_if_missing'] ?? false) && ! $basisrezeptZeile) {
            $gp = app(LaFirstGpService::class)->mintFromLa($team, $zutat, $slug, $wgHint !== '' ? $wgHint : null);
            if ($gp !== null) {
                $minted = true;
                $match = [
                    'target' => 'gp',
                    'status' => 'mint',   // Provenienz-Band: frisch LA-First gemintet (tentative)
                    'gp_id' => $gp->id,
                    'gp_name' => $gp->name,
                    'recipe_id' => null, 'recipe_name' => null,
                    'score' => null,
                ];
            }
        }

        if ($match['status'] instanceof \BackedEnum) {
            $match['status'] = $match['status']->value;
        }

        return ToolResult::success([
            'best_match' => $match,
            'minted' => $minted,
            // true = nur ein freigegebenes Unterrezept oder eine Lücke (Beschaffung: Basisrezept anlegen), nie ein GP.
            'basisrezept_zeile' => $basisrezeptZeile,
            // Nachvollziehbar machen, WARUM (nicht) gezogen wurde: bei `none` ist die Frage
            // „lag nichts über dem Boden?" sonst nicht beantwortbar.
            'draw' => ['bestand' => $bestand, 'floor' => $drawFloor],
            'candidates' => $kandidaten,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'zutat', 'match', 'ground-truth', 'la-first'],
            // Default = reiner Read; NUR mit mint_if_missing=true entsteht ein GP (tentative,
            // LA-belegt, dedup-idempotent, reversibel) → als schreibfähig deklariert (Lockstep-Ehrlichkeit).
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['creates'],   // nur im mint_if_missing-Zweig
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.gps.MINT_FROM_LA', 'foodalchemist.gp_proposals.POST', 'foodalchemist.gps.GET'],
            'examples' => ['Welcher GP passt zu "Kürbispüree"?', 'Matche "Ruby-Schokolade" und minte LA-First, falls kein GP existiert (mint_if_missing)'],
        ];
    }
}
