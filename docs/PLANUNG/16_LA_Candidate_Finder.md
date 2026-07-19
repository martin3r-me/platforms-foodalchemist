# WG-Lead-gescopter LA-Kandidaten-Finder + On-demand-Klassifikation

> **ROADMAP-Bezug:** #507 (Grounding/Weg-2) · Spec [07](07_LA_First_GP_Mint_ueberall.md) (LA-First-Mint) · Spec [15](15_Semantic_Supplier_Item_Pool.md) (LA-Vektor-Pool — hiermit **entkoppelt/verschoben**).
> **Anlass:** Use Case Dominique 2026-07-20 — *„Wenn es keinen passenden GP gibt, aber das Rezept die Zutat braucht, muss der passende Artikel unter den LA-Leads gefunden werden, die für die Warengruppe definiert sind."*
> **Kernaussage:** Dieser Use Case braucht **KEINEN Vektor-Store/Qdrant und keinen 264k-Pool.** Er ist eine **WG-Lead-gescopte Namenssuche** (Terminologie + Lexik, Weg-2-Stack schon live) + eine **on-demand-Klassifikation** der tatsächlich getroffenen LAs (asynchron). Der LA-Vektor-Pool (Spec 15) ist damit vom Tisch, bis echte Freitext-Discovery über den ganzen Katalog gewünscht ist.
> **Reifegrad: 🟢 bau-reif** (Code-kartiert + code-verifiziert 2026-07-20). Größe **S–M** (Finder klein — Scope-Resolver S1 existiert bereits als Reuse; on-demand-Klassifikator mittel = einziges echt neues Stück).

---

## 0. Warum / das Problem in Zahlen (demo, 2026-07-20)

- **264.516 LAs** gesamt, alle „aktiv" (der Flag ist wertlos — 96 % nie gemappt = Necta-Ballast).
- **`preferred_suppliers`** (die WG-Lead-Liste): **130 Zeilen, 109 mit WG-Code über alle 15 WGs, 21 Lieferanten** → gut gepflegt.
- Katalog dieser 21 Lead-Lieferanten: **117.054 LAs**, davon **7.811 strukturiert/klassifiziert (≈ 6,7 %)**.

**Zwei Erkenntnisse:**
1. Der WG-Lead-Scope verengt 264k → 117k → (pro WG) wenige tausend. Klein genug für Lexik/Terminologie, **kein Embedding/ANN nötig**.
2. Der eigentliche Hebel ist die **Auffindbarkeit/Klassifikation** der LAs (93 % der relevanten Kataloge unklassifiziert), NICHT die Such-Technik. Deshalb der zweite Baustein: on-demand-Klassifikation.

---

## 1. Code-Kartierung (verifiziert 2026-07-20)

**Der Finder existiert schon — naiv:** `LaFirstGpService::mintFromLa(Team, string $text, ?string $slug)` `:42`
- Aktuell: `SupplierItemService::searchGlobal($team, $text, [], 3)->items()[0]` → **global, ungerankt, erstes Item**. Genau das ist zu schärfen.
- `$slug`-Param ist bereits **„reserviert für schärferes LA-Matching (M3)"** → der Einhängepunkt für Hauptzutat-scharfes Matching.
- Rest der Methode ist gut: LA→GP-Struktur-Reuse, Dedup-Guard (`GpNamingService::anlageGuard`), §6-Naming, `LeadLaService::verknuepfen`. **Nur die Kandidatenwahl ist naiv.**

**Such-Einhängepunkt:** `SupplierItemService::searchGlobal(Team, q, array $filters, perPage)` `:43` — nimmt eine **`$filters`-Array** → hier kommt der `supplier_id IN (WG-Leads)`-Scope rein (kein Neubau der Suche).

**WG-Lead-Quelle + Scope-Resolver EXISTIERT SCHON (Korrektur 2026-07-20):** Tabelle `foodalchemist_preferred_suppliers` (`team_id, supplier_id, commodity_group_code`, nullable=global; English-Rename der Tabelle, Modell/Service blieben deutsch). Der WG-Scope-Resolver ist **bereits gebaut**: `StammLieferantService::stammSupplierIdsFor(team, ?wgCode)` `:33` liefert exakt die Lead-`supplier_id`s einer WG **inkl. global-NULL-Merge** (`whereNull('commodity_group_code') OR = wgCode`). Von `LeadLaService` schon konsumiert. → **S1 ist damit Reuse, kein Neubau** (spart eine Komponente).

**Vorhandenes Lead-Ranking (reuse als Muster):** `LeadLaService::rangliste(gp, team)` `:47` + `kandidaten()` — rankt LAs für ein GP über Stamm-/Preferred-Overlay + `stammIds`. Die Ranking-Logik (Lead-Priorität × Vollständigkeit) ist hier schon gelöst → für den WG-Fall spiegeln.

