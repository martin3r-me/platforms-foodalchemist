# Angebots-Funnel-Anfang — Brief-Parser (R6.2)

> **ROADMAP-Bezug:** R6.2 (Track „Alleinstellung"), Größe L, hängt an R6.1 (Brief→Konzept, gebaut).
> **Idee:** Die Kunden-Anfrage (Mail/Formular) automatisch in ein strukturiertes Event-Brief übersetzen — der Einstiegs-Trichter, der direkt in die R6.1-Konzeptgenerierung mündet. FA liefert **zu**, die Angebots-Führung bleibt beim Event-Modul.
> **Reifegrad: 🟢 bau-reif** (Code-Kartierung verifiziert 2026-07-19). Vorher ⚪ Dossier.

---

## 0. Code-Kartierung (verifiziert 2026-07-19)

**Die KI-Plumbing steht komplett — der Parser ist ein neuer Prompt + Fassade, kein Neubau:**
- `AiGatewayService::propose(string $promptKey, array $context=[], array $options=[]): AiProposal` `src/Services/Ai/AiGatewayService.php:50`. Prompt-Lookup `config('foodalchemist.prompts')[$key]` `:59`; Kill-Switch `KiDeaktiviertException` `:52`; Fake-Provider-Pfad `:437`; `structural_retry`-Callable im Temp-Treppen-Loop `:144`; jeder Call schreibt eine `ai_call_log`-Zeile.
- **Prompt-Registry** `config/foodalchemist.php` → `'prompts'` `:300`. Vorlage `concept.brief_geruest` `:537` (system: „erfindet NICHTS, fehlende Angaben weg" — direkt R6.2-Haltung; JSON-Schema inline im `task`-String). Antwort-Konvention modulweit: `{werte, confidence 0-1, reasoning}`.
- **`AiProposal`** `src/Services/Ai/AiProposal.php:11` hat nur EINE skalare `confidence`. → **Per-Feld-Konfidenz muss in `werte` liegen** (Gateway reicht `werte` unverändert durch, `:182`). Präzedenz für Per-Feld-Konfidenz existiert als Spalten (`SupplierItemStructure.main_ingredient_confidence` u.a.), aber im DTO nur skalar.
- **Klick-Ziel R6.1:** `ConceptGeneratorService::generiereAusBrief(Team, string $brief, ?name, via='ui', bool $useFavoritesList=false)` `:70` — nimmt **rohen Freitext**, leitet das Gerüst intern via `propose('concept.brief_geruest')` neu ab. **Kein struktureller Brief-Overload** (nur `generiereAusGeruest(…, FoodAlchemistPlanningFrame,…)` ist strukturiert).
- **Feld-Modell existiert bereits im Angebote-Modul:** `FoodAlchemistAngebot` (Tabelle `foodalchemist_offers`) casts `personen`, `budget`, `event_date`, `status` (`AngebotStatus::anfrage` = Intake); `Angebote\DetailPanel.php:23-27` editiert exakt `occasion, personen, budget, event_date, location, diet_requirement, brief`. → **das ist der natürliche Persistenz-Zielpunkt** für ein geparstes Brief (⚠️ Ownership-Grenze s. E2).
- **MCP:** read-only-Tool-Muster `CoverageGetTool`/`ConceptsGetTool`; Registrierung im `$toolHook`-Array `src/FoodAlchemistServiceProvider.php:~324`. Kein `briefs.*`-Tool vorhanden → neu.
- **UI-Vorlage:** Brief-Modal `Concepts\Index.php:38-87` + View `concepts/index.blade.php:58-95` (Textarea → `generatorStart` → Ergebnis-Panel). **Kein Mail-/Inbox-Intake** vorhanden → der Parser ist die neue Intake-Fläche.
- **Tests:** `AiGatewayTest.php` (fake-provider), `AiRetrySchutzTest.php` (skript-Provider + `structural_retry`-Muster), `ConceptGeneratorTest.php:133-197` (gebundener `LLMProviderContract` mit kontrollierter JSON-Antwort — Muster für einen deterministischen Parser-Test).

---

## 1. Der Flow

```
Kunden-Mail / Formular-Text
  → briefs.PARSE (KI, strukturiert): Anlass · Gäste · Budget p.P. · Diät · Termin
    — je Feld {wert, konfidenz}
  → unsichere Felder = Rückfrage-Liste (NICHT geraten)
  → Mensch bestätigt/ergänzt die Felder
  → ein Klick: Brief → R6.1 generiereAusBrief → Konzept-Draft
```

## 2. Festgezurrte Entscheidungen (2026-07-19)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| E1 | Per-Feld-Konfidenz technisch | **In `werte` genestet:** `werte = {felder: {anlass:{wert,konfidenz}, gaeste:{…}, budget_pp:{…}, diaet:{…}, termin:{…}}, rueckfragen: [string]}`. Kein DTO-Umbau. | `AiProposal` kann nur skalar; Gateway reicht `werte` durch. Idiomatisch (Per-Feld-Konfidenz-Präzedenz existiert). |
| E2 | Persistiert der Parser nach Angebote? | **v1: NEIN — Parser liefert das strukturierte Brief + Rückfragen an die UI, ein Klick → `generiereAusBrief` (roher Brieftext).** Optionales Speichern in einen `foodalchemist_offers`-Draft als Ausbaustufe, bewusst hinter der Ownership-Grenze. | Dossier-Grenze: Angebots-FÜHRUNG = Event-Modul, FA liefert nur zu. Angebote liegt heute noch in FA → Grenze markiert, nicht verdrahtet. |
| E3 | Struktur-Felder oder Rohtext an R6.1? | **v1: Rohtext** (der bestätigte/ergänzte Brief als String) an `generiereAusBrief`. Struktur-Felder dienen der Anzeige/Rückfrage, nicht als zweiter Extraktions-Input. | Kein struktureller Overload vorhanden; doppelte Extraktion vermeiden; R6.1 bleibt die eine Gerüst-Wahrheit. |
| E4 | Zeitpunkt scharfstellen | **Baubar sofort (fake-provider-getestet); produktiv scharf erst nach R6.1-Blindtest #492** (sonst füttert man einen ungetesteten Generator). | Dossier-Vorgabe; echtes E2E braucht Provider auf demo (Martin). |

## 3. Etappen

| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| **S1** | Prompt + Service | S | Prompt `briefs.PARSE` in der Registry (system: „nur was die Mail hergibt, nichts erfinden, unsicher → Rückfrage"; `task` beschreibt `werte.felder` + `rueckfragen`); `BriefParserService::parse(Team, string $text): array` (wrappt `propose`, `structural_retry` = mind. 1 Feld ODER 1 Rückfrage). |
| **S2** | MCP | S | `BriefsParseTool` (`foodalchemist.briefs.PARSE`, read-only, `cost_class=llm_call`) — Mail-Text rein, `{felder, rueckfragen}` raus; register im Provider. Brief→Konzept-Kette über bestehendes `concepts.GENERATE`. → **ganzer Funnel agentisch fahrbar.** |
| **S3** | UI | S–M | Brief-Parser-Modal (Klon des Concepts-Generator-Blocks): Mail einfügen → parse → Felder + Konfidenz-Badges + Rückfrage-Liste (editierbar) → Button „→ Konzept generieren" ruft `generiereAusBrief`. |

## 4. DoD

- [ ] Kunden-Anfrage → strukturiertes Event-Brief (Anlass, Gäste, Budget p.P., Diät, Termin) mit **Konfidenz je Feld** (`werte.felder`, E1).
- [ ] Unsichere Felder als **Rückfrage-Liste**, nicht geraten (system-prompt-erzwungen, Muster `concept.brief_geruest`).
- [ ] Brief mündet direkt in R6.1 (**ein Klick:** bestätigter Brief → `generiereAusBrief` → Konzept-Draft, E3).
- [ ] **Grenze eingehalten:** Angebots-FÜHRUNG bleibt Event-Modul — FA liefert Brief + Konzept zu (Zuarbeit dokumentiert, E2).
- [ ] **MCP-Pflicht (00-Invariante):** `briefs.PARSE` als read-only-Tool + Kette über `concepts.GENERATE`.
- [ ] Pest: fake-/skript-Provider — Extraktion + Rückfrage-Fall + `structural_retry`; kein echter Provider nötig für den Struktur-Nachweis.

## 5. Reuse-vs-Neu

| Reuse (vorhanden) | Neu bauen |
|---|---|
| `AiGatewayService::propose` + Prompt-Registry + `structural_retry` + Fake-Provider; `generiereAusBrief`; `concepts.GENERATE`; read-only-Tool- + Modal-Muster; `foodalchemist_offers`-Feldmodell (optionaler Persist) | Prompt `briefs.PARSE`, `BriefParserService`, `BriefsParseTool`, Brief-Parser-Modal, `werte.felder`-Konvention |

## 6. Abhängigkeiten + Einordnung
- **Hängt an R6.1** (gebaut; Blindtest #492 offen) — scharf erst nach dem Blindtest (E4).
- **Kein neues Grounding** — reine Struktur-Extraktion; Konzept-Logik bleibt in R6.1 deterministisch.
- Konzeptuelle Vorlage: `briefing_parser`-Skill (CJ, Vault — nicht als Code hier).

## 7. Bewusste Nicht-Ziele
- Keine Angebots-Führung/CRM-Funnel in FA — nur Brief-Zuarbeit (E2).
- Kein Raten — unsichere Felder werden gefragt.
- Kein Auto-Versand/Preis-Commit — FA liefert Draft, nichts geht raus.

*Verzahnt: R6.1 `ConceptGeneratorService`, [08](08_Planungs_und_Kreativ_Ebene.md), N-Track (Event-Modul). Dossier 2026-07-18, bau-reif 2026-07-19.*
