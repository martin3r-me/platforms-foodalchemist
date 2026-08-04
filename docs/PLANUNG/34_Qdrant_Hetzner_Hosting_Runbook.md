# Qdrant auf Hetzner — Hosting-Runbook + Scope-Entscheid

> **Stand:** 2026-08-03 · **Auftrag:** `QdrantEmbeddingStore` (Martin, gebaut) als produktives ANN-Backend hosten. Umsetzung mit Christian + RM (Infra), Backfill/Routing FA-seitig.
> **Vorgeschichte:** `MySqlJsonEmbeddingStore` (Cosine in PHP) hat dokumentierte Grenze ~50k Vektoren/Partition. Die **LA-Pool (Lieferantenartikel, ~100k)** wurde bewusst NICHT embedded — „bis Store/Qdrant-Entscheid" (Spec 15 §5c). Dieses Runbook löst genau diesen Entscheid ein.
> **Referenz:** `docs/_archiv/.../PLANUNG/02_RAG_System_FoodAlchemist.md` (Gesamtplan RAG), `EmbeddingStoreContract` (platforms-core).
>
> **Umsetzung FA (Stand 2026-08-04):** Core-Registry ist da (`EmbeddingStoreRegistry` + `QdrantEmbeddingStore`, beide Stores benannt registriert `mysql`/`qdrant`). FA-Code-Teil **fertig + Suite grün (2241)**: alle 8 `foodalchemist_*`-Pools werden im `FoodAlchemistServiceProvider::boot()` per `route()` deklariert (Store aus `config('foodalchemist.embedding_store')`, Default `mysql`), und `SemanticRetrievalService::candidates()` löst den Store jetzt über die Registry auf statt über den (in Core **bewusst nicht mehr gebundenen**) `EmbeddingStoreContract`. Offen = reine Ops: Backfill + Flip auf demo (unten §5).

---

## 0. Was Martins zwei Fragen für den Bau bedeuten (kurz)

**Frage 1 — shared vs. tenant:** Beides. FA hat den Split **bereits** im Code:
- **Referenz-/Universalwissen** (knowledge_documents, Anker, GPs, Basisrezepte, **LA-Pool**) → globaler Korpus, im Store über **Sentinel `team_id = 0`** abgelegt (`KnowledgeEmbeddingService`). Jede Query trifft den ganzen Korpus → **ANN zwingend**, keine Tenant-Partition nötig.
- **Tenant-Daten** (Kundenrezepte, Kunden-Foodbooks, kundenspezifische Konzepte) → echte `team_id`, klein je Tenant → Brute-Force wäre okay, aber sie liegen im selben Store.
- **Konsequenz für den Store:** Der Qdrant-Store muss den **Global-∪-Team-Filter** abbilden (`team_id IN {0, <tenant>}`), sonst ist die Referenz-Wissensbasis für Tenant-Queries unsichtbar. Das ist die offene „E0-Core-Discussion (Global-∪-Team-Scope) an Martin" aus dem RAG-Plan — sie wird hier scharf.
- **Leak-Risiko:** Der `foodbooks`-Pool ist tenant-privat. Routing/Scope müssen **default tenant, opt-in global** sein. Falsch klassifiziert = Kundendaten cross-tenant sichtbar. Das ist die KPI „0 Leaks".

**Frage 2 — ist Pairing ein Embedding-Problem:** Nein — und FA sagt das bereits selbst. RAG-Plan §3.1, Rollen-Invariante: *„Embeddings = Recall/Shortlist. Nie finaler Ranker. Nie für Kontrast/Pairing — der Anker-Graph bleibt die einzige Pairing-Wahrheit."* Qdrant bedient **Retrieval/Ähnlichkeit** (finde ähnliche Rezepte/GPs, Terminologie-Erdung). Pairing bleibt der **Anker-Graph** (`pairing_anchor_edges`, Co-Occurrence, Flavor Network). Hybrid-Endstufe = Embedding holt Kandidaten, Graph bewertet die Passung.

