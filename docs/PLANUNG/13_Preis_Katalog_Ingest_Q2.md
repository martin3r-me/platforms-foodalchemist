# Preis-/Katalog-Ingest — die Daten-Eingangs-Schnittstelle (Q2, Ex-Necta)

> **ROADMAP-Bezug:** Q2 (Querschnitt, „laufend"), Größe M — in der ROADMAP nur 3 Zeilen, aber **Kern-Infrastruktur**: ohne frische Lieferanten-Preise/Kataloge verliert die ganze Wirtschaftlichkeits-Maschine (R2) ihre Grundlage, und **R2.3 Menu-Engineering ist hart darauf gegated** (Verkaufs-Ist-Import).
> **Prinzip:** FA ist **Master** — der Ingest ist eine reine EINGANGS-Schnittstelle. Es gibt keinen VK-/Daten-Rückweg nach draußen.

---

## 1. Warum Kern (Einordnung)

Seit Necta raus ist, gibt es keinen automatischen Preis-Strom mehr. Alles, was auf EK sitzt — Kalkulation, Auto-VK, Preis-Alarm (R2.1), Simulation (R2.2), Menu-Engineering (R2.3), L8-Wirtschaftlichkeits-Glied — rechnet nur so gut, wie die Preise frisch sind. Q2 ist damit kein „Import-Feature", sondern die **Nabelschnur der Engine**.

**Zwei Ströme, ein Muster:**
1. **Lieferanten-Seite (EK):** Kataloge + Preislisten je Lieferant → `supplier_items`/`prices`.
2. **Verkaufs-Seite (Ist):** Bankett-/Verkaufsdaten (CSV/Excel, z.B. Bankettprofi) → speist R2.3. *(Format-Spec = das offene Gate, s. [12](12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md).)*

## 2. DoD (aus ROADMAP + Kontext)

- [ ] Bestehende Import-Pipeline als reine **EINGANGS-Schnittstelle dokumentiert** (kein VK-Rückweg — FA ist Master).
- [ ] **Katalog-Import-Lücken geschlossen** (z. B. Grønn → entsperrt Petersilienöl 7900) — bekannte Lücken als Liste führen + abarbeiten.
- [ ] **Preis-Import triggert R2.1-Alarm** (neuer EK → Marge-Impact-Signal, keine stille Drift).
- [ ] *(Erweiterung, für R2.3):* **Verkaufs-Ist-Import-Format-Spec** dokumentiert + 1 echte Beispieldatei eines BHG-Caterers geladen (Wording-Matcher-Muster Skript 250 für das Zeilen-Matching; Unmatched → Review-Queue).
- [ ] *(Hygiene):* Import idempotent + resumefähig; nach Preis-Import Recompute der betroffenen Ketten (Lead-LA → GP → Rezepte), Signale statt stiller Änderungen.

## 3. Offene Vorfragen (vor Baustart)

1. **Quellen-Inventar:** Welche Lieferanten liefern heute wie? (PDF-Preisliste / Excel / Portal-Export / gar nicht) — Bestandsaufnahme je Stamm-Lieferant.
2. **Format-Spec Verkaufs-Ist** (Bankettprofi-Export o.ä.) — das harte R2.3-Gate.
3. **Frequenz + Verantwortung:** wer lädt wann (manuell je Quartal? Watchfolder? Mail-Ingest?) — bewusst einfach starten.

## 4. Bewusste Nicht-Ziele
- **Kein Rückkanal** (FA schreibt nichts zu Lieferanten/Necta zurück).
- **Kein Bestell-/Wareneingangs-Prozess** (N-Track/Nachbar-Modul).
- Kein Voll-EDI in v1 — Datei-basierte Ingests reichen, Muster existieren (Skripte 92/250).

*Verzahnt: R2.1 (Alarm-Trigger), [12](12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md)/R2.3 (Gate), [14](14_Lieferanten_Management_R9.md)/R9 (Konditionen ↔ Preise), Skripte 92 (supplier_priorities) + 250 (Wording-Matcher). Erstellt 2026-07-18.*
