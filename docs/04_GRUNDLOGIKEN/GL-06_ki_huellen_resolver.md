---
typ: Grundlogik-Spec
gl_id: GL-06
stand: 2026-06-10
status: ausgearbeitet
---

# GL-06 — KI-Hüllen-Resolver (Semantic Layers)

> **Normative Quellen:** BHG-Office-Pattern `core.semantic_layer.*` (Scope-Chain + Versionierung) ⚠D3
> **Implementierungs-Quelle (Ist):** `src-tauri/src/layers.rs` (komplett, 138 Zeilen) + `src-tauri/src/gemini.rs:245-253` (`compose_system_prompt`) + SQLite `ai_layer` / `ai_layer_version`

## 1. Zweck & Quellen

Jeder KI-Call der App bekommt einen **versionierten, layered System-Prompt-Stack** („semantische Hüllen") vor den Per-Call-Task-Prompt gestellt. Hüllen kapseln Perspektive, Ton, Heuristiken und Negativ-Raum getrennt vom Task — eine Hülle ändern heißt: alle Features, die sie nutzen, ändern sich konsistent, versioniert und auditierbar.

| Baustein | Ist-Implementierung |
|---|---|
| Scope-Reihenfolge | `layers.rs:25` (`SCOPE_ORDER`) |
| Resolver | `layers.rs:35-78` (`resolve_for_call`) |
| Scope-Query (aktive Version) | `layers.rs:80-129` (`fetch_active_layers_for_scope`) |
| Modul-Filter | `layers.rs:133-138` (`layer_targets_module`) |
| System-Prompt-Komposition | `gemini.rs:245-253` (`compose_system_prompt`), Einsatz als `systemInstruction` `gemini.rs:349` |
| Audit der genutzten Hüllen | `commands.rs` — pro Call wird `[{key, semver}]` als JSON in `ai_call_log.layers_used` geschrieben (z.B. `commands.rs:14103-14105`) |

**Ist-Daten (wawi_1494.sqlite, 2026-06-10):** 14 Hüllen, 21 Versionen. Ziel-Mapping laut `02_DATENMODELL.md`: `ai_layer`/`ai_layer_version` → **`core.semantic_layer` der Plattform ⚠D3** (das CJ-Schema wurde nach dem BHG-Office-Pattern gebaut; erwartet wird 1:1-Pattern-Match, Hüllen-Texte werden als Seed übernommen).

## 2. Eingaben / Ausgaben / Invarianten

**Datenmodell (Ist, SQLite — vom Plattform-Pendant zu bestätigen ⚠D3):**

```
ai_layer:          id, key, scope ('global'|'module'|'customer'|'skill'|'field'),
                   scope_ref (NULL bei global; sonst Modulname / Kunden-Slug / Skill-Key /
                   "tabelle.feldname"), title, active_version_id (weicher FK, ohne Constraint
                   wegen Zirkularität), status ('draft'|'pilot'|'production'|'archived'),
                   enabled_modules (JSON-Array von Modul-Keys, '["all"]' = Joker),
                   notes, created_at, updated_at — UNIQUE(scope, scope_ref, key)

ai_layer_version:  id, layer_id (FK CASCADE), semver, perspektive, ton (JSON),
                   heuristiken (JSON), negativ_raum (JSON),
                   rendered_block (beim Speichern gecachter Fließtext-Block),
                   created_at — UNIQUE(layer_id, semver)
```

**Eingaben des Resolvers:** `module` (Pflicht, String), `customer_ref` (optional), `skill_ref` (optional), `field_target` (optional, Format `"tabelle.feldname"`, z.B. `"recipes.zubereitung_md"`).

**Ausgabe:** `ResolvedLayers { layers: [ResolvedLayerInfo], combined_block: String, total_tokens_approx: int }` — `combined_block` = alle `rendered_block` in Scope-Reihenfolge mit `"\n\n"` gejoint; `total_tokens_approx` = `ceil(chars/4)`.

**Invarianten:**

1. **Scope-Reihenfolge fix:** `global → module → customer → skill → field` (`layers.rs:25`). Es findet **kein Text-Merge** statt — Präzedenz ist rein positional: innere Scopes stehen später im Prompt (näher am User-Prompt) und überschreiben äußere damit kontextuell.
2. **Nur aktive Hüllen:** `status IN ('pilot','production')`; `draft` und `archived` werden nie resolvt.
3. **Genau eine Version pro Hülle:** Join über `active_version_id` — alte Versionen bleiben unverändert in der DB (Audit), nur der Zeiger wandert. Versionen sind **append-only** (`UNIQUE(layer_id, semver)`); inhaltliche Änderung = neue semver-Zeile + Zeiger-Flip.
4. **Doppelter Modul-Filter:** (a) bei `scope='module'` ist `scope_ref` = Modulname des Calls; (b) zusätzlich muss bei JEDEM Scope `enabled_modules` den Call-Modulwert oder `"all"` enthalten. Unparsbares `enabled_modules`-JSON → Hülle wird **konservativ ausgeschlossen** (`layers.rs:133-138`).
5. **Ref-Pflicht-Scopes:** `customer`/`skill`/`field` werden übersprungen, wenn der jeweilige Ref nicht übergeben wurde (`layers.rs:54-56`) — Field-Hüllen aktivieren sich nie per Default.
6. **Deterministische Ordnung innerhalb eines Scopes:** `ORDER BY l.key` (alphabetisch).
7. **Audit-Pflicht:** Jeder KI-Call persistiert die genutzten Hüllen als `[{key, semver}]` in `ai_call_log.layers_used` (→ GL-07).
8. Fehlt zu einem gegebenen `field_target` eine Hülle, ist das **kein Fehler** — der Stack ist einfach kürzer.

## 3. Pseudocode

```
function resolve_for_call(module, customer_ref?, skill_ref?, field_target?):
    collected = []
    for scope in [global, module, customer, skill, field]:
        scope_ref = match scope:
            global   -> NULL
            module   -> module                # der Call-Modulwert selbst
            customer -> customer_ref
            skill    -> skill_ref
            field    -> field_target          # "tabelle.feldname"
        if scope in {customer, skill, field} and scope_ref is NULL: continue

        rows = SELECT l.*, v.semver, COALESCE(v.rendered_block,'')
               FROM ai_layer l JOIN ai_layer_version v ON v.id = l.active_version_id
               WHERE l.scope = scope
                 AND (l.scope_ref = scope_ref ODER beide NULL)
                 AND l.status IN ('pilot','production')
               ORDER BY l.key
        rows = [r for r in rows if json(r.enabled_modules) enthält ("all" oder module)]
               # JSON-Parse-Fehler -> Zeile fliegt raus
        collected += rows

    combined_block      = join(rendered_block der collected, "\n\n")
    total_tokens_approx = ceil(len_chars(combined_block) / 4)
    return { layers: collected, combined_block, total_tokens_approx }

# Finale System-Prompt (gemini.rs:247-253):
function compose_system_prompt(layers_block?, system?):
    beide NULL  -> NULL
    nur layers  -> layers_block
    nur system  -> system
    beide       -> layers_block + "\n\n" + system   # Hüllen IMMER vor Ad-hoc-System-Prompt
# Ergebnis geht als systemInstruction in den LLM-Request; der Task-Prompt bleibt User-Turn.
```

## 4. Entscheidungstabellen

### 4.1 Scope-Präzedenz (Positions-Präzedenz im Prompt, kein Merge)

| Pos. | Scope | scope_ref | Bedeutung | Prompt-Position / Gewicht |
|---|---|---|---|---|
| 1 | `global` | NULL | universelle Voice/Sparring/Gastro-Heuristik | am weitesten vom User-Prompt → schwächste Position |
| 2 | `module` | Modul-Key (`grundprodukte`, `basisrezepte`, `verkaufsrezepte`, …) | modul-spezifische Fach-Hülle | |
| 3 | `customer` | Kunden-/Team-Slug | mandanten-spezifisch (Ist: **kein Call-Site nutzt es** — alle übergeben `None`; im Ziel: Team-Kontext der Plattform) | |
| 4 | `skill` | Skill-Key | fein-granular pro Use-Case (Ist: ungenutzt) | |
| 5 | `field` | `"tabelle.feldname"` | pro Ziel-Feld, nur bei explizitem `field_target` | direkt vor Task-Prompt → stärkste Position |

### 4.2 Aufnahme-Filter pro Hülle

| Bedingung | Ergebnis |
|---|---|
| `status` ∈ {pilot, production} UND `enabled_modules` ⊇ {`module`} oder {"all"} UND scope_ref passt | aufgenommen |
| `status` ∈ {draft, archived} | nie aufgenommen |
| `enabled_modules` enthält weder Call-Modul noch `"all"` | nicht aufgenommen |
| `enabled_modules` unparsbar | nicht aufgenommen (konservativ) |
| Ref-Scope ohne übergebenen Ref | Scope komplett übersprungen |
| `active_version_id` NULL / zeigt ins Leere | Zeile fällt aus dem JOIN — Hülle inaktiv |

### 4.3 Hüllen-Inventar (Ist-Bestand, 14 Hüllen / 21 Versionen)

| Scope | scope_ref | key | Titel | Status | enabled_modules | aktive Version |
|---|---|---|---|---|---|---|
| global | — | `cooking-jarvis-core` | Cooking Jarvis — Kern | production | `["all"]` | 1.0.0 |
| module | grundprodukte | `allergen-inferenz` | Allergen-Inferenz für Grundprodukte | pilot | `["grundprodukte"]` | 1.0.0 |
| module | grundprodukte | `gp-klassifikation` | GP-Klassifikation — Regelwerk-Hülle | pilot | `["grundprodukte"]` | 1.1.2 (4 Versionen) |
| module | basisrezepte | `basisrezept-generator` | Basisrezept-Generator (Produktions-Sicht) | pilot | `["basisrezepte"]` | 1.2.0 (3 Versionen) |
| module | basisrezepte | `recipe-description` | Rezept-Beschreibung — Pairing & §8 | pilot | `["basisrezepte","verkaufsrezepte"]` | 1.0.0 |
| module | verkaufsrezepte | `vk-rezept-generator` | VK-Rezept-Generator (Konsumenten-Sicht) | pilot | `["verkaufsrezepte"]` | 1.2.0 (3 Versionen) |
| field | `wawi_gp_v2.gp_name` | `field-gp-name` | Field-Hülle GP-Name (Regelwerk §6) | pilot | `["all"]` | 1.0.0 |
| field | `wawi_gp_v2.ai_begruendung` | `field-ai-begruendung` | Field-Hülle KI-Begründung (kompakt, parsbar) | pilot | `["all"]` | 1.0.0 |
| field | `wawi_gp_v2.tag_ai_begruendung` | `field-gp-tag-ai-begruendung` | Field-Hülle GP-Tag-Begründung | pilot | `["all"]` | 1.0.0 |
| field | `recipes.ki_beschreibung` | `field-ki-beschreibung` | Field-Hülle KI-Beschreibung (§8 + Pairing-Anker) | pilot | `["all"]` | 1.0.0 |
| field | `recipes.zubereitung_md` | `field-zubereitung-md` | Field-Hülle Zubereitung (Markdown + Küchen-Verben) | pilot | `["all"]` | 1.0.0 |
| field | `recipes.marketing_text` | `field-marketing-text` | Field-Hülle Marketing-Text (Gast-Sicht) | pilot | `["all"]` | 1.0.0 |
| field | `recipes.ai_begruendung` | `field-recipes-ai-begruendung` | Field-Hülle Rezept-Begründung | pilot | `["all"]` | 1.0.0 |
| field | `wawi_la_structured.ai_begruendung` | `field-la-ai-begruendung` | Field-Hülle LA-Match-Begründung | pilot | `["all"]` | 1.0.0 |

**⚠ Ist-Befund Modul-Key-Drift (beim Rewrite bereinigen):** Die Call-Sites übergeben fünf verschiedene Modul-Werte: `"grundprodukte"`, `"basisrezepte"`, `"verkaufsrezepte"`, **`"rezept"`** (~20 Sites, z.B. `commands.rs:13122`) und **`"recipes"`** (3 Sites: `commands.rs:11800,12158,12411`). `"rezept"`/`"recipes"` matchen **weder** ein `scope_ref` **noch** ein `enabled_modules` der Modul-Hüllen — diese Calls bekommen faktisch nur die globale Hülle (+ ggf. Field-Hülle, da dort `["all"]`). Die Modul-Hüllen `basisrezept-generator`/`vk-rezept-generator`/`recipe-description` aktivieren nur bei `ai_generate_recipe`/`ai_extract_recipe` (Modul aus `typ` abgeleitet, `commands.rs:20646,22008`) und `ai_plan_dishes` (`commands.rs:22154`). **Ziel-Spec:** kanonische Modul-Key-Liste als Enum (`grundprodukte | basisrezepte | verkaufsrezepte | lieferanten | foodbook`), Drift-Werte `rezept`/`recipes` beim Port auf `basisrezepte` mappen.

## 5. Golden-Testfälle

Basis = Ist-Inventar aus 4.3 (wird per Seed in die Test-DB übernommen). Erwartungen exakt aus `layers.rs` abgeleitet.

| # | Input (`module`, `customer_ref`, `skill_ref`, `field_target`) | Expected |
|---|---|---|
| GT-06-1 | (`"grundprodukte"`, –, –, –) | 3 Hüllen in dieser Reihenfolge: `cooking-jarvis-core` (1.0.0), dann module alphabetisch nach key: `allergen-inferenz` (1.0.0), `gp-klassifikation` (1.1.2). `combined_block` = die 3 `rendered_block` mit `\n\n` gejoint. |
| GT-06-2 | (`"rezept"`, –, –, –) | **Nur 1 Hülle:** `cooking-jarvis-core`. Keine Modul-Hülle (kein scope_ref `rezept`, kein enabled_modules-Match) — belegt die Drift aus 4.3. |
| GT-06-3 | (`"rezept"`, –, –, `"recipes.zubereitung_md"`) | 2 Hüllen: `cooking-jarvis-core`, dann `field-zubereitung-md` **als letzte** (stärkste Position). |
| GT-06-4 | (`"basisrezepte"`, –, –, –) | 3 Hüllen: `cooking-jarvis-core`, `basisrezept-generator` (1.2.0 — NICHT 1.0.0/1.1.0), `recipe-description` (enabled_modules enthält `basisrezepte`). `vk-rezept-generator` fehlt. |
| GT-06-5 | (`"grundprodukte"`, –, –, `"recipes.foo"`) | Wie GT-06-1 — unbekanntes `field_target` erzeugt **keinen Fehler**, nur keinen Field-Block. |
| GT-06-6 | Hülle mit `status='draft'` + passendem Scope angelegt, dann Resolve | Draft-Hülle erscheint nicht im Ergebnis. |
| GT-06-7 | Hülle mit `enabled_modules='kaputt['` (unparsbar) | Hülle wird ausgeschlossen, kein Fehler (konservativ, `layers.rs:133-138`). |
| GT-06-8 | `combined_block` mit 10 Zeichen | `total_tokens_approx = 3` (= `ceil(10/4)`, `layers.rs:71`). |
| GT-06-9 | `compose_system_prompt(Some("HÜLLEN"), Some("TASK-SYS"))` | `"HÜLLEN\n\nTASK-SYS"` — Hüllen stehen IMMER vor dem Ad-hoc-System-Prompt (`gemini.rs:252`). |
| GT-06-10 | Neue Version 1.3.0 zu `vk-rezept-generator` anlegen, `active_version_id` NICHT umstellen, Resolve mit `"verkaufsrezepte"` | Weiterhin 1.2.0 im Ergebnis — nur der Zeiger entscheidet, nie „höchste semver". |

## 6. Offene Weichen & Verbesserungen

- **⚠D3 — BEANTWORTET aus Core-Code (2026-06-11, platforms-core lokal):** `core.semantic_layer` **existiert** und ist nutzbar: Tabellen `semantic_layers`/`semantic_layer_versions`/`semantic_layer_audit`; Resolver `Platform\Core\SemanticLayer\Services\SemanticLayerResolver::resolveFor(?Team $team, ?string $module): ResolvedLayer` (Scope **global|team**, Modul-Gating via `enabled_modules[]`, leer = überall; 1h-Cache mit Versions-Hash, Invalidierung via Model-Events; CLI `layer:create/activate/enable-module`). **Mapping unserer 5-Stufen-Kette:** `global`→global ✓ · `customer`→team ✓ (inkl. Parent-Team-Hierarchie im Core-Team-Modell) · `module`→`enabled_modules`-Gating ✓ · **`skill`/`field` existieren im Core NICHT** — und das Core-Payload ist strukturiert (perspektive/ton/heuristiken/negativ_raum = Brand-Voice/Leitbild), kein freier System-Prompt-Text. **Konsequenz (Hybrid):** Verhaltens-/Voice-Hüllen (global/team/modul-gated) wandern in den Core-Layer und werden via `resolveFor()` konsumiert; die 8 **Field-Hüllen** + TASK_PROMPTs bleiben **modul-eigene Prompt-Registry** (Tabelle oder Konstanten mit Versionierung) — komponiert vom `AiGatewayService` in der Reihenfolge core-Layer → Modul-Hüllen → Task-Prompt. Seed: die 14 Ist-Hüllen entsprechend aufteilen.
- **V-01 (Tiering):** Modell-Wahl pro Feature (Tier A Qualität / B Mechanik-Labels / C Vision) gehört NICHT in die Hüllen, sondern in die Gateway-Konfiguration — Hüllen bleiben modellagnostisch.
- **Verbesserung (aus 4.3):** Kanonisches Modul-Key-Enum + Validierung beim Hüllen-Anlegen (`enabled_modules` nur aus Enum), damit die Ist-Drift (`rezept`/`recipes`) nicht erneut entsteht.
- **Verbesserung:** `total_tokens_approx` (chars/4) im Ziel durch echte Tokenizer-Zählung des Gateways ersetzen; Wert dient nur Anzeige/Budget-Warnung, hat keine Logik-Wirkung.
