---
title: Einstellungen
order: 9
---

# ⚙️ Einstellungen

In den Einstellungen legst du fest, nach welchen Regeln das Modul ordnet und rechnet. Drei Dinge wohnen hier:

> **Bereiche-Navigation:** Die Liste links ist entlang der Arbeits-Kaskade sortiert — erst Stammdaten & Vokabulare, dann Einkauf/Kalkulation/Preise, dann KI & Kreativ-Steuerung, dann Wissen, Produktion und zuletzt Ausgabe & Betrieb. Bewusst **ohne** feste Gruppen (mehrere Bereiche gehören funktional in zwei Töpfe zugleich); ein **Filter** oben blendet die Liste live auf den gesuchten Bereich ein (matcht Name **und** Kurzbeschreibung).

---

## 🗂️ Taxonomien

Die Ordnungssysteme, nach denen Grundprodukte, Rezepte und Gerichte eingeordnet werden — jeweils als Baum (Hauptgruppe → Kategorie/Klasse):

- **Konzept-Taxonomie** — Kategorien und Klassen für Concepts.
- **Warengruppen** — die Gliederung der Grundprodukte (Fleisch, Molkerei, Getränke …).
- **VK-Taxonomie** — die Speisen-Klassen für Gerichte (Verkaufsseite).

Saubere Taxonomien sorgen dafür, dass der [Gericht-Baum-Picker](concepter) im Concepter und die Filter überall sinnvoll greifen.

---

## 💶 Kalkulations-Sätze

Hier hinterlegst du die **Fixkosten** und die daraus abgeleiteten **Zuschlagssätze** (Material-, Fertigungs-, Verwaltungs-Gemeinkosten). Diese Werte speisen die mehrstufige Rechnung von HK1 zu HK2 — siehe [Kalkulation](kalkulation).

---

## 🤖 KI-Anbindung

Die KI-Funktionen laufen standardmäßig über die zentrale Plattform-Anbindung (`provider = core`). Für Tests ohne API-Schlüssel gibt es einen deterministischen Fake-Modus. Welches Modell je Aufgabe genutzt wird, ist Deployment-Konfiguration und nicht Teil der fachlichen Einstellungen.

---

> **Reihenfolge beim Einrichten:** Erst die Taxonomien aufsetzen, dann die Kalkulations-Sätze — danach fügt sich alles Weitere (Rezepte, Gerichte, Concepts) sauber in dieses Gerüst ein.
