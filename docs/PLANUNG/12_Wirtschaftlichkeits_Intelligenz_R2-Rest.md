# Wirtschaftlichkeits-Intelligenz — R2-Rest (Analyse · Optimierung · Preis-Governance)

> **ROADMAP-Bezug:** R2.3 + R2.4 + R2.5 (Track „Wirtschaftlichkeits-Maschine"). Der Rest der Engine — R2.1 Preis-Alarm, R2.2 Simulation, R2.6 Feedback, R2.7 Benchmark sind **gebaut**.
> **Rote Linie:** aus den Zahlen **lesen** (R2.3), aus dem Portfolio **lösen** (R2.4), Preise **verantwortet veröffentlichen** (R2.5). Alles intern-vorschlagend, nie Auto-Commit.

---

## 1. R2.3 — Menu-Engineering mit Ist-Zahlen · XL · hängt an R1 + Datenquellen-Entscheid

Popularität × Deckungsbeitrag über echtes Verkaufs-Ist.

⚠️ **Blocker-Vorfrage (VOR Baustart klären):** Woher kommen Verkaufs-/Bankettdaten, seit Necta raus ist? Realistisch CSV/Excel-Export (Bankettprofi o.ä.). **Format-Spec muss stehen, sonst bauen wir einen Import ins Blaue.** → verzahnt mit Q2 (Preise/Kataloge-Ingest).

**DoD:**
- [ ] Import-Format-Spec dokumentiert + Beispieldatei eines echten Caterers erfolgreich geladen.
- [ ] Matching Verkaufsposition → VK-Gericht mit **Review-Queue für Unmatched** (kein stilles Raten — Wording-Matcher-Muster aus Skript 250 wiederverwenden).
- [ ] **Stars/Renner/Schläfer/Penner-Matrix** (Popularität × DB) je Konzept/Zeitraum.
- [ ] DB-Ranking + W%-Ampeln übers Portfolio, filterbar nach Facetten.
- [ ] ≥1 echter BHG-Caterer-Datensatz durchgelaufen, mit Dominique plausibilisiert.

## 2. R2.4 — Marge-optimale Menü-Assemblierung · XL · hängt an R1 + R4.1

Aus dem Portfolio **lösen** statt raten: Rahmen rein → DB-maximale Kombination raus.

**DoD:**
- [ ] Solver: Zielpreis p.P. + Gästezahl + Coverage-Constraints (Diät-Quoten, Gang-/Stations-Gerüst, Preisspannen) → DB-maximale Kombination **nur aus echten VK-Gerichten**.
- [ ] Keine Halluzination; Slot ohne zulässigen Treffer bleibt leer + Begründung.
- [ ] Lösung **erklärt sich**: welche Constraints bindend, wie weit vom Optimum bei Lockerung X.
- [ ] MCP-Tool `assemblierung.POST` (read-only-Semantik) — KI spielt Varianten durch.
- [ ] Übernahme nur explizit (`status=draft`), kein Auto-Commit.
- [ ] Perf: Portfolio ~1.000 Gerichte < 15 s.
- [ ] Test: kleiner Constraint-Satz mit hand-gerechneter Optimallösung exakt reproduziert.

*Bezug: knüpft an die R4-Planungs-Ebene (Gerüst = Constraints) + [08](08_Planungs_und_Kreativ_Ebene.md) (der „Go" könnte den Solver rufen) an.*

## 3. R2.5 — Saison-Auto-Pricing (intern-vorschlagend) · M · hängt an R2.1 + R3.1

Löst den Vertrauensbruch durch **Trennung**: interne Live-Marge ↔ veröffentlichter, freigegebener VK.

**DoD:**
- [ ] Saubere Trennung: interne Marge (EK live aus Resolver) ↔ veröffentlichter VK = **freigegebener Snapshot**.
- [ ] Trigger: Marge verlässt team-konfigurierbares Zielband → Signal „VK-Anpassung empfohlen: N Gerichte, Richtung + Delta" (R2.1-Muster).
- [ ] Freigabe **menschlich + als Batch**: ein Klick veröffentlicht neuen VK-Snapshot; kein stiller Kunden-Preissprung.
- [ ] Kundensicht (R3.2) zeigt ausschließlich freigegebenen VK; Verfügbarkeit/Allergene bleiben live.
- [ ] Leitplanken konfigurierbar: Mindestmarge, max. VK-Delta je Freigabe.
- [ ] Test: EK-Sprung → Signal korrekt; OHNE Freigabe bleibt veröffentlichter VK unverändert.

---

## 4. Reihenfolge + Abhängigkeiten

```
Q2/Datenquellen-Format-Spec ──► R2.3 (sonst Import ins Blaue)   ← HARTES Gate für R2.3
R1 (bepreiste Masse) ✅ ──► R2.3, R2.4
R4.1 Gerüst ✅ ──► R2.4 (Constraints)
R2.1 ✅ + R3.1 ✅ ──► R2.5
```

**Empfehlung:** R2.5 zuerst (kleiner, unblocked — R2.1/R3.1 fertig, reiner Preis-Governance-Layer) → R2.4 (Solver, braucht nur R1/R4.1) → R2.3 zuletzt (erst wenn die Import-Format-Spec / Q2 steht).

## 5. Bewusste Nicht-Ziele
- **Kein Auto-Publish** von Preisen — Freigabe immer menschlich, als Batch (R2.5).
- **Keine Halluzination** im Solver — nur echte VK-Gerichte, Lücke bleibt Lücke.
- **Kein Import ins Blaue** — R2.3 erst mit dokumentierter Format-Spec.
- Kein Vertriebs-/Angebots-Funnel in FA (Grenze zum Event-Modul; s. `10`).

*Verzahnt: R1 (Masse), R4.1 (Constraints), R2.1/R3.1 (gebaut), Q2 (Daten-Ingest, Gate für R2.3), [08](08_Planungs_und_Kreativ_Ebene.md) (Solver am „Go"). Erstellt 2026-07-18.*
