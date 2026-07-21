# Spec-Status-Matrix (01–17)

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
| **16** | WG-Lead-gescopter LA-Kandidaten-Finder + On-demand-Klassifikation | ✅ **GEBAUT+GETESTET 2026-07-20** (S1–S5 + Nachzügler, 16+ Pest) | Finder deterministisch live (schärft Spec-07-`mintFromLa`); WG-Scope + Terminologie + **Compound-Anti-Marker (S3 geschlossen)** + Fallback; **WG-Hint im KI-Schema scharf (E1)**. Rest nur noch provider-gated (demo): LLM-`commodity_group`-Emission + S4-ClassifyLaJob-Inhalt |
| **17** | Bestellwesen — mini-WaWi Bestellassistent (N-Track) | 🟢 **S0 GEPUSHT** (`0d78bd2`) · **S1 GEBAUT** 2026-07-21 · S2–S3 offen (E1–E11) | Der von [R9 §7](14_Lieferanten_Management_R9.md) ausgeklammerte N-Track, **OHNE Bestand**. S0 = `GebindeRechner` + Gebinde-Bestellzeile + „N Portionen"-Fix + Einkauf-Blatt raus. S1 = `suppliers.delivery_days`/`order_cutoff_time`/`order_lead_days` + SupplierDetail-Tab + `suppliers.PUT`. **S2 (persistente Bestellschiene `orders`/`order_lines` + OrderService + UI) = Kern, als Nächstes.** Dev #549 |

## Verdichtet

- **Sofort baubar, null Blocker:** 12·S2 (R2.4-Solver) · 13·S1 (Kanal-B) · 03·L4/L5 · **17·S0 (Gebinde-Bestellzeile + Blatt-Fix)**. **(16 = gebaut 2026-07-20.)**
- **Baubar gegen Fake-Provider (Qualitäts-Gate später via Key):** 03·L1/L2/L3/L6/L7 · 08 · 10 · 05·Etappe-2.
- **Einziger echter Rest-Blocker im Ordner:** 08 (Konvergenz-Qualität = LLM live). Alle übrigen „Blocker" sind Deploy-/Key-Gates fürs *Live-Feuern*, nicht fürs Bauen.
- **Fertig:** 04 · 06 · 07 · 09 · 11 (S1–S3; nur optionales KI-Narrativ offen) · 14 (+ 01 nur Deploy-Verifikation).

### Korrektur-Log
- **2026-07-19:** „Chem-Import fehlt" (Spec 11) war eine **Fehlannahme aus veraltetem Memory** — an der Dev-DB verifiziert, dass `foodalchemist:import-master` durch ist und die 26 Chemie-/Pairing-Tabellen voll befüllt sind. Spec 11 damit von 🟡 auf 🟢.