**Namensmatch (Weg-2, LIVE):** `TerminologyService::aliasPhrasesFor` (S1, Paradeiser→Tomate) + `isAntiMarker` (S2) + `TokenEngine::matchScore` (lexikalisch). Läuft auf dem rohen `designation` — **keine LA-Klassifikation nötig**.

**Klassifikations-Senke (Felder da, 6,7 % gefüllt):** `foodalchemist_supplier_item_structures` — `main_ingredient_slug`, `commodity_group_suggestion`, `commodity_group_confidence`, `processing`, `form`, `condition`, `is_food`, `classifier`, `classifier_version`, `classified_at`, `needs_review`. **Ein live-LA-Klassifikator fehlt in FA** (die 7.811 kamen aus dem WaWi-Master-Import `wawi_la_structured`) → NEU, spiegelt das Vault-Muster `105_classify_with_gemini`.

**Async-Muster (reuse):** `src/Jobs/BulkEnrichJob.php` / `BulkEnrichGpJob.php` (queued KI-Anreicherung) — direkte Vorlage für den on-demand-Klassifikations-Job. LLM-Zugang via `AiGatewayService` (wie Generator/BulkEnrich).

**Review-Senke:** `ReviewQueue` (Livewire) — tentative GPs + `needs_review`-Strukturen landen hier zur Freigabe (schon Klasse-A-Queue).

**MCP (Lockstep):** `gps.MINT_FROM_LA` + `gps.MATCH` (mint-if-missing) existieren (Spec 07) → nur den WG/Scope-Param durchreichen.

---

## 2. Vorzuklärende Entscheidungen

| # | Frage | Tendenz / Optionen |
|---|---|---|
| E1 | **Woher kommt die WG der Zutat**, wenn kein GP existiert? | Der Aufrufer (Generator/Klassifikator) liefert einen **WG-Hint** mit (der Generierungs-Kontext kennt die grobe WG). Fehlt er → Finder läuft über **alle** WG-Leads (breiter, aber funktioniert). Kein eigener WG-Rater nötig für v1. |
| E2 | **Fallback-Kaskade**, wenn der WG-Lead die Zutat nicht führt | (a) nächster preferred der WG → (b) global/Stamm (WG=NULL) → (c) irgendein Lieferant, der die Zutat führt → (d) **Sourcing-Wunsch** (kein Treffer, kein GP; Doktrin). **Fachliche Reihenfolge = Dominique.** |
| E3 | **On-demand-Klassifikation: sync oder async?** | **Async (Queue-Job)** — Mint passiert sofort ohne KI; Klassifikation läuft danach, blockiert die Suche/das Grounding NIE. |
| E4 | **Ranking-Formel** der Kandidaten | Namensscore (`matchScore` + Terminologie) × Lead-Priorität (`preferred_suppliers`/Stamm) × Vollständigkeit/Preis/Aktualität — **`LeadLaService::rangliste` spiegeln**. |
| E5 | **Klassifikator-Provider** | LLM via `AiGatewayService` (demo-OpenAI-Key vorhanden). Prompt = WaWi-`105`-Regelwerk (WG + Hauptzutat + Zustand/Form + `is_food`). Confidence + `needs_review` bei Pflichtangaben-Lücke. |

---

## 3. Etappen

| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| **S1** | **WG-Lead-Scope-Resolver** | ~~S~~ **0 (reuse)** | ✅ **Existiert:** `StammLieferantService::stammSupplierIdsFor(team, ?wgCode)` `:33` — WG-Code + global-NULL-Merge, genau der gewünschte Scope. Kein Neubau; nur aus S2 aufrufen. |
| **S2** | **LaCandidateFinder** | M | `find(team, ingredientName, ?wgCode, k)` → Scope via `StammLieferantService::stammSupplierIdsFor` (S1-Reuse) → `searchGlobal($text, ['supplier_id'=>ids])` → Re-Ranking mit `TerminologyService` (Alias/Anti-Marker) + `TokenEngine::matchScore` → Top-k. **Kein KI, deterministisch, schnell.** ✅ **Verifiziert 2026-07-20:** `SupplierItemService::baseQuery` `:59` wertet heute NUR `$filters['onlyActive']` aus → **einen `->when($filters['supplier_ids'] ?? null, fn($q,$ids)=>$q->whereIn('supplier_id',$ids))`-Zweig ergänzen** (ein Statement, kein Suchneubau). Damit greift der WG-Scope. |
| **S3** | **mintFromLa umstellen** | S | `searchGlobal(...)->items()[0]` ersetzen durch `LaCandidateFinder::find(team, $text, $wgHint, 3)->first()`. `$slug`/WG-Hint durchreichen (Param existiert). Fallback-Kaskade (E2). Verhalten byte-identisch, wenn kein WG-Hint + kein besserer Treffer (Regressions-Schutz). |
| **S4** | **On-demand-Klassifikator** | M | `ClassifyLaJob(supplierItemId)` (BulkEnrich-Muster): LLM klassifiziert den **einen** getroffenen LA → schreibt `supplier_item_structures` (WG/Hauptzutat/Zustand/Form/`classifier`/`classified_at`) + reichert den tentativen GP an → `needs_review` bei Lücke. **Nach dem Mint dispatched, nie inline.** Idempotent (schon klassifiziert → skip). |
| **S5** | **MCP-Lockstep** | S | `gps.MINT_FROM_LA` / `gps.MATCH` um optionalen `commodity_group`-Hint erweitern; Response nennt gewählten LA + `via` (lexical/terminology) + ob Klassifikation angestoßen wurde. |
| — | **S6 Batch-Klassifikation** | (optional) | Nur falls v1 zu grob: Lead-Lieferanten-Kataloge (117k) getaktet vorklassifizieren (WaWi-`105`-Batch). Bewusst NACH der Messung, nicht auf Verdacht. |

