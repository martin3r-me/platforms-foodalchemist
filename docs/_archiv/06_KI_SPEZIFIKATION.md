---
typ: KI-Spezifikation
stand: 2026-06-10
status: ausgearbeitet
---

# 06 — KI-Spezifikation (AiGateway, Tiering, Robustheit, Audit)

> **Einordnung.** Diese Spec definiert die KI-Infrastruktur des Moduls (Domäne D-4, `01_ARCHITEKTUR.md` §4). Sie **referenziert** die fertigen Grundlogiken statt sie zu duplizieren: Hüllen-Komposition → **GL-06**, Vorschlags-Lebenszyklus (propose/accept/reject/clear, Lineage, Override-First) → **GL-07**, Wissenskontext → **GL-13**. Hier steht, was DAZWISCHEN liegt: der Gateway-Vertrag, Modell-Wahl, Transport-Robustheit, Queue-Strategie und Audit/Kosten.
>
> **Ist-Quelle:** `src-tauri/src/gemini.rs` (869 Zeilen, vollständig analysiert) + 42 `TASK_PROMPT_*`-Konstanten in `commands.rs` (Anhang A) + `ai_call_log`-Ist (8.594 Calls, 16,7 Mio Tokens in / 2,4 Mio out, 46 Features, Stand 2026-06-10).

## 1. AiGatewayContract (⚠D3)

**Arbeits-Annahme aus `08_ENTSCHEIDUNGEN.md` D3 (b/c):** Das Modul definiert einen eigenen `AiGatewayService` **hinter einem Interface**. Stellt die Plattform später einen zentralen KI-Service (Key-Verwaltung, Rate-Limit, Kosten pro Team), tauscht **nur das Binding im ServiceProvider** — kein Call-Site ändert sich. Deshalb: alle ~90 KI-Features rufen ausschließlich den Contract, nie einen HTTP-Client direkt.

```php
interface AiGatewayContract
{
    public function call(AiRequest $request): AiResult;

    /** Multi-Turn Function-Calling (agentisches Matching, Template-Fill).
     *  $dispatch führt Tool-Calls aus (read-only Service-Reads — NIE mit offener
     *  DB-Transaktion über den Netz-Call!) und liefert das JSON-Ergebnis zurück. */
    public function callWithTools(
        AiRequest $request,
        array $toolDeclarations,          // functionDeclarations-Schema
        Closure $dispatch,                // fn(string $name, array $args): array
        int $maxRounds = 6,
        int $wallClockBudgetSecs = 45,    // Ist-Wert gemini.rs:331 — Schutz vor Endlos-Loops
    ): AiResult;
}

final class AiRequest
{
    public string  $feature;          // ai_call_log-Label, z.B. 'recipe_zubereitung_infer'
    public string  $prompt;           // Task-Prompt + Daten-Kontext (User-Turn)
    public array   $systemHuellen;    // ResolvedLayers aus GL-06 (combined_block + [{key,semver}])
    public array   $wissen;           // Wissens-Blöcke aus GL-13 (slugs + Texte), oft leer
    public ?array  $schema;           // JSON-Schema für Structured Output (§3.4); null = json_mode only
    public ?AiTier $tier;             // A|B|C|D (§2); null → Lookup feature→Tier aus Gateway-Config
    public array   $multimodal;       // Attachments [{mimeType, dataBase64}] — Bilder/PDF (§2 Tier C)
    public float   $temperature = 0.2;
    public int     $maxOutputTokens = 2048;   // Gateway erzwingt Floor 4096 (§3.5)
    public ?string $targetTable;      // Audit (§5)
    public ?int    $targetId;
}

final class AiResult
{
    public string  $text;             // Fence-bereinigt (§3.4); bei JSON-Features parsebar
    public ?array  $json;             // geparst, wenn json_mode/schema aktiv
    public ?float  $confidence;       // aus der Antwort extrahiert, geclampt [0,1] (GL-07)
    public int     $tokensIn;
    public int     $tokensOut;
    public string  $model;            // tatsächlich genutztes Modell (nach Fallback!)
    public string  $finishReason;     // 'STOP'|'MAX_TOKENS'|… (§3.6)
    public int     $callLogId;        // Pflicht-Rückgabe — Accept/Reject stempeln darauf (GL-07)
    public int     $elapsedMs;
}
```

