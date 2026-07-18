# RAG-System #507 — Go-Live-Runbook (Online-Scharfstellen)

> **Zweck:** Die letzten Schritte, um den semantischen Retrieval-Layer auf demo LIVE zu schalten. Code ist gebaut + gepusht (`ebc1aa4`); hier steht nur noch, was ONLINE passieren muss.
> **Begleitdokument zu:** [[02_RAG_System_FoodAlchemist]] (Bauplan). **Stand:** 2026-07-17.
> **Grundprinzip:** Bis Schritt 5 bleibt der Layer still (`enabled()` false ODER Flag aus) → FA läuft rein lexikalisch weiter, kein Risiko. Erst Schritt 5 stellt scharf.

---

## Vorbedingungen (einmalig)

- [ ] **Code live:** Deploy von `main` (`ebc1aa4`) auf demo — via **`demo.bhgdigital.de/update.sh`** (git pull main → `composer update` über die Demo-Host-App → commit + **push** → Server-Auto-Deploy). **NICHT** modul-scoped `composer update martin3r/platform-foodalchemist` fahren (= nur Sandbox-Lokaltest).
- [ ] **OpenAI-Key** auf demo via Core-Contract (`config('services.openai.api_key')`) — Martin. Deckt LLM **und** Embeddings (Entscheid A, ein Key).
- [ ] Kein neuer Migrations-Schritt nötig (E1–E5 nutzen die bestehende `core_embeddings`-Tabelle aus platform-core).

> ⚠️ **Server-CLI:** Default ist PHP 8.3, Web läuft auf 8.4 → **immer `php8.4 artisan …`** (nacktes `php`/`artisan` bricht mit „requires PHP >= 8.4.1" ab — kein Schaden, nur falsche CLI-Version).

---

## Schritt 1 — Backfill (Vektoren erzeugen)

Der einzige Schritt, der **nicht** vom Auto-Deploy mitkommt. Ohne ihn ist der Index leer → 0 Treffer.

```bash
ssh forge@demo.bhgdigital.de
cd <demo-app-root>
php8.4 artisan foodalchemist:embed --pool=all
```

**Erwartung:** Tabelle mit Kandidaten-Zahlen je Pool (GP ~7–8k, Rezepte ~2–3k, Wissen ~1k) + Partitionen. Idempotent — Re-Run überspringt Unverändertes (source_hash).

- [ ] Backfill gelaufen, Zahlen plausibel (nicht 0).
- [ ] Queue-Worker läuft (für den inkrementellen Observer-Pfad bei künftigen GP-/Rezept-Edits — `GenerateEmbeddingJob`).

**Verifikation (optional):**
```bash
php8.4 artisan tinker --execute="echo \Illuminate\Support\Facades\DB::table('core_embeddings')->distinct()->count('entity_type');"
# erwartet: ≥ 3 entity_types (foodalchemist_gp, foodalchemist_recipe, foodalchemist_knowledge_document)
```

---

## Schritt 2 — Eichung (Floor messen, NICHT raten)

`pool_sem_floor` steht auf `0.55` — das ist **Gemini-768d-geeicht**, für OpenAI ungeprüft. Dieser Lauf misst den richtigen Wert am Golden-Set.

```bash
php8.4 artisan foodalchemist:embed-eval --team=<MASTER_TEAM_ID> --k=15
# optional: --details  (jeden der 44 Fälle einzeln)
# optional: --floors=0.30,0.35,...,0.70  (Sweep-Raster ändern)
```

**Was zu lesen ist:** Tabelle Floor × Recall@15 je Fallklasse (Übersetzung/Synonym/regional/Kompositum) + Anti-Marker-Verletzungen. Am Ende:

> `→ Vorschlag pool_sem_floor = X.XX  (Recall@15 …%, Anti-Marker-Verletzungen 0/8)`

- [ ] Vorschlag notiert: **`pool_sem_floor = ______`**
- [ ] Anti-Marker-Verletzungen beim Vorschlag = **0** (sonst NICHT scharfstellen — Golden-Set/Embed-Text prüfen).
- [ ] Recall plausibel (Übersetzung/Kompositum sollten deutlich > lexikalische Baseline liegen).

> Kommt **kein** Vorschlag („kein Floor ohne Anti-Marker-Verletzung"): NICHT scharfstellen. Dann ist entweder der Embed-Text zu grob oder das Modell verwechselt Anti-Marker — mit Martin/Dominique klären.

---

## Schritt 3 — Scharfstellen (das ist der Moment)

demo-`.env` (bzw. Forge Environment):

```
FOODALCHEMIST_SEMANTIC_POOL_FLOOR=<Vorschlag aus Schritt 2>
FOODALCHEMIST_SEMANTIC_SEARCH=true
```

```bash
php8.4 artisan config:clear
```

- [ ] Floor + Flag gesetzt, Config gecleart.

---

## Schritt 4 — Smoke-Test (der #507-Demo-Fall)

Über MCP (Live-Connector) oder tinker:

- [ ] `gps.MATCH("Beef")` bzw. `gps.SEARCH("Beef")` → **Rindfleisch/Roastbeef** taucht auf (vorher: nur „Corned Beef" 0.5). Treffer trägt `via: semantic` bzw. `origin`.
- [ ] `gps.SEARCH("Erdapfel")` → Kartoffel-GP mit `via: semantic`.
- [ ] Gegenprobe: `gps.SEARCH("Bries")` liefert **nicht** „Brie" als Top-Treffer (Anti-Marker hält).
- [ ] Ein „KI-Überarbeiten" an einem Rezept: neue Zutat wird gegroundet (nicht `unmatched`), Vorschau zeigt Badge (E3).

---

## Rollback (jederzeit, risikolos)

```
FOODALCHEMIST_SEMANTIC_SEARCH=false
```
→ `config:clear`. FA fällt sofort auf den rein lexikalischen Pfad zurück (Legacy-Verhalten, byte-identisch). Die Vektoren bleiben liegen (kein Datenverlust), können später wieder zugeschaltet werden.

---

## Troubleshooting

| Symptom | Ursache | Fix |
|---|---|---|
| `embed`/`embed-eval`: „Kein Embedding-Provider verfügbar" | Key fehlt / falsche CLI | Key prüfen (`services.openai.api_key`); `php8.4` nutzen |
| `embed-eval`: alle Queries 0 Treffer | Pools nicht gebackfillt | Schritt 1 nachholen |
| Suche findet nichts trotz Flag=true | Floor zu hoch / Index leer | Schritt 2 Floor senken; Backfill prüfen |
| Anti-Marker verwechselt (Brie/Bries) | Floor zu niedrig | Floor auf den `embed-eval`-Vorschlag heben |
| „requires PHP >= 8.4.1" | Server-Default-PHP 8.3 | `php8.4 artisan …` |

---

## Danach

- [ ] `pool_sem_floor` finalen Wert in Memory `project_fa_507_semantic_search` + Dev-#507 (E5-DoD) eintragen.
- [ ] Dev-#507 auf **Done** (E5+E6 abgehakt).
- [ ] `foodalchemist:embed` + `foodalchemist:embed-eval` in die CLAUDE.md-Skript-Tabelle.
- [ ] Perf-DoD am echten 8k-GP-Pool prüfen (Einzel-Match < 1 s warm; 30-Zutaten-Batch < 10 s) — bei Riss: Cache-Schicht als E2-Nachtrag (§5 im Bauplan), erst dann über Qdrant reden.
