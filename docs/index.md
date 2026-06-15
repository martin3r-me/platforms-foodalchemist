---
title: Food Alchemist — Überblick
order: 1
---

# 🍳 Food Alchemist

Food Alchemist bildet die **Wertschöpfung in Gastronomie und Catering** ab — vom eingekauften Grundprodukt bis zum fertigen Kunden-Angebot. Statt Rezepte in Excel und Preise im Kopf zu jonglieren, entsteht hier eine durchgängige Kette: jede Zutat, jeder Preis und jede Kalkulation hängt nachvollziehbar zusammen.

---

## Die Wertschöpfungskette

Alles im Modul baut aufeinander auf. Wenn sich unten etwas ändert (z. B. ein Einkaufspreis), zieht sich das automatisch nach oben durch:

```
Grundprodukt  →  Basisrezept  →  Gericht  →  Concept  →  Foodbook (Angebot)
 (Einkauf)        (Produktion)   (Verkauf)   (Baukasten)   (Kunde)
```

| Ebene | Was hier passiert |
|-------|-------------------|
| **Grundprodukt** | Die abstrakte Zutat (z. B. „Zanderfilet") — mit Allergenen, Nährwerten und den konkreten Lieferantenartikeln dahinter. |
| **Basisrezept** | Das Produktionsrezept (Sauce, Beilage, Komponente) — kann auf andere Basisrezepte aufbauen. |
| **Gericht** | Die verkaufsfähige Speise mit Preis und Marge — gebaut aus Basisrezepten und Grundprodukten. |
| **Concept** | Der Baukasten: Gerichte und Pakete zu einem stimmigen Angebots-Gerüst zusammenstellen. |
| **Foodbook** | Das fertige Portfolio: Concepts werden zu Kunden-Angeboten (Kapitel, Personenzahl, Preise). |

Quer dazu liegen zwei Werkzeuge, die jede Ebene nutzen: die **Kalkulation** (was kostet es, was muss es bringen?) und der **Speiseplan** (was kommt wann auf den Tisch?).

---

## Drei Prinzipien, die überall gelten

### 🧮 Kosten rechnen sich von unten hoch
Du pflegst Preise nur einmal — beim Lieferantenartikel. Von dort rollt der Wareneinsatz automatisch ins Basisrezept, ins Gericht und ins Concept. Ändert ein Lieferant seinen Preis, ist die Kalkulation überall aktuell.

### 🤖 KI schlägt vor, du entscheidest
An vielen Stellen hilft die KI (Allergene ableiten, Rezepte entwerfen, Pairings vorschlagen). Diese Vorschläge werden **nie blind übernommen** — sie landen zur Kontrolle unter **„Zu prüfen"**, und jeder Wert merkt sich, ob er von Hand oder von der KI kommt und wie sicher er ist.

### 👥 Deine Daten gehören deinem Team
Was dein Team anlegt, sieht dein Team. Übergreifende Stammdaten (z. B. ein zentraler Grundprodukt-Katalog) können team-übergreifend bereitgestellt werden.

---

## Deine Bereiche

| Bereich | Worum es geht |
|---------|---------------|
| [📦 Stammdaten](stammdaten) | Grundprodukte, Lieferanten & Preise — das Fundament |
| [📖 Rezepte](rezepte) | Basisrezepte (Produktion) und Gerichte (Verkauf) |
| [🧱 Concepter](concepter) | Gerichte & Pakete zu Concepts zusammenstellen |
| [📔 Foodbook](foodbook) | Concepts zu Kunden-Angeboten bündeln |
| [🧮 Kalkulation](kalkulation) | Vom Wareneinsatz zur Vollkosten- und Verkaufspreis-Rechnung |
| [🗓️ Speiseplan](speiseplan) | Bausteine über Tage, Mahlzeiten und Wochen verteilen |
| [⚙️ Einstellungen](einstellungen) | Taxonomien, Kalkulations-Sätze und KI-Anbindung |

---

> **Wo wird das Modul weiterentwickelt?** Die laufende Planung — Features, Bugs, Entscheidungen und die technische Doku — liegt im **Dev-Modul** auf `office.bhgdigital.de` (Package `platforms-food-alchemist`). Dieses Handbuch beschreibt, wie das Modul **benutzt** wird; das *Wie-es-gebaut-ist* steht dort.