**Verantwortung des Gateways (genau hier, nirgendwo sonst):** API-Key (serverseitig, nie im Frontend), Modell-Wahl per Tier (§2), Rate-Limit (§4), Backoff + Modell-Fallback + Degenerations-Schutz (§3), JSON-Bereinigung/-Validierung (§3.4), Audit-Write **inkl. Fehlerpfad** (§5), Prompt-Komposition in kanonischer Reihenfolge (§6). Die Fach-Services (GL-07-Lebenszyklus) bleiben transport-agnostisch.

> **✅ Plattform-Befund (platforms-core, 2026-06-11 — ändert die Bauweise, nicht das Interface):** Der Core hat bereits einen zentralen LLM-Service (`OpenAiService` hinter **`LLMProviderContract`**, `config/ai.php`, Default **Anthropic claude-sonnet-4-6**). Der `AiGatewayService` baut deshalb **keinen eigenen HTTP-Client und kein Key-Handling** — er delegiert den Transport an den Plattform-Provider und behält die Modul-Verantwortungen (Tiering, Retries, Audit, Komposition). Konkrete Modell-Strings (auch das gesamte gemini.rs-Vokabular dieser Spec) sind damit **Deployment-Config, nicht Spec** — die §3-Robustheits-Mechaniken werden gegen das Provider-Interface implementiert. Voice-Hüllen laufen über `core.semantic_layer` (`SemanticLayerResolver::resolveFor()`), Field-Hüllen + TASK_PROMPTs über die modul-eigene Prompt-Registry (GL-06 §6 Hybrid). **Offen (D3-Restfragen):** Embedding-Support des Providers (Ist: Gemini 768-dim — kritisch für GL-04-RAG + V-24), Vision/Multimodal (Track D), Team-Rate-Limits im Core.

## 2. Modell-Tiering (V-01)

Die Alt-App fährt ein Blanket-Modell (`gemini-flash-latest` für alles, `DEFAULT_MODEL` in `gemini.rs:23`). Das Ist-Log zeigt: **der Großteil der Calls sind Mechanik-Labels mit winzigem Output** — die brauchen kein Qualitätsmodell. Tiering ist Gateway-Config (`config/foodalchemist.php`), **pro Feature**, nicht in den Hüllen (GL-06 §6) und nicht im Lebenszyklus (GL-07 §6).

| Tier | Zweck | Modell-Klasse (konfigurierbar) | Merkmale |
|---|---|---|---|
| **A** | Qualität: Generatoren, lange Texte, Reviews, Compliance | flash-Klasse | Thinking aus, json_mode, V-02-Schutz bei langen Einzeltexten |
| **B** | Mechanik-Labels: Klassifikation, Normalisierung, Schätzwerte | flash-lite-Klasse (billig) | Output meist < 100 Tokens, striktes Schema, Vokabular-Validierung (GL-07) |
| **C** | Vision: Foto/PDF-Extraktion | flash-Klasse multimodal | inline_data ≤ ~20 MB, Wissenskontext bewusst LEER (GL-13 Inv. 7) |
| **D** | Reasoning (optional): agentische Tool-Loops | flash-Klasse, **Thinking AN** | `callWithTools`, temp 0.0, hohes Output-Ceiling (Ist: 8192) |

### 2.1 Zuordnung der TOP-Features (Ist-Token-Zahlen aus `ai_call_log`, 8.594 Calls)

