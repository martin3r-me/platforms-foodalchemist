---
title: Foodbook / Portfolio
order: 5
---

# 📔 Foodbook / Portfolio

Das Foodbook ist die **Kunden-Seite** des Moduls. Hier werden fertige [Concepts](concepter) zu einem konkreten **Angebot** zusammengestellt — das, was am Ende beim Kunden landet.

Ein Foodbook bündelt:

- **Kapitel** — die Gliederung des Angebots (z. B. nach Anlass, Menülinie oder Saison).
- **Personenzahl (Pax)** — für wie viele Gäste kalkuliert wird.
- **Angebots-Preise** — die Preise, die der Kunde sieht.

Ein Foodbook arbeitet primär mit **Concepts** (gebündelte Pakete, €/Gast). Die Komposition einzelner Teller passiert eine Ebene tiefer im Concepter — das Foodbook stellt die fertigen Bausteine zum Portfolio zusammen.

Seit Spec 19 (2026-07-23) kann ein Kapitel **zusätzlich einzelne Gerichte direkt** tragen: neben 0–n Concepts (Paket, €/Gast) auch 0–n `recipe_ref`-Blöcke, die ein VK-Gericht direkt referenzieren (€/Position). Damit ist die frühere Regel „Foodbook komponiert nur Concepts" (Weg B exklusiv) teilrevidiert. Ein `recipe_ref`-Block akzeptiert nur echte VK-Gerichte (keine konzept-lokalen Slot-Varianten).

## Planung läuft in der Leitstelle (Spec 42, 2026-08-22)

Das Foodbook ist eine **reine Ausgabe-Form** — man **kuratiert und gibt aus**, man plant hier nicht mehr. Die frühere Planungs-Maschinerie (Brief/Kickoff, Gerüst, Kreativ-Skizzen, 3-Modus, Trend) ist in die **Planungs-Leitstelle** gezogen (Round-Trip-Vertrag, revidiert aus [Spec 40](PLANUNG/40_Leitstelle_Planungs_Spine.md); Details → [Spec 42](PLANUNG/42_Foodbook_reine_Ausgabe.md)).

**Der Editor hat vier Tabs:** `Kontext` (Stammdaten · Kunde · schlanke Defaults Schreibstil/Kundentyp/Niveau · Einleitung) · `Speisen` (Kapitel + Bestands-Gerichte/Concepts arrangieren) · `Branding/CI` · `Preise`.

**Inhalte entstehen so:**

- **KI-Weg:** der Knopf **„In der Leitstelle planen"** öffnet die Leitstelle im Owner-Kontext dieses Foodbooks. Dort: Brief → Gerüst → Kaskade (Concept/Gericht/Basisrezept, gestufte Freigabe). Das Ergebnis **dockt automatisch als Kapitel/Blöcke ins Foodbook zurück** (`concept_ref`), Owner-Banner + Zurück-Link führen wieder hierher. Ein neues Foodbook lässt sich auch direkt in der Leitstelle über **„Foodbook aus Brief"** anlegen.
- **Alltags-Weg:** im `Speisen`-Tab bestehende Concepts/Gerichte per Picker einfügen, Kapitel/Blöcke umsortieren, für den Kunden benennen, A/B-Wahlgruppen bilden.

Die schlanken Kontext-Defaults (Schreibstil steuert den Kundentext; Kundentyp/Niveau sind Leitplanken) **reiten beim Start in die Leitstelle mit**. Kreativität/Skizzen/Lücken-Signale (`SignalTyp::SortimentsLuecke`) leben jetzt in der Leitstelle.

---

> **Die Logik dahinter:** Concepter = das Kochen im Kopf (was passt zusammen, was kostet es). Foodbook = das Verkaufen (welches Concept biete ich wem, für wie viele, zu welchem Preis).

---

## Präsentation — digitales Kundenbuch (Spec 43)

Im Tab **„Branding & Präsentation"** wird das Foodbook als teilbares, login-freies Kundenbuch
veröffentlicht: Design wählen (3 Vorlagen oder eigenes, im Struktur-Builder unter *Einstellungen →
Präsentations-Designs* gestaltete), **gültig bis** (Pflicht) setzen, **Veröffentlichen** → der
Kundenlink ist aktiv. Veröffentlichen friert einen **absoluten Snapshot** ein: Weiterarbeiten am
Foodbook ändert den Link erst beim erneuten Veröffentlichen. Zurückziehen/Ablauf → 404. Die
Kundensicht ist beweisbar interna-frei (kein EK/Marge/W%/Lieferant). Details → `PLANUNG/43_Praesentation_Digitales_Kundenbuch.md`.

## Handbuch — Standards zum Nachschlagen (Spec 86)

Zu jedem Ausgabe-Dokument (Foodbook, Speisekarte, Speiseplan, Angebot) gibt es ein **Handbuch** als zweiten,
eigenen Link neben dem Kundenlink: Tablet-first, login-frei, **ohne Preise**, eingefroren (Änderungen erst nach
erneutem Veröffentlichen). Je Gericht: Zubereitung, Anrichten (mit Schrittfotos), Regeneration je Komponente,
Bestandteile (Garnitur markiert), Portion/Geschirr, Allergene. Fehlt ein Abschnitt, bleibt er im Handbuch weg;
„Lücken prüfen" im Editor listet die betroffenen Gerichte. Link: `/p/handbuch/{token}` (eigener Token, eigenes
Ablaufdatum, Widerruf; Wieder-Veröffentlichen behält den Link). Der Wandmonitor ist davon unberührt.

**MCP** (je Dokumenttyp ein eigener Namensraum, damit die Rechte-Hülle über das Präfix den Bereich greift):

| Tool | Zweck |
|---|---|
| `foodalchemist.<typ>_handbuch.PUBLISH` | veröffentlichen; `<typ>_id` + `expires_at` (Pflicht); Antwort enthält `token`, `url`, `luecken` |
| `foodalchemist.<typ>_handbuch.GET` | Status (veröffentlicht, erreichbar, Link, Ablauf); `luecken=true` rechnet zusätzlich die Lücken |
| `foodalchemist.<typ>_handbuch.WITHDRAW` | zurückziehen (Link → 404, Token bleibt) |

`<typ>` = `foodbook` · `speisekarte` · `speiseplan` · `angebot`. Speiseplan: das Handbuch zeigt die laufende Woche,
sonst die erste belegte. Quelle: `HandbuchService`, `HandbuchQuellenService`; Spec → `PLANUNG/86_Handbuch_Standards.md`.

