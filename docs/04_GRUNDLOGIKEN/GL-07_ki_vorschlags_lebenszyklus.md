---
typ: Grundlogik-Spec
gl_id: GL-07
stand: 2026-06-10
status: ausgearbeitet
---

# GL-07 — KI-Vorschlags-Lebenszyklus (propose / accept / reject / clear)

> **Normative Quellen:** Lineage-Konvention `<feld>_quelle` / `<feld>_ai_confidence` / `<feld>_ai_begruendung`; Override-First (manual schlägt KI — KI überschreibt NIE `quelle='manual'`); `ai_call_log`-Schreibpflicht
> **Implementierungs-Quelle (Ist):** `src-tauri/src/commands.rs` — ~90 Commands im Muster `ai_*` / `accept_*` / `reject_*` / `clear_*`. EIN Pattern, hier einmal spezifiziert. Belege: `log_ai_call` (9353-9398), `accept_gp_tags` (11154-11233), `accept_gp_domain` (11507+), `accept_stk_default_g` (12797-12838), `accept_gp_ankers` (16049-16082), `accept_marketing_text` (14559-14568), `ai_suggest_geschmacksrichtung` (14047-14131), `ai_infer_gp_ankers` (15888-16046, Gap-Surfacing)

## 1. Zweck & Quellen

DAS Grundmuster aller KI-Features: **KI schlägt vor, der User entscheidet, erst der Accept schreibt.** Jeder Fachwert, der von KI stammen kann, trägt Lineage-Spalten; manuelle Pflege gewinnt immer gegen KI; jeder LLM-Call hinterlässt eine Audit-Zeile. Der Laravel-Dev implementiert dieses Pattern EINMAL generisch (Service + Trait/Action-Set) und instanziiert es pro Feature — nicht 90× Copy-Paste.

**Vier Phasen:**

1. **Propose** (`ai_*`): Kontext sammeln (DB-Felder + Hüllen GL-06 + ggf. Wissen GL-13) → LLM-Call → Antwort gegen Vokabular validieren → `ai_call_log`-Zeile schreiben → Vorschlags-DTO `{werte, confidence, begruendung, call_log_id, ggf. unknown_slugs}` ans Frontend. **Keine Persistenz des Fachwerts** (Ausnahmen → §4.3).
2. **Accept** (`accept_*`): User bestätigt (ggf. mit Edits) im Review-Modal → Override-First-Check → Fachwert + Lineage schreiben → `ai_call_log.accepted_at` stempeln → ggf. Folge-Aggregationen (Propagate-Down, z.B. Rezept-Recompute).
3. **Reject** (`reject_*`): NUR `ai_call_log.rejected_at` stempeln. Fachdaten bleiben unberührt.
4. **Clear** (`clear_*`): Fachwert + komplette Lineage auf NULL; danach darf KI wieder vorschlagen oder der User manuell pflegen.

**Ist-Daten:** `ai_call_log` enthält 8.594 Zeilen (Korpus-Snapshot 02_DATENMODELL: 7.903 — wächst täglich). Top-Features: `recipe_pairing_infer_bulk` (1.381, davon 1.254 accepted), `recipe_sub_typ_infer_bulk` (1.380), `recipe_zubereitung_infer` (418/392 acc), `gp_suggest` (46/12 acc/23 rej).

## 2. Eingaben / Ausgaben / Invarianten

**Lineage-Spalten (Konvention pro KI-fähigem Feld `<feld>`):**

| Spalte | Typ | Semantik |
|---|---|---|
| `<feld>_quelle` | TEXT/Enum | `'manual'` \| `'ki'` \| NULL. **Ist-Vokabular uneinheitlich:** `wawi_gp_v2` nutzt `'ai_inferred'`, `recipes` nutzt `'ki'`, Anker-Mappings zusätzlich `'auto_slug_match'`. **Ziel: Enum `manual|ki|auto`**, Migration mappt `ai_inferred→ki`, `auto_slug_match→auto`. |
| `<feld>_ai_confidence` | REAL | 0.0–1.0, beim Schreiben IMMER `clamp(0,1)` (z.B. `commands.rs:11158`). NULL bei manual. |
| `<feld>_ai_begruendung` | TEXT | KI-Begründung fürs Review-Modal (separat vom `ai_call_log`-Audit, `commands.rs:10660`). NULL bei manual. |
| `<feld>_aggregiert_am` | TEXT | Zeitstempel des letzten Schreibens (nur bei einigen Feldern, z.B. Tags/Domain). |