| Feature (Ist) | Calls | Ø in / Ø out (Tokens) | Tier | Begründung aus dem Ist |
|---|---|---|---|---|
| `recipe_pairing_infer_bulk` | 1.381 | 1.876 / 737 | **A** | größter Kostenblock (2,6 M in / 1,0 M out); 12–25 belegte Pairings wählen ist Qualitätsarbeit, Fehlvorschläge kosten Kurator-Zeit |
| `recipe_sub_typ_infer_bulk` | 1.380 | 1.133 / **68** | **B** | reines Vokabular-Label; 1,56 M Input-Tokens für 93 k Output — Paradefall fürs Billig-Modell |
| `recipe_name_normalize` | 435 | 1.390 / **18** | **B** | Ø 18 Output-Tokens — String-Normalisierung nach §1, kein Reasoning |
| `recipe_zubereitung_infer` | 418 | 1.047 / 324 | **A** | langer Einzeltext, Degenerations-anfällig (V-02-Ursprung) |
| `recipe_description_infer` | 412 | 975 / 140 | **A** | kundennaher Fachtext (§8-Regelwerk), Qualität sichtbar |
| `recipe_sub_typ_infer` | 409 | 1.331 / 72 | **B** | wie bulk |
| `recipe_kategorie_classify` | 408 | 1.724 / 78 | **B** | Taxonomie-Pick aus Whitelist |
| `recipe_eigenschaften_infer` | 405 | 735 / 37 | **B** | 3 Schätz-Labels |
| `geschmacksrichtung_infer` | 401 | 655 / 64 | **B** | Enum-Pick (Auto-Apply-Ausnahme → GL-07 §4.3) |
| `recipe_pairing_infer` | 379 | **4.881** / 831 | **A** | schwerster Einzel-Call (Pairing-Grounding GL-13); Qualität entscheidet |
| `recipe_sektor_infer` | 368 | 1.011 / 364 | **B** | Eignungs-Matrix mit Begründungen — Label-Charakter überwiegt |
| `equipment_suggest` | 367 | 1.566 / 88 | **B** | Vokabular-Auswahl |
| `recipe_garverlust_infer` | 361 | 1.046 / 417 | **B** | Zahlen-Schätzung pro Zutat; Schema-Zwang statt teurem Modell |
| `recipe_niveau_infer` | 356 | 1.045 / 202 | **B** | wie Sektor |
| `recipe_fertigungstiefe_infer` | 351 | 895 / 80 | **B** | Enum-Pick |

**Weitere relevante Zuordnungen außerhalb der Top 15:** `recipe_gen_basis` (99 Calls, **Ø 16.200 in** / 850 out) und `recipe_gen_vk` (Ø 18.100 in) → **A** — die Generatoren tragen den größten Prompt (Hüllen + Wissen + Inventar-Grounding), hier zählt Prefix-Caching (§6). `recipe_gen_disambig` (74 Calls, Ø 17.650 in — agentischer Tool-Loop) → **D**. `recipe_extract_basis/vk` (multimodal) → **C**. `allergen_infer` (28 Calls) → **A trotz Label-Charakter: LMIV-Compliance**, Fehler sind Haftungsrisiko — bestes Modell + Review-Pflicht bleibt (GL-07). `recipe_review`, `foodbook_plan` (Ø 18.300 in), `marketing_text_generate`, `vk_wording_generate` → **A**. Alle GP-/LA-Label-Features (`gp_tag_infer`, `gp_domain_infer`, `gp_la_suggest`, `la_match_to_gp`, `term_la_rank`, `gp_stk_default_g_infer`, `gp_count_units_infer`, `speisen_klasse_classify`, `behaelter_suggest`, `regeneration_suggest`, `servier_vehikel_suggest`, `price_plausi_check`, `rollen_verteilung_infer`, `gp_anker_infer`, `recipe_anker_infer`) → **B**.

**Hochrechnung:** Tier B deckt damit ~70 % der Ist-Calls ab, die zusammen < 15 % der Output-Tokens erzeugen — das Einsparpotenzial liegt fast vollständig im Input-Preis der Masse-Labels, ohne Qualitätsrisiko bei den sichtbaren Texten.

## 3. Robustheit

Alle Mechanismen leben **im Gateway** — Fach-Code sieht nur `AiResult` oder eine typisierte Exception (V-06).

### 3.1 Backoff (Ist: `gemini.rs:41,629-691`)
Transiente Fehler = HTTP 429, 5xx, Timeout, Connect-Fehler. Retry-Treppe **1 s / 3 s / 10 s** (3 Versuche), danach §3.2. Rate-Limit (§4) greift VOR den Retries — kein Hämmern. Request-Timeout 60 s.