---

## 1. Server-Empfehlung

| Option | Specs | ~€/Mon | Bewertung |
|---|---|---|---|
| CAX11 (ARM) | 2 vCPU, 4 GB | ~4 | Reicht **nur mit int8-Quantisierung** (s.u.) |
| **CAX21 (ARM)** ← Empfehlung | 4 vCPU, 8 GB | ~7 | Headroom, auch unquantisiert bei 3072d, schnelleres Indexing |

**Warum CAX21, nicht CAX11:** Default-Provider ist OpenAI `text-embedding-3-large` = **3072 Dimensionen**. 100k Vektoren × 3072 × 4 B = **~1,23 GB roh** (float32), mit HNSW-Graph real ~2–2,5 GB im RAM. Auf 4 GB (CAX11) ist das **nur mit Quantisierung** entspannt; für Indexing-Bursts + Wachstum (LA-Pool wächst) sind 8 GB die sichere Wahl. Die 3 €/Mon Aufpreis sind irrelevant.

- **Region: EU** (Nürnberg / Falkenstein / Helsinki). DSGVO / Datenresidenz DE-EU — der Vorteil ggü. Qdrant Cloud (AWS/US). **Intern als Governance-Argument nutzen.**
- Qdrant-Image ist ARM64-fähig → CAX-Linie (Ampere) passt.

---

## 2. Sicherheit — der #1-Risikopunkt beim Self-Hosting

**Qdrant hat per Default KEINE Authentifizierung.** Ein offenes Qdrant auf einer öffentlichen Hetzner-VM = Datenexfiltration. Pflicht:

1. **API-Key** setzen (`QDRANT__SERVICE__API_KEY`) — der ENV-Knopf `QDRANT_API_KEY` ist FA-seitig schon vorgesehen.
2. **Kein öffentliches Exposure.** Zwei Wege:
   - **(A, bevorzugt) Hetzner Private Network** zwischen FA-App-Host und Qdrant-VM. Qdrant lauscht nur im privaten Netz, **kein** Inbound aus dem Internet auf 6333/6334.
   - **(B, falls Cross-Provider)** Public + **Reverse-Proxy mit TLS** (Caddy) + **Hetzner Cloud Firewall**, die 6333/6334 **ausschließlich** von der FA-App-Source-IP zulässt.
3. **Hetzner Cloud Firewall** in jedem Fall: Inbound 22 nur von Admin-IPs; 6333/6334 nur von der App-Quelle (oder gar nicht öffentlich bei A); Rest deny.
4. **Image-Version pinnen** (nicht `latest`) — Reproduzierbarkeit + kontrollierte Upgrades.

---

## 3. docker-compose (Basis)

```yaml
# /opt/qdrant/docker-compose.yml   (Version pinnen!)
services:
  qdrant:
    image: qdrant/qdrant:v1.x.y        # konkrete Version, nicht latest
    restart: unless-stopped
    ports:
      # Variante A (Private Network): an die private IP binden
      # - "10.0.0.2:6333:6333"
      # - "10.0.0.2:6334:6334"
      # Variante B (Public + Reverse-Proxy): nur lokal, Caddy davor
      - "127.0.0.1:6333:6333"
      - "127.0.0.1:6334:6334"
    environment:
      QDRANT__SERVICE__API_KEY: "${QDRANT_API_KEY}"
    volumes:
      - ./storage:/qdrant/storage      # persistente Daten — Backup-relevant
      - ./config:/qdrant/config
```