---

## 4. Definition of Done

- [ ] `LaFirstGpService::mintFromLa` wählt den Kandidaten **WG-Lead-gescoped + Terminologie-gerankt** statt `->items()[0]`; ohne WG-Hint + ohne besseren Treffer identisches Verhalten (Regressions-Test).
- [ ] Scope via `StammLieferantService::stammSupplierIdsFor` (WG-Code + global-NULL-Merge, existiert); leere WG → definierter Fallback (E2).
- [ ] Namensmatch nutzt `TerminologyService` (Alias/Anti-Marker) — „Paradeiser" findet den Tomaten-LA, „Brie" nicht den Bries-LA.
- [ ] On-demand-Klassifikation läuft **asynchron** (Queue), blockiert Mint/Grounding nicht; nur der getroffene LA wird klassifiziert; idempotent; `needs_review` bei §8-Lücke → ReviewQueue.
- [ ] Kein Vektor-Store/Embedding im Pfad (deterministisch); Perf: Finder < 300 ms warm im WG-Scope.
- [ ] MCP-Lockstep (`gps.MINT_FROM_LA`/`gps.MATCH`), team-scoped, Response transparent (gewählter LA + Herkunft).
- [ ] Pest: Scope-Resolver, Finder-Ranking, mintFromLa-Regression, ClassifyLaJob (async, idempotent, needs_review).

---

## 5. Reuse-vs-Neu

| Reuse | Neu |
|---|---|
| `mintFromLa`-Gerüst (Struktur-Reuse, Dedup-Guard, §6-Naming, `LeadLaService::verknuepfen`); `SupplierItemService::searchGlobal(filters[])`; **`StammLieferantService::stammSupplierIdsFor` (= S1-Scope-Resolver, existiert)**; `preferred_suppliers`-Tabelle; `LeadLaService::rangliste`-Ranking-Muster; `TerminologyService` + `TokenEngine::matchScore`; `BulkEnrichJob`-Async-Muster; `AiGatewayService`; `ReviewQueue`; `supplier_item_structures`-Felder | `LaCandidateFinder` (S2); `supplier_ids`-whereIn-Zweig in `baseQuery` (1 Statement); `ClassifyLaJob` + LA-Klassifikator-Service (WaWi-`105`-Spiegel); WG-Hint-Param in `mintFromLa` + MCP; Fallback-Kaskade (E2) |

---

## 6. Abhängigkeiten & Abgrenzung

- **Setzt Spec 07 (LA-First-Mint) voraus** — LIVE. Dieser Finder schärft dessen Kandidatenwahl.
- **Entkoppelt Spec 15 (LA-Vektor-Pool):** Für DIESEN Use Case NICHT nötig. Der 264k-Vektor-Pool + Qdrant-Frage bleiben verschoben, bis echte Freitext-Discovery über den ganzen Katalog gefordert ist (separates, niedriger priorisiertes Thema).
- **Nutzt den Weg-2-Terminologie-Stack** (#507 E7, LIVE) als Namensmatch-Motor.
- **Klärungsbedarf vor Baustart (fachlich, Dominique):** E1 WG-Hint-Quelle · E2 Fallback-Reihenfolge · E4 Ranking-Gewichte.

*Spec 2026-07-20, aus Dominique-Use-Case „Artikel unter WG-Leads finden". Einstieg nächste Session: S1→S2→S3 (Finder), dann S4 (on-demand-Klassifikation).*