### 3.2 Modell-Fallback (Ist: `gemini.rs:23-29,646-660`)
Nach erschöpftem Backoff **einmaliger** Wechsel auf das Fallback-Modell der nächst-billigeren Klasse (Ist: flash → flash-lite), Backoff-Zähler reset, nochmal §3.1. Greift NICHT, wenn schon auf dem Fallback-Modell gestartet wurde. Begründung der Alt-App gilt weiter: ein schwächeres Ergebnis schlägt einen roten Abbruch — **aber** `AiResult.model` trägt immer das tatsächlich genutzte Modell, damit das Audit (§5) Fallback-Quoten sichtbar macht.

> **Caveat (adversarial verifiziert 2026-06-11):** Fallback (§3.2) und 4096-Floor (§3.5) gelten im Ist NUR im Text-/Multimodal-Pfad (`call_inner`). **`call_with_tools`** (agentischer Resolver, Tier D) hat KEINEN Modell-Fallback, nutzt fix 8192 Output-Tokens ohne Floor-Logik und lässt Thinking bewusst AN (`gemini.rs:343-346`). Ziel-Gateway: Fallback + Floor auch im Tool-Loop-Pfad vereinheitlichen; Thinking bleibt Tier-D-Ausnahme (§3.5).

### 3.3 Degenerations-Schutz generalisiert (V-02)
Ist-Befund (`commands.rs:13655-13676`): lange Einzeltext-Felder in JSON-Stringhülle (`zubereitung_md`) verleiten das Modell zu Phrasen-Wiederholung am Tail → kaputtes JSON. Ist-Lösung (nur dort): Re-Roll mit **steigender Temperatur 0.3 → 0.5 → 0.7**, erste valide Antwort gewinnt; P(3 Degenerationen in Folge) ≈ 0. **Ziel: im Gateway generalisieren** — Feature-Flag `degenerationRetry: true` für ALLE Features der Klasse „langes Einzeltext-Feld" (`zubereitung`, `plating`, `description`, `marketing_text`, `review`). Zweite Ist-Variante (Generator, `commands.rs:20766-20780`): bis zu 3 Versuche bei **strukturell unbrauchbarem** JSON (valides JSON, aber leeres Pflicht-Array `zutaten`) — als `structuralRetry(callable $isUsable)` ebenfalls generalisieren.

### 3.4 JSON-Validierung
1. **Schema-Zwang wo möglich:** `response_mime_type=application/json` ist gesetzt (Ist); im Ziel zusätzlich `responseSchema` (Gemini Structured Output) aus `AiRequest::$schema` für alle Tier-B-Features — eliminiert die halbe Fehlerklasse.
2. **Fence-Stripping trotzdem behalten** (Ist: `gemini.rs:748-786`, 7 Unit-Tests): Modelle umrahmen JSON gelegentlich mit Prosa/Fences und hängen Trailing-Müll an. Algorithmus: ab erstem `{`/`[` mit Tiefen-Zähler scannen (String-Literale + Escapes respektieren), am ERSTEN vollständigen Wert abschneiden. Unbalanciert (echte Truncation) → Rest zurückgeben, Parse-Fehler bleibt ehrlich. Die Rust-Testfälle (`gemini.rs:788-859`) wandern 1:1 als PHPUnit-Datasets in `09_TESTKATALOG`.
3. **Vokabular-Validierung + Gap-Surfacing** ist NICHT Gateway-Sache, sondern Lebenszyklus (GL-07 Inv. 6).

### 3.5 Token-Budgets
- Output: Floor `max(maxOutputTokens, 4096)` (Ist: `gemini.rs:613`) — enge Limits erzeugten abgeschnittenes JSON; ein hohes Ceiling kostet nichts (nur real erzeugte Tokens zählen).
- **Thinking standardmäßig AUS** (`thinkingBudget: 0`, Ist: `gemini.rs:133-149`): die Tasks sind constrained Extraktion/Auswahl mit Grounding; Thinking-Tokens zählen gegen das Output-Budget und brachen JSON mit EOF ab. Ausnahme Tier D (§2): Tool-Loops profitieren vom Reasoning, Thinking an.
- Input-Schätzung für Anzeige/Budget-Warnung: **~4 Zeichen/Token** (`ceil(chars/4)`, GL-06) — im Ziel durch echte Tokenizer-Zählung ersetzbar, hat keine Logik-Wirkung.

