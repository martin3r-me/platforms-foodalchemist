---
typ: Grundlogik-Spec
gl_id: GL-13
stand: 2026-06-11
status: ausgearbeitet (⚠D4 entschieden 2026-06-11)
---

# GL-13 — Wissenskontext-Beschaffung für KI-Calls

> **Normative Quellen:** 07_WISSEN-Welt des Vaults (Cross-Cutting-Dateien, Food-Domains, Pairing-Doku); Token-Disziplin („mehr Kontext ist nicht besser")
> **Implementierungs-Quelle (Ist):** `src-tauri/src/vault_context.rs` (komplett, 710 Zeilen — Disk-Reads aus dem Obsidian-Vault) + Aufrufer in `commands.rs` → **Ziel: `foodalchemist_knowledge_*`-Tabellen ⚠D4**

## 1. Zweck & Quellen

KI-Generatoren (Rezept-Erzeugung, Menü-Planung, Pairing-/Anker-Inferenz) sollen nicht als „mechanische Token-Matcher", sondern als **Catering-Souschef** antworten. Dafür wird pro Call kuratiertes Fachwissen als Kontext-Block injiziert — heute Markdown-Dateien von Disk, im Ziel versionierte Datensätze in Modul-Tabellen (⚠D4, Arbeits-Annahme (a) aus 08_ENTSCHEIDUNGEN).

| Baustein | Ist-Implementierung |
|---|---|
| Always-Load-Liste (7 Cross-Cutting-Dateien) | `vault_context.rs:21-29` |
| Trunkierungs-Budgets | `vault_context.rs:31-33` (Cross-Cutting 4.000 / Domain 6.000 Zeichen, `DOMAIN_TOP_K=4`) |
| Hauptzutat→Domain-Mapping (258 Slugs, hartkodiert (verifiziert 2026-06-11)) | `vault_context.rs:39-322` (`HAUPTZUTAT_TO_DOMAIN`) |
| Tokenizer (Umlaut-Auflösung, ≥3 Zeichen) | `vault_context.rs:343-362` |
| Datei-Read mit Kürzungs-Marker | `vault_context.rs:379-387` (`read_truncated`) |
| Haupt-Loader | `vault_context.rs:544-646` (`load_vault_context`) |
| Pairing-Block (kompakte Partner-Listen, Stil-Filter) | `vault_context.rs:399-540` (`extract_pairing_names`, `load_pairing_block`) |
| Pairing-Doku-Grounding einzelner Features | `vault_context.rs:374-377` (`read_vault_file_truncated`); Aufrufer `commands.rs:15535-15565` (Pairings, 1.200 Z./Datei, max 5) und `commands.rs:15928-15945` (GP-Anker, 1.400 Z./Datei, max 3) |
| Pfad-Quelle der Pairing-Dokus | `vocab_pairing_anker.file_path` (764 Anker); Food-Domains: `vocab_food_domain.vault_file` (im Datenmodell-Doc als `md_path` bezeichnet — reale Spalte heißt `vault_file`) |

**Wichtig fürs Verständnis:** Die Hüllen (GL-06) liefern *Verhalten* (Ton, Heuristik, Negativ-Raum) als systemInstruction; GL-13 liefert *Fakten-Wissen* als Teil des User-Prompts. Beides ist additiv, nie redundant.

## 2. Eingaben / Ausgaben / Invarianten

**Eingaben (Ist):** Freitext-`beschreibung` (Generator-Brief bzw. Rezept-/GP-Metadaten), optional `kompositions_stil` (`klassisch` | `kreativ` | `gewagt`), für Grounding-Features die `hauptzutat_slug`s der beteiligten GPs.

**Ausgaben:**
- `VaultContext { combined_block, files_used[], total_chars }` — Block beginnt mit Header `# VAULT-WISSEN (…)`, Sektionen `## CROSS_CUTTING: <datei>` und `## DOMAIN: <stem>`, getrennt durch `\n\n---\n\n` (`vault_context.rs:632-638`).
- Pairing-Block: `# FLAVOR-PAIRING (…)` + je Anker eine Zeile `- <slug>: PartnerA · PartnerB · …` (max 28 Partner, max 3 Anker; `vault_context.rs:535-539`).
- Grounding-Block: `### Pairing-Doku: <slug>` + getrimmter Dateiinhalt.

**Invarianten:**

1. **Always-Load:** Die 7 Cross-Cutting-Wissenseinheiten (`Substitutionen`, `Saisonkalender`, `Synonyme`, `Sauce_Mutterstrukturen`, `Mengen_Defaults`, `Techniken`, `Bruehen_Fonds`) gehen bei Generator-Calls IMMER mit — unabhängig von der Beschreibung.
2. **Domain-Discovery zweistufig:** (a) explizites Slug→Domain-Mapping gegen die tokenisierte Beschreibung (Substring-Match auf Token-Ebene, Token ≥4 Zeichen für Teil-Matches); (b) nur wenn <2 Domains gefunden: Fallback Filename-Token-Match (Jaccard + 0,1×Wort-Treffer) auf die Domain-Dateinamen. Max. `DOMAIN_TOP_K=4` Domains, alphabetisch sortiert geladen.
3. **Hartes Per-Dokument-Budget:** Cross-Cutting 4.000, Domain 6.000, Pairing-Grounding 1.200/1.400 Zeichen. Gekürzte Inhalte enden mit dem Marker `[…gekürzt für KI-Kontext…]`. Gesamtbudget ≈ 52.000 Zeichen ≈ 13k Tokens.
4. **Kompakt statt Prosa beim Pairing:** Aus Pairing-Dokus werden NUR die verifizierten Partner-NAMEN extrahiert (Wikilink-Displays + **bold**-Einträge, ≤40 Zeichen, dedupliziert), nicht die molekulare Prosa. Region: ab `## Pairings` bis `## Notizen`/`## Eigene`.
5. **Stil-Filter (Achse 10):** `klassisch` → nur `### Klassisch`-Untersektionen; `kreativ` → `Klassisch`+`Modern`; `gewagt` → `Modern`+`Kontrast`; kein Stil → ganze Pairings-Region inkl. Verbund/Trinitas. Auch „gewagt" zieht NUR belegte Paarungen — die KI wird im Block explizit angewiesen, keine unbelegten Paarungen zu erfinden.
6. **Fehlende Quelle = leerer Kontext, nie Fehler:** Vault/Datei nicht vorhanden → leerer Block, Call läuft ohne Wissen weiter (graceful degradation, `vault_context.rs:545-547`).
7. **Extraktion bleibt treu:** `ai_extract_recipe` bekommt BEWUSST keinen Wissenskontext (treu extrahieren, nicht anreichern — `commands.rs:22016-22020`).
8. **Audit:** `files_used` (Ziel: Knowledge-Slugs + Version) gehört ins Call-Audit (heute nur im DTO; Ziel: Spalte `knowledge_used` analog `layers_used`, → Verbesserung §6).

## 3. Pseudocode

```
function knowledge_context(feature, beschreibung, stil?, hauptzutat_slugs?):
    routing = FEATURE_ROUTING[feature]                     # Tabelle 4.1
    if routing == KEINS: return ""

    blocks = []
    if routing umfasst CROSS_CUTTING:
        for doc in knowledge_docs(kategorie='cross_cutting', aktiv):   # 7 Stück, Always-Load
            blocks += "## CROSS_CUTTING: {doc.slug}\n\n" + truncate(doc.inhalt_md, 4000)

    if routing umfasst DOMAINS:
        tokens  = tokenize(beschreibung)                   # lowercase, ä→ae ö→oe ü→ue ß→ss,
                                                           # nur alnum, Token ≥3 Zeichen
        domains = { alias.domain für alias in knowledge_aliases
                    wo token == alias.slug
                       oder (token ≥4 Z. und alias.slug enthält token)
                       oder (alias.slug ≥4 Z. und token enthält alias.slug) }
        if |domains| < 2:                                  # Fallback: Titel-/Slug-Match
            scores = für jedes Domain-Doc: jaccard(tokens, tokenize(doc.slug))
                     + 0.1 * |tokens, die im doc.slug vorkommen|
            domains += top_k(scores > 0), bis DOMAIN_TOP_K erreicht
        for d in sort(domains)[..4]:
            blocks += "## DOMAIN: {d}\n\n" + truncate(doc(d).inhalt_md, 6000)

    if blocks: prepend "# VAULT-WISSEN …Souschef-Wissen…"-Header; join "\n\n---\n\n"

    if routing umfasst PAIRING_BLOCK:                      # Generator-Features
        sections = stil_filter(stil)                       # Tabelle 4.2
        anker    = pairing_docs deren slug die Beschreibung matcht (max 3, sortiert)
        je anker: "- {slug}: " + join(extract_partner_namen(doc, sections)[..28], " · ")
        blocks += "# FLAVOR-PAIRING (… erfinde KEINE unbelegten Paarungen):\n" + zeilen
        # Ist-Hinweis: ai_generate_recipe zieht Pairings primär aus dem SQL-Anker-Graph
        # (GL-10, commands.rs:20678-20683); der MD-Block ist Fallback.

    if routing umfasst PAIRING_GROUNDING:                  # Anker-/Pairing-Inferenz
        for hz in hauptzutat_slugs, bis max_docs erreicht (3 bzw. 5):
            doc = pairing_doc mit slug == hz
                  oder slug startet mit "{hz}_" oder hz startet mit "{slug}_"
            blocks += "### Pairing-Doku: {slug}\n" + truncate(doc.inhalt_md, max_chars)
        if keine Doku: Hinweis "(keine spezifische Doku gefunden — nutze allgemeines Wissen)"

    return join(blocks)

function truncate(text, max_chars):
    if len_chars(text) <= max_chars: return text
    return first_chars(text, max_chars) + "\n\n[…gekürzt für KI-Kontext…]"
```

## 4. Entscheidungstabellen

### 4.1 Feature → Wissens-Routing (Ist-Verhalten, verbindlich)

| Feature (Ist-Command) | Cross-Cutting | Domains | Pairing-Block | Pairing-Grounding | Beleg |
|---|---|---|---|---|---|
| `ai_generate_recipe` (Basis + VK) | ✅ | ✅ (aus `beschreibung`) | ✅ (SQL-Graph primär, MD-Fallback; Stil-Filter nur VK) | — | `commands.rs:20667-20683` |
| `ai_plan_dishes` (Menü-Planung) | ✅ | ✅ (aus `brief`) | — | — | `commands.rs:22162` |
| `ai_extract_recipe` (PDF/Bild → Rezept) | ❌ bewusst leer | ❌ | ❌ | ❌ | `commands.rs:22016-22020` |
| `ai_suggest_pairings` (Rezept-Pairings) | — | — | — | ✅ max 5 Dokus × 1.200 Z. via `hauptzutat_slug` der Zutaten | `commands.rs:15535-15565` |
| `ai_infer_gp_ankers` / `ai_infer_recipe_ankers` | — | — | — | ✅ max 3 Dokus × 1.400 Z. via Hauptzutat | `commands.rs:15928-15945` |
| Label-/Mechanik-Features (Tags, Domain, Sektor, Niveau, Garverlust, stk_default, Geschmacksrichtung, Kategorie, LA-Match …) | — | — | — | — (nur Hüllen GL-06 + strukturierte DB-Daten + Vokabular-Listen im Prompt) | z.B. `commands.rs:11343ff` |

### 4.2 Stil → Pairing-Sektionen (Achse 10)

| `kompositions_stil` | Gescannte `### `-Untersektionen | Prompt-Hinweis |
|---|---|---|
| (nicht gesetzt) | gesamte `## Pairings`-Region inkl. Verbund/Trinitas | — |
| `klassisch` | nur `Klassisch` | „etablierte, traditionelle Kombinationen" |
| `kreativ` | `Klassisch` + `Modern` | „klassische Basis + belegte Twists" |
| `gewagt` | `Modern` + `Kontrast` | „bewusst mutig, aber NUR belegte aus dieser Liste" |

### 4.3 Ziel-Schema `foodalchemist_knowledge_*` (⚠D4 — **ENTSCHIEDEN 2026-06-11**, siehe 08_ENTSCHEIDUNGEN: Drei-Klassen-Modell, hier landet nur Klasse A)

```
foodalchemist_knowledge_documents
  id, uuid, team_id NULLABLE (⚠D1: NULL = global/BHG-kuratiert),
  slug          VARCHAR UNIQUE          -- z.B. 'substitutionen', 'fisch_seafood', 'pairing.salbei'
  titel         VARCHAR
  kategorie     ENUM('cross_cutting','domain','pairing','regelwerk_snippet')
                                        -- regelwerk_snippet = nur prompt-relevante §§, nie Vollwerk;
                                        -- Niveau_System wird NICHT Knowledge → Hüllen (GL-06)
  inhalt_md     TEXT (Markdown, 1:1 aus Vault-Export)
  version       INT (monoton; Import-Kommando erhöht bei Inhalts-Änderung)
  content_hash  VARCHAR (sha256 — idempotenter Import: unverändert → skip)
  char_count    INT (für Budget-Vorschau)
  aktiv         BOOL
  quelle_pfad   VARCHAR (Vault-Herkunft, nur Doku/Re-Import)
  created_at / updated_at / deleted_at

foodalchemist_knowledge_aliases        -- ersetzt das hartkodierte HAUPTZUTAT_TO_DOMAIN
  id, alias_slug VARCHAR ('lachs', 'gouda', …), knowledge_document_id FK
  -- Seed: die 258 Paare aus vault_context.rs:39-322

foodalchemist_knowledge_routings       -- Tabelle 4.1 als Daten (pro KI-Feature konfigurierbar)
  id, feature VARCHAR, kategorie ENUM, modus ENUM('always','discovery','grounding','none'),
  max_docs INT, max_chars_per_doc INT
```

Pairing-Dokus referenzieren: `foodalchemist_vocab_pairing_ankers.knowledge_document_id` (ersetzt `file_path`); Food-Domains: `foodalchemist_vocab_food_domains.knowledge_document_id` (ersetzt `vault_file`/`md_path` — vgl. 02_DATENMODELL Z.46). Import: Artisan-Kommando `foodalchemist:knowledge-import` liest den Vault-Export und upsertet per `slug` (07_MIGRATION_SEED).

## 5. Golden-Testfälle

GT-1 bis GT-5 existieren als Rust-Tests und wandern 1:1 in PHPUnit-Datasets.

| # | Input | Expected | Beleg |
|---|---|---|---|
| GT-13-1 | `tokenize("Halve Hahn mit Holländer-Käse")` | Tokens enthalten `hollaender` und `kaese` (Umlaut-Expansion, Bindestrich splittet, „mit" bleibt da ≥3 Z.) | `vault_context.rs:661-665` |
| GT-13-2 | `jaccard({butter,eigelb},{butter,zucker})` | `1/3 ± 0.001` | `vault_context.rs:667-672` |
| GT-13-3 | Pairing-Fixture (Klassisch: Butter / Modern: Yuzu, Matcha / Kontrast: Anchovis / Verbund: TrinitasX / Notizen: Noise), Filter `None` | Butter, Yuzu, Anchovis, TrinitasX enthalten; **Noise nicht** (hinter `## Notizen`) | `vault_context.rs:684-692` |
| GT-13-4 | Gleiches Fixture, Filter `["Klassisch"]` | Butter ja; Yuzu und Anchovis **nein** | `vault_context.rs:694-700` |
| GT-13-5 | Gleiches Fixture, Filter `["Modern","Kontrast"]` (= `gewagt`) | Yuzu + Anchovis ja; Butter **nein**; TrinitasX **nein** (Verbund ist eigene `## `-Sektion → Filter aus) | `vault_context.rs:702-709` |
| GT-13-6 | `load_vault_context("Lachs mit brauner Butter und Walnuss")` | `files_used` enthält alle 7 Cross-Cutting + Domains `Fisch_Seafood`, `Milchprodukte`, `Nuesse_Saaten` (Stufe 2a, 3 ≥ 2 → kein Filename-Fallback); Domains alphabetisch sortiert; max 4 Domains | `vault_context.rs:567-626` |
| GT-13-7 | Domain-Dokument mit 10.000 Zeichen | Block = erste 6.000 Zeichen + `\n\n[…gekürzt für KI-Kontext…]`; Dokument mit exakt 6.000 → ungekürzt, ohne Marker | `vault_context.rs:379-387` |
| GT-13-8 | `load_vault_context("")` | Nur Cross-Cutting-Blöcke (Token-Set leer → keine Domain); kein Fehler | `vault_context.rs:567-615` |
| GT-13-9 | Wissens-Quelle komplett fehlt (Vault weg / Tabellen leer) | `combined_block=""`, `files_used=[]`, `total_chars=0` — Generator-Call läuft ohne Wissen weiter | `vault_context.rs:545-547,648-654` |
| GT-13-10 | `load_pairing_block("Salbei-Gnocchi", stil="klassisch")` mit Salbei-Doku aus GT-13-3 | Genau eine Zeile `- salbei: …` mit nur Klassisch-Partnern (max 28); Header enthält „Stil KLASSISCH" und „erfinde KEINE unbelegten Paarungen" | `vault_context.rs:464-539` |
| GT-13-11 | GP-Anker-Grounding: `hauptzutat_slug='koriander'`, Anker-Vokabular hat `koriander_blatt` + `koriander_saat` | Beide Dokus geladen (Präfix-Match `slug startet mit "hz_"`), je auf 1.400 Z. gekürzt, dedupliziert pro Datei, max 3 | `commands.rs:15928-15945` |

## 6. Offene Weichen & Verbesserungen

- **⚠D4 — ENTSCHIEDEN (2026-06-11, Dominique):** Modul-Tabellen (Schema §4.3) mit **Drei-Klassen-Modell** (Details: 08_ENTSCHEIDUNGEN D4): Klasse A (Cross_Cutting 33 + Domains 36 + Pairing-MDs 767 + Regelwerk-Snippets) → DB/MVP · Klasse B (Literatur/Marktstudien/PDFs) → bleibt Vault · Klasse C (75 Destillate, Trend-Pulse) → Phase 2. **Pflegeprozess:** Vault bleibt Autoren-Umgebung, EINBAHN via `foodalchemist:knowledge-import` (Upsert per slug, content_hash). **Pairing-Konsistenz-Kopplung:** Import parst Kanten aus Pairing-MDs → `pairing_anker_edges` + Re-Embed (V-24) in EINEM Schritt. **Niveau_System → Hüllen** (GL-06), nicht Knowledge. Retrieval-Interface bleibt Repository-Pattern (Quelle austauschbar, falls später obsidian-Modul). Offen nur noch: Team-Sichtbarkeit (⚠D1) + Authoring-in-Plattform (Phase 2+).
- **⚠D3-Berührung:** Das Routing (4.1) lebt im `AiGatewayService` — pro Feature konfigurierbar, nicht hartkodiert.
- **Verbesserung — Wissens-Audit:** `knowledge_used` (Slugs + Version) analog `layers_used` ins `foodalchemist_ai_call_log` schreiben; heute geht `files_used` nach dem Call verloren.
- **Verbesserung — Alias-Pflege:** `HAUPTZUTAT_TO_DOMAIN` (258 hartkodierte Paare) wird Daten (`knowledge_aliases`) mit Admin-UI; Gap-Surfacing aus GL-07 kann fehlende Aliasse melden.
- **Verbesserung — Retrieval-Qualität (Phase 2):** Heute rein lexikalisch (Token/Jaccard). Embedding-Re-Ranking existiert in der Alt-App bereits fürs Inventory-Grounding (`commands.rs:20696ff`) — für Knowledge-Discovery evaluieren, aber erst nach 1:1-Port (Verhaltens-Parität zuerst).
- **V-15-Berührung:** Bulk-Jobs laden Wissen pro Item neu — bei Queue-Umbau Caching pro Job-Batch erwägen (gleiche Beschreibung ≠ gleiche Domains, aber Cross-Cutting ist konstant).
