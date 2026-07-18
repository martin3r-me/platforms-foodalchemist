# Lieferanten-Management — die kommerzielle Beziehungs-Ebene (R9)

> **ROADMAP-Bezug:** R9.1 + R9.2 (eigener Track; Dominique-Wunsch 2026-07-05). **Ziel:** Die Beziehung zu einem Lieferanten aktiv **steuern** — Verträge, Konditionen, Absprachen, Zusagen, wer wofür Lead ist. Heute mündlich/verstreut, im System nicht führbar.
> **Scope-Grenze ✅ ENTSCHIEDEN (Dominique 2026-07-18):** R9 = **nur** kommerzielle/strategische Ebene („**mit wem zu welchen Bedingungen**"). **NICHT** operatives Bestellen/Wareneingang/Lieferscheine/Rechnungskontrolle — das bleibt N-Track/Nachbar-Modul („was wann bestellt"). Entscheid #1 ist damit gefallen; die R9-Planungs-Session startet direkt mit der Kartierung.

---

## 1. Vorhandener Kern (Startpunkt, kein Neubau)

- `lead_la_supplier_item_id` + `pick_lead_la`-Heuristik (Lead je GP — heute nur Skript/Heuristik, unbedient).
- `supplier_priorities` (Umsatz-Ranking, Import Skript 92 aus Rückvergütungs-Forecast).
- `stamm_lieferant` + `stamm_lieferant_wg` (Lieferant×Warengruppe-Matrix).

→ R9 **bündelt und bedient** das, statt es in Skripten zu lassen.

## 2. R9.1 — Lieferanten-Stammblatt + Absprachen-Log · L · hängt an nichts (FA-nativ)

**DoD:**
- [ ] Lieferanten-Detailseite: Kontakte, Rollen, Status (aktiv/Zweitquelle/gesperrt), WG-Abdeckung (aus `stamm_lieferant_wg`).
- [ ] **Absprachen-/Zusagen-Log** je Lieferant: datierte Einträge (Konditionszusage, Sonderpreis, Liefertreue-Absprache …), Wiedervorlage/Erinnerung, Autor.
- [ ] **Vertrags-/Dokumenten-Ablage** (Rahmenvertrag, Konditionsblatt, Preisliste) mit Laufzeit + Kündigungsfrist → **Fristen-Signal** (R2.1-Signale-Muster).
- [ ] Konditionen strukturiert: Rückvergütung/Bonus %, Zahlungsziel, Mindestbestellwert, Frei-Haus-Grenze — feeds spätere EK-/Marge-Betrachtung.
- [ ] Team-scoped, LogsActivity, MCP-Tools (`suppliers.GET/PUT`, `supplier_agreements.POST`) — Lockstep.

## 3. R9.2 — Lead-Lieferant-Steuerung als bediente Strecke · M · hängt an R9.1 + R1

**DoD:**
- [ ] Lead-LA je GP/Warengruppe **sichtbar + überschreibbar** in der UI — mit Begründungs-Vermerk bei manuellem Override.
- [ ] Vorschlag = `pick_lead_la` (Vollständigkeit > Aktualität > Preis-pro-Einheit); **Mensch bestätigt/übersteuert**, Entscheid protokolliert.
- [ ] Zweit-/Ausweichquelle je GP hinterlegbar (Ausfall-Absicherung).
- [ ] Auswertung: Volumen/Umsatz je Lieferant (`supplier_priorities`) × Konditionen → **„wo lohnt Bündelung / Nachverhandlung"**.
- [ ] Test: Override setzt Lead korrekt, Recompute nutzt neuen Lead-EK; Historie nachvollziehbar.

## 4. Verzahnung mit dem Kern

- **[13](13_Preis_Katalog_Ingest_Q2.md)/Q2:** Konditionen (R9) × frische Preise (Q2) = ehrliche EK-Wahrheit; Preis-Import kann Absprachen-Verstöße sichtbar machen („Sonderpreis zugesagt, Katalog sagt anderes").
- **[07](07_LA_First_GP_Mint_ueberall.md)/LA-First:** Sourcing-Wünsche (keine LA gefunden) landen sinnvoll beim Lieferanten-Management — „bei wem beschaffen?" ist eine R9-Frage.
- **06/Convenience-Highlights:** „Lieblings-Lieferanten-Produkte" — die Highlight-Kuratierung kann R9-Prioritäten (Umsatz/Konditionen) als Score-Input nutzen.
- **R2:** Konditionen fließen langfristig in die Marge-Betrachtung (Rückvergütung ≠ Listen-EK).

## 5. Bewusste Nicht-Ziele
- **Kein Bestellen/Wareneingang/Rechnungsprüfung** (N-Track) — die Scope-Grenze ist Entscheid #1 vor Baustart.
- Keine automatische Lead-Umschaltung ohne Menschen (Vorschlag ja, Commit menschlich).
- Kein CRM-Doppel zum Office-CRM — FA führt die *warenwirtschaftliche* Beziehung (Konditionen/Lead), nicht den Vertriebskontakt.

*Verzahnt: [13](13_Preis_Katalog_Ingest_Q2.md), [07](07_LA_First_GP_Mint_ueberall.md), 06, R2.1-Signale, Skript 92, `pick_lead_la`/Skript 213. Erstellt 2026-07-18.*