### 3.6 Finish-Reason (Ist: `gemini.rs:704-711`)
| finishReason | Behandlung |
|---|---|
| `STOP`, `MAX_TOKENS` | akzeptieren (MAX_TOKENS → Truncation fängt §3.4/2 ehrlich ab) |
| `SAFETY` | typisierte Exception `AiBlockedException::safety()` — kein Retry (deterministisch) |
| `RECITATION` | `AiBlockedException::recitation()` — kein Retry |
| sonstiges / candidates leer | `AiUnexpectedResponseException` — Audit-Zeile mit `error` (§5) |

## 4. Rate-Limit & Queue (V-15)

**Ist:** globaler Mutex-Throttle 1.000 ms zwischen Calls (`gemini.rs:40-78`; Paid Tier, ~60× konservativer als das echte Limit). Bulk-Läufe sind UI-blockierende Loops — genau das fällt weg.

**Ziel:** zentraler Rate-Limiter im Gateway (Redis-basiert, `RateLimiter::attempt` pro Modell-Klasse), Limits in der Gateway-Config. Zwei Ausführungspfade:

| | **Interaktiv (sync)** | **Bulk (Queue-Job)** |
|---|---|---|
| Wann | genau **1 Ziel-Datensatz** UND erwartete Laufzeit < ~15 s (1 Call, ggf. + Disambig) | **> 1 Ziel-Datensatz** ODER Tool-Loop über viele Bauteile ODER erwartete Laufzeit > 15 s |
| Beispiele | einzelnes `recipe_description_infer`, `gp_tag_infer` aus dem Editor | `*_bulk`-Features (Pairing 1.381 Items!), Seed-Nachklassifikation, Re-Embed |
| Mechanik | Livewire-Action → Service → Gateway; Spinner im Modal | `Jobs/AiBulkRunJob` mit **Fortschritt** (verarbeitet/gesamt, persistiert pro Item), **Resume** (bereits gelogte `target_id`s überspringen — Idempotenz über `ai_call_log`), **Abbruch** (Cancel-Flag, Job prüft pro Item) |
| Lebenszyklus | unverändert GL-07 | pro Item unverändert GL-07: Schreibpflicht + Override-First; **Bulk-Accept bleibt ein Accept pro Ziel-Datensatz**, keine Sammel-TX (GL-07 §6) |

Queue-Worker respektieren denselben zentralen Rate-Limiter wie Sync-Calls — ein Bulk-Lauf darf interaktive Nutzer drosseln, aber nie aushungern (separate Queue `foodalchemist-ai-bulk` mit niedrigerer Priorität). Wissens-Kontext pro Job-Batch cachen (Cross-Cutting ist konstant, GL-13 §6).

## 5. Audit & Kosten (V-09 / V-16)

**`foodalchemist_ai_call_log`** = Ist-Schema (GL-07 §2) **plus** Plattform-Felder:

```
id, ts, feature, layers_used (JSON [{key,semver}] — GL-06 Inv. 7),
knowledge_used (JSON [{slug,version}] — NEU, GL-13 §6: heute geht files_used verloren),
prompt_hash (SHA-256 — Dedup ohne Volltext-Leak), response_summary (≤200 Z.),
tokens_in, tokens_out, model (tatsächlich genutzt, nach Fallback §3.2), tier,
target_table, target_id, accepted_at, rejected_at, error, elapsed_ms,
team_id (NOT NULL — Verursacher), user_id (wer hat den Call ausgelöst)
```

**Pflichten (aus GL-07, hier verschärft):**
1. Jeder erfolgreiche Call schreibt VOR Rückgabe genau eine Zeile; `callLogId` geht im DTO mit.
2. **Auch der Fehlerpfad loggt** (`error`-Spalte befüllt) — Ist-Lücke: Err vor `log_ai_call` ließ die Spalte faktisch ungenutzt (GL-07 §4.3). Im Gateway als try/finally erzwungen.
3. **`accepted_at`-Stempel-Pflicht** in der generischen Accept-Action — drei Ist-Features stempeln nicht (sichtbar als 0-accepted in §2.1: `recipe_name_normalize`, `recipe_kategorie_classify`, `marketing_text_generate`-Verwandte).
4. Zwei Ist-Call-Sites ganz ohne Log (`TASK_PROMPT_TEMPLATE_FILL`-Loop; Anhang A) → im Ziel unmöglich, weil nur das Gateway Calls macht und immer loggt.