Bei Variante B zusätzlich Caddy (automatisches Let's-Encrypt-TLS) als Reverse-Proxy auf 443 → 127.0.0.1:6333.

---

## 4. FA-Konfiguration (ENV)

Routing macht FA selbst im ServiceProvider (`route()` für alle 8 Pools) — **kein** globaler
`EMBEDDING_STORE=qdrant` (der würde jedes Modul mitziehen). Ein Schalter steuert alles:

```dotenv
# FA-Schalter für ALLE acht foodalchemist_*-Pools (mysql | qdrant). Default (unset) = mysql = No-op.
FOODALCHEMIST_EMBEDDING_STORE=qdrant

# Qdrant-Verbindung (von Cores config/embeddings.php gelesen)
QDRANT_URL=https://qdrant.intern.bhgdigital.de   # bzw. private IP:6333
QDRANT_API_KEY=<geheim, 32+ Zeichen>
QDRANT_QUANTIZATION=scalar             # ⚠ Wert ist 'scalar' (int8 unter der Haube), NICHT 'int8'.
                                       # unset = keine Quantisierung. RAM ↓ ~4x; Recall danach messen!
```

- **`FOODALCHEMIST_EMBEDDING_STORE`** ist zugleich der **Rollback-Schalter**: `=mysql` (bzw. entfernen)
  routet sofort zurück — solange der MySQL-Bestand noch nicht gepurged ist (§5.6).
- Cores globaler `EMBEDDING_STORE` + `embeddings.routing`-Map bleiben als Fallback existent, werden
  von FA aber **nicht** benutzt (Modul-`route()` hat Vorrang, hält core entity-agnostisch).
- **Alle 8 Pools müssen denselben Store haben** (Mixed-Type-Suche `[gp,recipe]` routet am ersten Typ).

**Collection pro Modell** (Qdrant-Collection ≙ provider+model): der Store baut sie **selbst** beim ersten
`store()` (Cosine, Dimension aus dem Vektor, Payload-Indizes auf `team_id`+`entity_type`). Namensschema
`emb_openai_text_embedding_3_large` bzw. `emb_gemini_…` (Prefix `QDRANT_COLLECTION_PREFIX`, Default `emb`).
Der `EmbeddingDimensionMismatchException`-Guard fängt Modell-Wechsel — bei Wechsel `purgeProvider()` + Re-Embed.

---

## 5. Backfill + Cutover (kein Big-Bang, off-peak)

Der FA-Store-Schalter routet Reads **und** Writes gemeinsam (ein Schalter, kein getrennter Read/Write-Split).
Sequenz daher **Flip → Backfill → Verify → rollback-sicher**; MySQL-Bestand bleibt als Rollback erhalten,
bis §5.6 abgeräumt wird. Während des Backfills (nach Flip, vor gefüllter Collection) liefert die
Semantik-Recall-Schicht leer → die Aufrufer fallen **graceful auf lexikalisch** zurück (GL-13 Invariante 6,
kein Fehler). Deshalb off-peak. Reihenfolge:

0. **Deploy** (FA-Code ist No-op bis Flip): im demo-Repo `git pull` → `composer update` → `composer.lock` committen/pushen. Danach `php8.4 artisan config:clear`.
1. **Provisionieren** (Christian/RM): VM, Firewall/Private Net, docker-compose up, API-Key, TLS. Collections legt der Store beim ersten `store()` selbst an — kein manuelles Setup.
2. **Flip off-peak:** `FOODALCHEMIST_EMBEDDING_STORE=qdrant` setzen (+ `QDRANT_URL`/`QDRANT_API_KEY`/ggf. `QDRANT_QUANTIZATION=scalar`), `config:clear`. Ab jetzt gehen Reads+Writes nach Qdrant (noch leer → lexikalischer Fallback).
3. **Backfill off-peak** (Embed-Jobs erzeugen Last → nie parallel zur Nutzung, sonst 502; ~15k Vektoren, idempotent per `source_hash`, Re-Run sicher):
   - `php8.4 artisan foodalchemist:knowledge-embed`      (Wissenskorpus + Anker, team_id=0)
   - `php8.4 artisan foodalchemist:embed --pool=all`      (gps/recipes/suppliers/concepts/foodbooks/lab_notes)
4. **Recall verifizieren:** `php8.4 artisan foodalchemist:embed-eval --team=<id>` — Recall@K + Anti-Marker gegen die MySQL-Baseline. **Quantisierung darf den Floor nicht unter ~66 % drücken.** Sinkt Recall → `QDRANT_QUANTIZATION` lockern (`scalar`→unset) oder CAX21-RAM nutzen, dann `--pool=all` erneut (Collection neu füllen). **+ Foodbook-Leak-Check** (tenant-privat): Tenant-Query darf nur eigene Ahnenkette + Global sehen, nie fremde Foodbooks.
5. **Stabil?** Wenn Recall + Leak-Check ok → fertig, Qdrant ist live. Falls nicht: `FOODALCHEMIST_EMBEDDING_STORE=mysql` + `config:clear` = Sofort-Rollback (MySQL trägt noch).
6. **Altpfad abräumen** (erst nach stabiler Verifikation): `core_embeddings`-Zeilen der 8 Typen entfernen. Erst wenn Qdrant nachweislich trägt — vorher ist es der Rollback-Anker.

> **LA-Pool (~100k, bisher zurückgehalten, Spec 15 §5c)** ist **kein** Teil dieser 8-Pool-Migration — separater Backfill, wenn der LA-Embed-Pfad gebaut ist.

---

## 6. Betrieb / offene Risiken

- **Backups:** Qdrant-Snapshot-API + Offsite (Hetzner Storage Box). VM-Verlust ohne Snapshot = kompletter Re-Embed (teuer, off-peak). **Muss vor Go-Live stehen.**
- **HA / Single Point of Failure:** eine VM → Feature dunkel bei Ausfall. Entscheiden: Fällt das Routing bei Qdrant-Down auf `MySqlJsonEmbeddingStore` zurück (degradiert, aber online)? Für den Referenz-Korpus tolerierbar (re-embedbar), bewusst festlegen.
- **Netz-Placement:** **Wo läuft die FA-App?** Auf Forge/AWS + Qdrant auf Hetzner = Cross-Provider-Hop je Query (Latenz + Egress). **Co-Location bzw. Private Network ist die Kern-Infra-Entscheidung für Christian.**
- **Global-∪-Team-Filter — GELÖST, kein Core-Change nötig:** FA sucht in `SemanticRetrievalService::candidates()` **je Partition einzeln** (eigene Ahnenkette ∪ Master ∪ Global-Sentinel 0) und merged modulseitig — jeder Store-Call trägt genau **eine** `team_id`. `QdrantEmbeddingStore.search()` filtert `must: team_id = <id>` (+ optional `entity_type ANY`), exakt wie der MySQL-Store. Der Compound-Filter `IN {0, tenant}` wird damit **nicht** gebraucht; die alte „E0-Core-Discussion an Martin" entfällt für FA. (Nur relevant, falls jemand später auf einen Single-Call-`EmbeddingService::search` mit einer team_id umbaut.)

---

## 7. Rollen

| Wer | Aufgabe |
|---|---|
| **Christian / RM** | VM (CAX21, EU), Firewall/Private Net, docker-compose, TLS, API-Key, Snapshots |
| **Martin (core)** | `QdrantEmbeddingStore` (gebaut) + Global-∪-Team-Filter im Store bestätigen |
| **Dominique (FA)** | ENV/Routing-Map, Backfill-Jobs, `embed-eval`-Verifikation, Cutover, Altpfad-Abräumen |

## 8. Offene Entscheidungen (vor Provisionierung klären)

1. **App-Placement / Netz:** FA-App-Host? → Private Network vs. Public+TLS.
2. **Quantisierung:** int8 als Start (RAM-schonend) — Recall-Messung entscheidet endgültig.
3. **Routing-Granularität:** globaler `EMBEDDING_STORE=qdrant` vs. pro-entity_type (Referenz→qdrant, `foodbooks` konservativ).
4. **Fallback-Policy** bei Qdrant-Ausfall.