**Feature-Gruppen mit Lineage-Spalten (Ist-Bestand per `PRAGMA table_info`):**

| Tabelle (Ist → Ziel) | Feld-Gruppen |
|---|---|
| `recipes` → `foodalchemist_recipes` | `sub_rezept_typ` (quelle/conf/begr), `geschmacksrichtung` (quelle/conf), `marketing_text` (quelle/conf) |
| `wawi_gp_v2` → `foodalchemist_gps` | `allergene` (quelle/conf), `tag_*` (quelle/conf/begr — 1 Lineage-Satz für alle 11 Tags), `food_domain` (quelle/conf/begr), `stk_default_g` (quelle/conf/begr) |
| Mapping-Tabellen (zeilenbasierte Lineage: `quelle`/`ai_confidence`/`ai_begruendung` PRO ZEILE) | `gp_anker_mapping`, `recipe_anker_mapping`, `recipe_sektor_eignung`, `recipe_niveau_eignung`, `recipe_pairings` |
| `kombination_block` / `foodbook_block` | `header_quelle` (Phase 2 ⚠D5) |

**`ai_call_log` (Ist-Schema → `foodalchemist_ai_call_log` + `user_id` + `team_id`):**

```
id, ts, feature (z.B. 'gp_suggest', 'recipe_zubereitung_infer'),
layers_used (JSON [{key, semver}] — Hüllen-Audit aus GL-06),
prompt_hash (SHA-256 hex des vollen Prompts — Dedup ohne Volltext-Leak),
response_summary (max 200 Zeichen), tokens_in, tokens_out, model,
target_table, target_id, accepted_at, rejected_at, error, elapsed_ms
```

**Invarianten:**