**Kosten-Auswertung pro Team (V-09):** View/Query auf `ai_call_log`: `SUM(tokens_in × preis_in[model] + tokens_out × preis_out[model]) GROUP BY team_id, feature, month`. Modell-Preistabelle in der Gateway-Config (versioniert, mit `gueltig_ab`). KI-Kosten-Dashboard im Sidebar-Bereich „KI" (`01_ARCHITEKTUR.md` §2). Zur Einordnung: das gesamte Ist-Volumen (19,1 Mio Tokens) liegt im niedrigen Euro-Bereich — das Dashboard dient Transparenz + Tiering-Kontrolle (Fallback-Quote, Tier-Drift), nicht Panik.

**Billables (V-16):** `config/foodalchemist.php` → `billables: KI-Aufrufe per_item` auf `ai_call_log` (Vorbild `planner.php`, `01_ARCHITEKTUR.md` §2). Abrechnungsbasis ist die Log-Zeile; optional gewichtet per Tier (A teurer als B).

## 6. Prompt-Komposition & Caching

Kanonische Reihenfolge (Ist-Verhalten GL-06/GL-07, fürs Ziel verbindlich):

```
systemInstruction:
  1. Hüllen-Block (GL-06: global → module → customer → skill → field, "\n\n"-join)
  2. [optionaler Ad-hoc-System-Prompt]            ← compose_system_prompt, GL-06 GT-06-9
User-Turn:
  3. Wissens-Blöcke (GL-13: Cross-Cutting → Domains → Pairing)   ← stabil pro Feature-Klasse
  4. Task-Prompt (TASK_PROMPT_*, Anhang A)                        ← statisch pro Feature
  5. Daten-Kontext (GP-Summary, Zutaten, Kandidaten-Listen …)     ← volatil, IMMER zuletzt
  6. [multimodale Attachments]                                    ← nach dem Text-Part
```

**Caching-Überlegung:** Gemini (wie alle Provider) cached Prompt-Präfixe implizit — **stabile Blöcke müssen vorn stehen, volatile hinten.** Die Ist-App hält das bei den Hüllen ein, mischt aber teils Task-Prompt vor Wissen; im Ziel gilt strikt 1→6. Effekt ist bei den Generatoren am größten (Ø 16–18 k Input-Tokens, davon > 80 % stabiler Hüllen-/Wissens-/Regelwerk-Prefix). Vokabular-Whitelists (Sub-Typen, Taxonomie, Anker) gehören zum stabilen Teil von Block 4 (per Platzhalter eingesetzt, ändern sich selten). `prompt_hash` (§5) macht Cache-Hits messbar (identischer Hash = identischer Prompt).

**Hüllen sind modellagnostisch** (GL-06 §6): Tier-Wahl steht NIE im Prompt-Text, nur in der Gateway-Config.

---

## Anhang A — Prompt-Inventar (42 `TASK_PROMPT_*`-Konstanten aus `commands.rs`)

Vollständig per `grep '^const TASK_PROMPT_'` extrahiert (Stand 2026-06-10). Diese Konstanten sind der **Seed der Task-Prompts** im Ziel (Migration → Prompt-Registry des Gateways, versioniert analog Hüllen). Feature = `ai_call_log`-Label.

