# Spec-Status-Matrix (01–16)

> Schnell-Sicht: Status + Blocker je Kern-Spec. Detail je Spec in der jeweiligen Datei; Reihenfolge/Phasen in [00_Orchestrierung_Naechste_Schritte.md](00_Orchestrierung_Naechste_Schritte.md).
> Stand **2026-07-20**. Legende: 🟢 bau-reif · 🟡 entscheidungs-reif (benannter Blocker) · ⚪ Dossier · ✅ fertig.

| # | Spec | Status | Blocker / Offen |
|---|---|---|---|
| **01** | Editor-Strecke #511/#509 | ✅ gebaut+gepusht (`0eac4fe`) | Live-Klickstrecke → **demo-Deploy (Martin)** |
| **02/02b** | RAG-Layer #507 (semantische Suche) | ✅ gebaut, Go-Live-Runbook liegt | **Martin: OpenAI-Key (Core-Contract) + `update.sh`-Deploy** → dann Backfill/Floor/Flag |
| **03** | KI-Erstell-Flächen #512 (L1–L8) | ⚪ Spec, ungebaut | Bau gegen **Fake-Provider jetzt möglich**; Qualitäts-Gate braucht Key live. L4/L5 rein deterministisch |
| **04** | Grammaturen-Rechner #513 | ✅ **KOMPLETT** | nur Browser-Klickstrecke Bäcker-%-UI (Deploy) |
| **05** | Datenqualitäts-Kaskade | 🟢 Etappe 1 + P5 ✅ | Etappe-2-Bau (KI-Commands) jetzt möglich; echtes Daten-Heilen → **Key live + demo-Daten** |
| **06** | Convenience-Highlights | ✅ **KOMPLETT** (`4de1ca2`) | — |
| **07** | LA-First-GP-Mint überall | ✅ **KOMPLETT** M1–M4 | — |
| **08** | Planungs-/Kreativ-Ebene (Doppel-Diamant) | ⚪ ungebaut | Bau gegen Fake möglich; **Konvergenz-Qualität braucht LLM-Provider live** (einziger echter Rest-Blocker im Ordner) |
| **09** | Pairing-Offense-Trio (R6.8/6.9/6.10) | ✅ **KOMPLETT gebaut** | R6.10 *produktiv* = Q1-Contract (extern/N-Track) |
| **10** | Angebots-Funnel / Brief-Parser R6.2 | 🟢 bau-reif | Bau gegen Fake möglich; scharf erst **nach #492-Blindtest** (Key live) |
| **11** | Hypothesen-/Widerspruchs-Modus R6.11 | ✅ **S1–S4 gebaut 2026-07-19** | E5 verifiziert. S1 Hypothesen (Aroma-Harmonie) · S2 Widerspruchs-Detektor · S3 Lab-Notes-Senke · S4 Kontrast-Hypothesen (Geschmacks-Gegensatz + kuratierte kontrast-Kanten); 3 MCP-Tools, 14 Pest. Einziger Rest = optionales KI-Narrativ (Provider) |
| **12** | Wirtschaftlichkeits-Intelligenz R2-Rest | R2.5 ✅ · R2.4 🟢 · R2.3 🟡 | **R2.4-Solver blocker-frei baubar**; R2.3 gated auf **Q2-Format-Spec** |
| **13** | Preis-/Katalog-Ingest Q2 | Kanal-B 🟢 · Sales-Ist 🟡 · Kanal-A ⚪ | **Kanal-B-Import blocker-frei baubar** (bewusst hinten angestellt); Sales-Ist gated auf echte Beispieldatei; Kanal A extern |
| **14** | Lieferanten-Management R9 | ✅ **KOMPLETT** (Engine+MCP+UI, `1382fc2`) | echtes Spend = Q2 (Nutzungs-Proxy ist v1) |
| **15** | Semantische Suche über Lieferantenartikel (Supplier-Item-Pool) | ⚪ Dossier — **entkoppelt/zurückgestellt via 16** | RAG-Nachzug (Vektor-Pool + Observer + Retrieval); vom WG-Lead-Use-Case (16) NICHT gebraucht → wartet auf echte Freitext-Katalog-Discovery + 50k-Store-/Qdrant-Frage |
| **16** | WG-Lead-gescopter LA-Kandidaten-Finder + On-demand-Klassifikation | 🟢 **bau-reif** (code-verifiziert 2026-07-20) | **Blocker-frei baubar** (Finder deterministisch, kein Provider). S1-Scope-Resolver existiert bereits (`stammSupplierIdsFor`) → Reuse; on-demand-Klassifikator (S4) = einziges neues Stück, nutzt Provider async. Schärft Spec-07-`mintFromLa`; fachlich: E1/E2/E4 mit Dominique |

## Verdichtet

- **Sofort baubar, null Blocker:** 12·S2 (R2.4-Solver) · 13·S1 (Kanal-B) · 03·L4/L5 · **16·S2/S3 (LA-Finder, deterministisch — S1 ist Reuse)**.
- **Baubar gegen Fake-Provider (Qualitäts-Gate später via Key):** 03·L1/L2/L3/L6/L7 · 08 · 10 · 05·Etappe-2.
- **Einziger echter Rest-Blocker im Ordner:** 08 (Konvergenz-Qualität = LLM live). Alle übrigen „Blocker" sind Deploy-/Key-Gates fürs *Live-Feuern*, nicht fürs Bauen.
- **Fertig:** 04 · 06 · 07 · 09 · 11 (S1–S3; nur optionales KI-Narrativ offen) · 14 (+ 01 nur Deploy-Verifikation).

### Korrektur-Log
- **2026-07-19:** „Chem-Import fehlt" (Spec 11) war eine **Fehlannahme aus veraltetem Memory** — an der Dev-DB verifiziert, dass `foodalchemist:import-master` durch ist und die 26 Chemie-/Pairing-Tabellen voll befüllt sind. Spec 11 damit von 🟡 auf 🟢.