1. **Schreibpflicht:** Jeder erfolgreiche LLM-Call schreibt VOR der Rückgabe ans Frontend genau eine `ai_call_log`-Zeile (`log_ai_call`, `commands.rs:9353-9398`) und gibt deren `call_log_id` im DTO mit — sonst können Accept/Reject nicht stempeln.
2. **Override-First:** Steht `<feld>_quelle = 'manual'`, verweigert `accept_*` mit Fehler („…manuell gepflegt … bitte erst Reset"); der Weg zurück führt NUR über `clear_*` (User-bewusst). Zeilenbasierte Features (Anker) schützen stattdessen pro Zeile: `WHERE quelle != 'manual'` (`commands.rs:16071`), manuelle Zeilen überleben.
3. **Vorschlag ≠ Schreiben:** `ai_*` persistiert den Fachwert NICHT (dokumentierte Ist-Ausnahmen → §4.3; im Ziel abschaffen oder explizit als „auto-apply mit manual-Guard" deklarieren).
4. **Reject ist zerstörungsfrei:** ausschließlich `rejected_at`-Stempel.
5. **Confidence-Clamp:** jeder eingehende Confidence-Wert wird auf [0,1] geklemmt.
6. **Gap-Surfacing statt Erzwingen:** Liefert die KI Werte außerhalb des erlaubten Vokabulars, werden sie NICHT geschrieben und NICHT still verworfen, sondern als Lücke gemeldet: DTO-Feld `unknown_slugs` + `response_summary`-Vermerk `"fehlende anker: …"` (`commands.rs:16002-16034`). Skalare Enums (z.B. Geschmacksrichtung) mappen Unbekanntes auf `None` (`commands.rs:14092-14098`). Neue Taxonomie-Werte (Rezept-Kategorie) sind als VORSCHLAG erlaubt, Frontend warnt (`commands.rs:14145ff`).
7. **Propagate-Down:** Accepts, die aggregierte Werte beeinflussen (GP-Tags, Allergene), stoßen den Rezept-Recompute transaktional mit an (`commands.rs:11215`, → GL-01/GL-02).
8. Accept + `ai_call_log`-Stempel + Propagation laufen in EINER Transaktion.

## 3. Pseudocode

```
# ── PROPOSE ───────────────────────────────────────────────────────────────
function ai_propose(feature, target_id, opts):
    ctx      = lade_fachkontext(target_id)              # DB-Felder, Zutaten, Vokabular-Listen
    huellen  = resolve_for_call(module, …)              # GL-06
    wissen   = knowledge_context(feature, ctx)          # GL-13 (featureabhängig, oft leer)
    prompt   = task_prompt(feature) + wissen + ctx      # Hüllen separat als systemInstruction
    result   = llm.call(huellen.combined_block, prompt, json_mode, temp≈0.0–0.2)

    parsed   = json_parse(result.text)                  # Fehler -> Err (Ist: OHNE Log, s. §6)
    werte, unknown = validiere_gegen_vokabular(parsed)  # Unbekanntes -> unknown[], nie schreiben
    confidence = clamp(parsed.confidence, 0, 1)

    call_log_id = INSERT ai_call_log(feature, layers_used=[{key,semver}…],
                    prompt_hash=sha256(prompt), response_summary=trunc(summary,200),
                    tokens_in, tokens_out, model, target_table, target_id, elapsed_ms)
    return { werte, confidence, begruendung, unknown_slugs: unknown, call_log_id }

# ── ACCEPT (skalares Feld) ────────────────────────────────────────────────
function accept(feature, input):                        # input = User-reviewte Werte + call_log_id
    BEGIN TX
    if SELECT <feld>_quelle == 'manual': ROLLBACK; return Err("manuell gepflegt — erst Reset")
    UPDATE ziel SET <feld>=input.werte, <feld>_quelle='ki',
                    <feld>_ai_confidence=clamp(input.confidence,0,1),
                    <feld>_ai_begruendung=input.begruendung,
                    <feld>_aggregiert_am=now()
    propagation_falls_noetig()                          # z.B. Rezept-Recompute
    UPDATE ai_call_log SET accepted_at=now(), target_table, target_id WHERE id=input.call_log_id
    COMMIT

# ── ACCEPT (zeilenbasiert, z.B. GP-Anker — commands.rs:16049-16082) ──────
function accept_rows(input):
    DELETE FROM mapping WHERE ziel_id=? AND quelle IN ('ki','auto')   # KI-Zeilen ersetzen
    budget = CAP - count(verbleibende zeilen)                          # manuelle zählen ins Budget
    for row in input.rows while budget > 0:
        INSERT … quelle='ki'
          ON CONFLICT DO UPDATE … WHERE bestehende.quelle != 'manual'  # manual unantastbar
    UPDATE ai_call_log SET accepted_at=now() …

# ── REJECT ────────────────────────────────────────────────────────────────
function reject(call_log_id): UPDATE ai_call_log SET rejected_at=now() WHERE id=call_log_id

# ── CLEAR ─────────────────────────────────────────────────────────────────
function clear(target_id):
    UPDATE ziel SET <feld>=NULL, <feld>_quelle=NULL, _ai_confidence=NULL, _ai_begruendung=NULL
    propagation_falls_noetig()
# danach: KI darf neu vorschlagen ODER User pflegt manuell (quelle='manual')

# ── MANUELLER PFAD ────────────────────────────────────────────────────────
function set_manual(target_id, wert):                  # z.B. commands.rs:11753-11755
    UPSERT … quelle='manual', ai_confidence=NULL, ai_begruendung=NULL   # überschreibt KI immer
```

## 4. Entscheidungstabellen

### 4.1 Quelle × Aktion — wer darf was überschreiben (skalare Felder)

| Ist-Zustand `<feld>_quelle` | `ai_*` (Propose) | `accept_*` | `reject_*` | `clear_*` | manueller Edit |
|---|---|---|---|---|---|
| NULL (ungepflegt) | ✅ Vorschlag | ✅ schreibt, quelle=`ki` | ✅ nur Stempel | ✅ No-op | ✅ quelle=`manual` |
| `ki` | ✅ Vorschlag | ✅ überschreibt KI-Wert (Re-Run erlaubt) | ✅ Wert bleibt | ✅ alles → NULL | ✅ quelle=`manual` (manual schlägt KI) |
| `manual` | ✅ Vorschlag erlaubt (Anzeige) | ❌ **verweigert mit Fehler** — KI überschreibt NIE manual | ✅ | ✅ setzt AUCH manual zurück (bewusster User-Klick) | ✅ |

### 4.2 Quelle × Aktion — zeilenbasierte Mappings (Anker, Sektor/Niveau-Eignung)

| Zeilen-`quelle` | accept (KI-Batch) | clear | manueller Toggle |
|---|---|---|---|
| `ki` / `auto` | wird gelöscht + durch neue KI-Zeilen ersetzt | gelöscht | wird zu `manual` hochgestuft |
| `manual` | **überlebt** (DELETE filtert sie aus; ON-CONFLICT-UPDATE mit `WHERE quelle != 'manual'`); zählt ins Cap-Budget (GP: max 3 Kern-Anker, Rezept: max 5) | gelöscht (clear = Voll-Reset) | Update OK |

### 4.3 Dokumentierte Ist-Abweichungen vom Pattern (beim Rewrite entscheiden)

| Abweichung | Beleg | Ziel-Empfehlung |
|---|---|---|
| `ai_suggest_geschmacksrichtung` **persistiert direkt** im Propose (quelle=`ki`), guarded mit `WHERE … quelle != 'manual'`; es existiert kein Accept (Stats: 401 Calls / 0 accepted) | `commands.rs:14108-14114` | Entweder ins Standard-Pattern heben ODER als „auto-apply"-Featureklasse deklarieren (Override-First-Guard ist vorhanden — Pflicht) |
| Einige `accept_*` stempeln `accepted_at` NICHT (`accept_marketing_text`, `accept_recipe_name`, `accept_recipe_kategorie`) → Audit-Lücke, sichtbar als 0-accepted-Features in den Stats | `commands.rs:14559-14568` (kein `ai_call_log`-Update im Body) | Ziel: Stempel-Pflicht in der generischen Accept-Action erzwingen |
| Fehlgeschlagene LLM-Calls (Netz/JSON-Parse) returnen Err VOR `log_ai_call` → `error`-Spalte faktisch ungenutzt | z.B. `commands.rs:14090` (`?` vor Log) | Ziel: Log-Pflicht AUCH im Fehlerpfad (try/finally im Gateway), `error` befüllen |
| Lineage-Vokabular-Drift `ai_inferred` vs `ki` vs `auto_slug_match` | PRAGMA-Befund §2 | Enum `manual\|ki\|auto` + Migrations-Mapping |

## 5. Golden-Testfälle

| # | Setup / Aktion | Expected (exakt aus Code verifiziert) |
|---|---|---|
| GT-07-1 | GP mit `tag_quelle='manual'`; `accept_gp_tags(call_log_id=X)` | **Fehler** „Tags sind manuell gepflegt … bitte erst über 'KI-Tags löschen' zurücksetzen" (`commands.rs:11171-11175`). Tags unverändert, `ai_call_log.accepted_at` für X bleibt NULL (Check liegt VOR jedem Write, TX). |
| GT-07-2 | GP mit `tag_quelle=NULL`; `accept_gp_tags` mit 11 Tag-Werten, confidence 0.83 | Tags geschrieben, `tag_quelle='ai_inferred'` (Ziel: `ki`), `tag_ai_confidence=0.83`, `tag_aggregiert_am` gesetzt; alle Rezepte mit diesem GP recomputed; `accepted_at` + `target_table='wawi_gp_v2'` + `target_id` am Log. Alles in EINER TX. |
| GT-07-3 | `reject_gp_tags(call_log_id)` | Ausschließlich `rejected_at` gesetzt (`commands.rs:11236-11244`); keine Fachdaten-Änderung. |
| GT-07-4 | `accept_*` mit `confidence=1.7` | Persistiert wird `1.0` (Clamp, `commands.rs:11158`). Analog `-0.3` → `0.0`. |
| GT-07-5 | GP hat 2 Anker-Zeilen `quelle='manual'` + 1 `quelle='ai_inferred'`; `accept_gp_ankers` mit 3 neuen KI-Ankern | Alte `ai_inferred`-Zeile gelöscht; Budget = 3 − 2 manuelle = **1** → nur der erste neue Anker wird geschrieben; beide manuellen Zeilen unverändert (`commands.rs:16051-16075`). Rückgabe `written=1`. |
| GT-07-6 | Rezept mit `geschmacksrichtung_quelle='manual'`; `ai_suggest_geschmacksrichtung` liefert `herzhaft` | DTO enthält Vorschlag, aber DB unverändert (`WHERE … != 'manual'` matcht 0 Zeilen, `commands.rs:14112`); Log-Zeile `feature='geschmacksrichtung_infer'` entsteht trotzdem. |
| GT-07-7 | `ai_infer_gp_ankers`: Gemini antwortet mit Slug `"zitronengras-thai"`, der nicht im 764er-Vokabular ist | Kein Insert; `unknown_slugs=["zitronengras-thai"]` im DTO; `response_summary` endet auf `"fehlende anker: zitronengras-thai"` (`commands.rs:16002-16034`). |
| GT-07-8 | `clear_gp_tags(gp_v2_id)` | Alle 11 Tags + `tag_quelle` + `tag_ai_confidence` + `tag_ai_begruendung` → NULL; Rezepte recomputed (`commands.rs:11249ff`). Danach `accept_gp_tags` wieder erlaubt (GT-07-2-Pfad). |
| GT-07-9 | Manueller Toggle `set_recipe_sektor` auf Zeile mit `quelle='ki'` | Upsert auf `quelle='manual'`, `ai_confidence=NULL`, `ai_begruendung=NULL` (`commands.rs:11753-11755`) — manual schlägt KI, rückstandsfrei. |
| GT-07-10 | Jeder Propose-Call (Erfolgsfall) | Genau 1 neue `ai_call_log`-Zeile: `prompt_hash` = SHA-256-Hex des vollen Prompts, `response_summary` ≤ 200 Zeichen, `layers_used` = JSON der Hüllen `[{key,semver}]`, `tokens_in/out` + `model` + `elapsed_ms` befüllt; `id` == `call_log_id` im DTO (`commands.rs:9353-9398`). |
| GT-07-11 | `accept_stk_default_g` mit `stk_default_g=NULL` | `stk_default_g_quelle` wird NULL (CASE WHEN — kein Lineage-Rest ohne Wert, `commands.rs:12821-12824`); `accepted_at` trotzdem gestempelt. |

## 6. Offene Weichen & Verbesserungen

- **⚠D3:** Der gesamte Lebenszyklus läuft im Ziel über den `AiGatewayContract` (08_ENTSCHEIDUNGEN D3) — `foodalchemist_ai_call_log` bekommt `user_id` + `team_id` (wer hat akzeptiert? Kosten pro Team, V-09/V-16). Ob die Plattform einen zentralen Call-Log stellt: vom Plattform-Dev zu bestätigen; Arbeits-Annahme: Modul-eigene Tabelle.
- **V-01 (Tiering):** `feature` → Modell-Tier-Mapping (A Qualität / B Mechanik-Labels / C Vision) gehört in die Gateway-Konfig; der Lebenszyklus ist tier-agnostisch.
- **V-02 (Degenerations-Schutz):** Steigende-Temperatur-Retry bei degenerierten langen Texten (heute nur `zubereitung`) im Gateway generalisieren — greift in der Propose-Phase, ändert das Pattern nicht.
- **V-15 (Queue-Bulk):** Die `*_bulk`-Varianten (heute UI-blockierende Loops, z.B. `recipe_pairing_infer_bulk`) werden Laravel-Queue-Jobs mit Fortschritt + Resume; pro Item gelten unverändert Schreibpflicht + Override-First. Bulk-Accept bleibt ein Accept pro Ziel-Datensatz (keine Sammel-TX über tausende Zeilen).
- **Verbesserungen aus 4.3 (Ziel-Pflicht):** (a) `accepted_at`-Stempel in der generischen Accept-Action erzwingen; (b) Fehlerpfad-Logging mit `error`-Spalte; (c) Lineage-Enum vereinheitlichen; (d) Auto-Persist-Ausnahmen explizit als Featureklasse deklarieren oder abschaffen.