| # | Konstante | Zweck (1 Zeile) | Feature(s) | Tier |
|---|---|---|---|---|
| 1 | `TASK_PROMPT_ROLLEN_VERTEILUNG` | Komponenten-Rollen (Haupt/Beilage/Sauce …) der Zutaten eines Verkaufsrezepts verteilen | `rollen_verteilung_infer` | B |
| 2 | `TASK_PROMPT_TEMPLATE_FILL` | Platzhalter eines Grundrezept-Templates für konkrete Variante füllen (Tool-Loop) | — **kein Log im Ist (Audit-Lücke, §5)** | D |
| 3 | `TASK_PROMPT_GP_SUGGEST` | Lieferanten-Item zu Grundprodukt klassifizieren (tentative GP-Anlage) | `gp_suggest`, `la_match_to_gp`, `la_bulk_match_to_gp` | B |
| 4 | `TASK_PROMPT_ALLERGEN_INFER` | 14 EU-Allergene (LMIV Anhang II) für ein GP ableiten | `allergen_infer` | **A** (Compliance) |
| 5 | `TASK_PROMPT_TAG_INFER` | 11 intrinsische Eigenschafts-Tags für ein GP ableiten | `gp_tag_infer` | B |
| 6 | `TASK_PROMPT_DOMAIN_INFER` | GP einer Wissens-Domain zuordnen | `gp_domain_infer` | B |
| 7 | `TASK_PROMPT_SEKTOR_INFER` | Eignung eines Rezepts für Verpflegungs-Sektoren beurteilen | `recipe_sektor_infer` | B |
| 8 | `TASK_PROMPT_NIVEAU_INFER` | Eignung eines Rezepts für Niveau-Stufen beurteilen | `recipe_niveau_infer` | B |
| 9 | `TASK_PROMPT_GARVERLUST_INFER` | Garverlust in % pro Zutat schätzen | `recipe_garverlust_infer` | B |
| 10 | `TASK_PROMPT_STK_DEFAULT_G` | Stück-Durchschnittsgewicht eines GP schätzen | `gp_stk_default_g_infer` | B |
| 11 | `TASK_PROMPT_GP_COUNT_UNITS` | Natürliche Zähl-Einheiten + Durchschnittsgewichte eines GP listen | `gp_count_units_infer` | B |
| 12 | `TASK_PROMPT_SUB_REZEPT_TYP` | Rezept zu Sub-Rezept-Typ aus Vokabular klassifizieren | `recipe_sub_typ_infer(_bulk)` | B |
| 13 | `TASK_PROMPT_FERTIGUNGSTIEFE` | Fertigungstiefe (Rohware vs. Convenience) klassifizieren | `recipe_fertigungstiefe_infer` | B |
| 14 | `TASK_PROMPT_RECIPE_DESCRIPTION` | Nüchtern-fachliche Rezept-Beschreibung nach Regelwerk §8 schreiben | `recipe_description_infer` | A |
| 15 | `TASK_PROMPT_ZUBEREITUNG` | Schritt-für-Schritt-Zubereitung für Produktionsrezept schreiben | `recipe_zubereitung_infer` | A (+V-02) |
| 16 | `TASK_PROMPT_PLATING` | Hybrid-Plating-Anweisung für Verkaufsrezept schreiben | `recipe_zubereitung_infer` (VK-Pfad) | A (+V-02) |
| 17 | `TASK_PROMPT_RECIPE_NAME` | Rezeptnamen nach Regelwerk §1 normalisieren | `recipe_name_normalize` | B |
| 18 | `TASK_PROMPT_RECIPE_NAME_VK` | Verkaufsrezept-Namen normalisieren | `recipe_name_normalize` (VK-Pfad) | B |
| 19 | `TASK_PROMPT_EIGENSCHAFTEN` | Drei Rezept-Eigenschaften schätzen | `recipe_eigenschaften_infer` | B |
| 20 | `TASK_PROMPT_GESCHMACK` | Grobe Geschmacksrichtung für Menüplanung bestimmen | `geschmacksrichtung_infer` (Auto-Apply-Ausnahme GL-07 §4.3) | B |
| 21 | `TASK_PROMPT_KATEGORIE` | Basisrezept in WaWi-Taxonomie einordnen | `recipe_kategorie_classify` | B |
| 22 | `TASK_PROMPT_SPEISEN_KLASSE` | Verkaufsrezept in Speisen-Klasse (Hauptgruppe × Diätform) einordnen | `speisen_klasse_classify` | B |
| 23 | `TASK_PROMPT_MARKETING` | Marketing-Text für Foodbook-Eintrag generieren | `marketing_text_generate` | A |
| 24 | `TASK_PROMPT_VK_WORDING` | Kanonischen Marketing-Namen (VK-Wording-Standard) generieren | `vk_wording_generate` | A |
| 25 | `TASK_PROMPT_BEHAELTER` | Behälter (warm/kalt) + Anzahl für Catering-VK vorschlagen | `behaelter_suggest` | B |
| 26 | `TASK_PROMPT_REGENERATION` | Regenerations-Programm (Gerät/Temperatur/Dauer/KT) vorschlagen | `regeneration_suggest` | B |
| 27 | `TASK_PROMPT_REVIEW_VK` | Verkaufsrezept auf Verkaufs-Tauglichkeit prüfen (Copilot-Review) | `recipe_review` (VK-Pfad) | A |
| 28 | `TASK_PROMPT_REVIEW` | Produktionsrezept auf Plausibilität prüfen (Sous-Chef-Review) | `recipe_review` | A |
| 29 | `TASK_PROMPT_PAIRING` | 12–25 belegte Flavor-Pairing-Partner vorschlagen | `recipe_pairing_infer(_bulk)` | A |
| 30 | `TASK_PROMPT_GP_ANKER` | Kern-Anker (Aroma-Identität) eines GP bestimmen | `gp_anker_infer` | B |
| 31 | `TASK_PROMPT_RECIPE_ANKER` | Kern-Anker (1–5) eines Rezepts bestimmen | `recipe_anker_infer` | B |
| 32 | `TASK_PROMPT_GP_LA_SUGGEST` | Unzugeordnete LA-Kandidaten einem GP zuordnen | `gp_la_suggest`, `phantom_matrix_match` | B |
| 33 | `TASK_PROMPT_TERM_LA_RANK` | LA-Kandidaten als GP-Basis zu einem Produktbegriff ranken | `term_la_rank` | B |
| 34 | `TASK_PROMPT_PRICE_PLAUSI` | Auffälligen Lieferanten-Preis auf Plausibilität prüfen | `price_plausi_check` | B |
| 35 | `TASK_PROMPT_RECIPE_GEN_BASIS` | Basisrezept für die Produktionsküche generieren | `recipe_gen_basis` | A |
| 36 | `TASK_PROMPT_RECIPE_GEN_VK` | Verkaufsrezept fürs Catering-Foodbook generieren | `recipe_gen_vk` | A |
| 37 | `TASK_PROMPT_FOODBOOK_PLAN` | Menü-/Sortiments-Planung für ein Foodbook | `foodbook_plan` | A (Phase 2 ⚠D5) |
| 38 | `TASK_PROMPT_AGENTIC_RESOLVER` | Agentischer Matching-Entscheider (Zutat→GP/Sub-Rezept, Tool-Loop) | `recipe_gen_disambig`, `recipe_extract_disambig` | **D** (Thinking an) |
| 39 | `TASK_PROMPT_DISAMBIG` | Zutaten-Zuordnungs-Assistent (Alt-Pfad) | — **ungenutzt im Ist (toter Code, vom agentischen Resolver abgelöst) — nicht porten** | — |
| 40 | `TASK_PROMPT_RECIPE_EXTRACT` | Rezept TREU aus Foto/PDF/Text extrahieren (ohne Wissens-Anreicherung, GL-13 Inv. 7) | `recipe_extract_basis`, `recipe_extract_vk` | **C** (multimodal) |
| 41 | `TASK_PROMPT_EQUIPMENT` | Equipment-Set für die Produktion eines Basisrezepts vorschlagen | `equipment_suggest` | B |
| 42 | `TASK_PROMPT_SERVIER_VEHIKEL` | Servier-Vehikel (Teller/Schale/Verpackung) vorschlagen | `servier_vehikel_suggest` | B |

**Features ohne `TASK_PROMPT_*`-Konstante** (Inline-Prompts im Ist, beim Port in die Prompt-Registry heben): `chat_message`, `culinary_coherence_judge`, `plate_suggester`, `gp_rolle_infer`. Embeddings (`gemini-embedding-001`, 768 Dims, L2-normalisiert, RETRIEVAL_QUERY/DOCUMENT — `gemini.rs:456-558`) laufen ebenfalls über das Gateway, sind aber kein Prompt-Feature (kein `ai_call_log`-Zwang; eigene Spur, vgl. Re-Embed-Jobs `01_ARCHITEKTUR.md` §2).
