---
title: Speisekarte
order: 8
---

# 🍽️ Speisekarte

Die Speisekarte ist die **Gastronomie-Seite** des Moduls — die klassische
Restaurant-à-la-carte-Karte. Neben dem [Foodbook](foodbook) (Catering-Angebot) und dem
[Speiseplan](speiseplan) (Gemeinschaftsverpflegung) deckt sie den dritten Absatzkanal ab.

Eine Karte ist aufgebaut aus **Rubriken** (Vorspeisen · Hauptgänge · Desserts · Getränke …,
beliebig verschachtelt) und **Positionen** darin:

- **Gericht** — ein einzelnes VK-Gericht mit eigenem Preis pro Position.
- **Fix-Menü** — ein Mehrgänger (3-Gang-Menü …) zum Pauschalpreis, referenziert ein
  [Concept](concepter); die Gänge werden auf der Karte darunter aufgelistet.
- **Getränke/Wein** — ein Getränke-Gericht; Glas-/Flaschengrößen als Preis, Wein-Metadaten
  (Jahrgang · Region · Rebsorte) am Eintrag.

---

## Was automatisch mitkommt

Weil die Karte auf denselben Bausteinen steht wie der Rest des Moduls:

- **Preise** kommen aus der Darreichung des Gerichts bzw. dem Concept-Preis (manuell
  übersteuerbar) und werden — Gastro-typisch — **brutto** angezeigt (inkl. MwSt).
- Die **Allergen- und Zusatzstoff-Kennzeichnung** (LMIV/ZZulV) entsteht aus den Gerichten
  automatisch als Fußnoten (A, B … / 1, 2 …, `*` = Spuren) samt Legende — der gesetzlich
  verbindliche Kern jeder Gastronomie-Karte.
- **Wording** (der Gast-Name) läuft über dieselbe Kette wie im Foodbook; ein KI-Vorschlag
  in Brand-Voice ist pro Position abrufbar.

---

## Drucken & Präsentieren

Jede Karte liefert ein **druckbares Dokument** (HTML/PDF) mit Branding (Farbe, Logo,
Titelbild, Fußzeile) und der Kennzeichnungs-Legende, sowie eine **Web-Präsentation** für
den Gast. Eine **Gültigkeit** (ab/bis) und der **Kartentyp** (à la carte, Tages-, Saison-,
Getränke-, Weinkarte) steuern Layout und Einsatz; per **Duplizieren** wird aus einer Basis
schnell eine Wechsel-/Saisonkarte.

---

## Leitstelle

Ein kleines Cockpit im Editor leitet ab, ob die Karte fertig ist: sind Rubriken und
Positionen da, alle **Preise gesetzt**, die **Allergene bekannt**, das Branding gepflegt —
und meldet „bereit", sobald die harten Punkte stehen.

> **Die Logik dahinter:** Foodbook verkauft ein Anlass-Angebot, der Speiseplan füllt die
> Zeitachse — die Speisekarte ist das stehende Restaurant-Sortiment. Drei Ausgabeformen,
> ein Rezept-/Gericht-Fundament.
