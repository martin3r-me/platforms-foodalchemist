---
title: Kalkulation
order: 6
---

# Kalkulation

Die Kalkulation besitzt zwei getrennte Rechenkreisläufe. Ein Katalogpreis ist mengenunabhängig;
ein belastbarer Produktions-HK2 entsteht erst mit einer konkreten Personenzahl.

## Katalogpreis ohne Pax

```text
MEK je Darreichung
→ dynamischer Unternehmens-Basissatz
→ relativer Klassenfaktor
→ Auto-Vorschlag
→ gültiger Katalog-VK
```

Der Basissatz normalisiert die bestätigten Monatswerte `MEK`, `FEK` und `HK` auf einen Euro
Wareneinsatz. Aktive Gemeinkostenblöcke auf MEK, FEK und HK sowie der Gewinnaufschlag auf HK2
fließen in den Faktor ein. Fehlen belastbare Monatsbasen, verwendet das System sichtbar den
Fallback `100 / Ziel-Wareneinsatzquote`.

```text
Auto-VK netto = Darreichungs-MEK × Basissatz × Klassenfaktor / 100
```

Preisklassen sind relative Produktabweichungen. `100 %` übernimmt den Unternehmenssatz
unverändert; sie sind kein harter Gesamtaufschlag mehr. Der Katalogpreis verwendet weder
Produktionszeit noch eine angenommene Auftragsgröße.

## Preiszustände

- `auto`: Vergleichspreis und gültiger VK werden neu berechnet.
- `fixed`: Der Vergleichspreis läuft weiter, der freigegebene VK bleibt stehen.
- `manuell`: nur als lesbarer Legacy-Zustand; neue Fixierungen verwenden `fixed`.

Eine neue Fixierung benötigt Preis und Begründung. Benutzer, Zeitpunkt und optionales Ablaufdatum
werden protokolliert. Abgelaufene Fixierungen fallen auf `auto` zurück. Preisänderungen werden in
`foodalchemist_price_change_audits` mit altem und neuem Vergleichs- und Effektivpreis festgehalten.

MwSt wird als Schlüssel `regulaer` oder `ermaessigt` gespeichert. Der aktuelle Prozentsatz kommt
aus den zentralen Einstellungen; ein freies Prozentfeld an der Darreichung existiert nicht mehr.

## Auftrag mit Pax

```text
Concept-Menge pro Person × Pax
→ rekursiver, konsolidierter Rezeptbedarf
→ Produktionsvorgänge, Rüst- und variable Zeit
→ MEK + FEK + direkte Kosten + Gemeinkosten
→ auftragsspezifischer HK2
→ Mindestpreis und Zielpreis
```

Ein Angebot zeigt Katalogpreis, tatsächlichen Auftrags-HK2, Deckungsbeitrag, Mindestpreis,
Zielpreis und aktive Personenzeit. Liegt der Katalog- oder Angebotspreis unter dem Zielpreis,
erscheint eine Warnung. Der Preis wird nicht still erhöht.

## Fixkosten und Bezugsbasen

Abgeleitete Sätze berechnen sich je Block aus `Fixkosten pro Monat / Bezugsbasis × 100`.
Positive Fixkosten mit Basis `0` ergeben weiterhin `0 %` und eine Warnung. Die Einstellungen bieten
einen ausdrücklich gekennzeichneten, editierbaren Catering-Beispielsatz mit typischen Kostenarten
und Monatsbasen. Diese Werte sind Rechenbeispiele, keine Branchen-Norm.

## Rechenverantwortung

- `CatalogPricingService`: mengenunabhängiger Darreichungs- und Katalogpreis.
- `ProductionTimeService`: Vorgänge, aktive Personenzeit und passive Standzeit.
- `OrderCostingService`: Bedarfsexplosion und auftragsspezifischer HK2.
- `PricingCascadeService`: Darreichung → Paket → Concept → offenes Angebot.

Die Datenumstellung läuft trocken oder schreibend:

```bash
php artisan foodalchemist:pricing-v2
php artisan foodalchemist:pricing-v2 --apply --chunk=200
```

Der Lauf ist fortsetzbar und wert-idempotent. Aktive Preise werden auf `auto` neu gesetzt;
versendete, angenommene und abgelehnte Angebote bleiben historische Snapshots.
