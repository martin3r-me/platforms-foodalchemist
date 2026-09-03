---
title: Einkauf
order: 8
---

# 🛒 Einkauf

Der Einkauf ist das **Handeln**: bestellen, Konditionen pflegen, den Wareneingang buchen. Das
Auswerten — Preisvergleich, Wareneinsatz, Simulation — liegt im [Controlling](controlling).
Zwei Tätigkeiten, zwei Orte.

---

## Bestellungen

Eine Bestellung entsteht nicht als Formular, sondern als offener Entwurf je **Lieferant und
Liefertag**, in den Bedarf hineinläuft, bis du ihn absendest. Der **Liefertag** trennt die
Bestellungen: derselbe Lieferant kann für Montag und für Donnerstag je eine eigene offene
Bestellung haben. Produktion gibt ausschließlich einen versionierten **Materialbedarf** frei.
Er erscheint im Eingang „Bedarfe“; erst dort wählt der Einkauf Strategie, Lieferanten und
Liefertage und speichert daraus eine Bestellrunde.

Bedarf kommt aus mehreren Richtungen:

| Woher | Wie |
|-------|-----|
| **Produktion** | Produktionsauftrag einfügen: Ziele werden aufgelöst, Liefertag = Produktionsdatum. |
| **Gericht / Basisrezept** | Rezeptbedarf einfügen: Portionen, Ansätze oder kg werden in Grundprodukte und Gebinde aufgelöst. |
| **Grundprodukt** | Direkter GP-Bedarf, z. B. 3 kg Äpfel, wird über Lead-Artikel beschafft. |
| **Lieferantenartikel** | Direktbestellung eines konkreten Lieferantenartikels. |
| **Preisvergleich** | Aus dem Controlling: „→ Bestellung" legt einen einzelnen Artikel dazu. |
| **von Hand** | Sonderbedarf direkt in der Bestellung erfassen. |

Gerechnet wird in **Gebinden**, nicht in Kilogramm — bestellt wird, was der Lieferant liefert.
Die Rundung passiert auf dem Gesamtbedarf je Grundprodukt, nicht je Quelle; sonst würde zweimal
aufgerundet, wenn derselbe Artikel aus Produktion und Handeingabe kommt.

Eine Ampel zeigt, ob die Mindestbestellmenge erreicht ist.

### Featureliste Bestellcockpit

- **Neutraler Start:** Eine neue Bestellung startet ohne Lieferant. Der Lieferant entsteht erst
  durch die aufgelösten Quellen.
- **Quellen-Arbeitsstand:** Lieferantenartikel, Grundprodukte, Gerichte/Basisrezepte und
  Produktionen werden zuerst gesammelt und bleiben editierbar, bis gespeichert wird.
- **Einkaufsstrategie:** Pro Cockpit-Start kann Team-Standard, günstigster Preis,
  Stamm-Lieferant zuerst oder Prioritäts-Kette gewählt werden; die Strategie wird auf erzeugte
  Drafts geschrieben.
- **Vorschau ohne Schreiben:** Die Vorschau bündelt nach **Lieferant + Liefertag**, zeigt
  Positionen, Netto, Mindestwert/Frei-Haus, Lieferlogistik und Klärpunkte.
- **Klärliste:** Fehlende Lead-Artikel, fehlende Preise/Gebinde oder nicht bestellbare Artikel
  blockieren nur die betroffene Position, nicht den ganzen Einkauf.
- **Einzelne Zutaten wechseln:** In der Vorschau und in gespeicherten Drafts kann eine Position
  auf einen anderen Lieferantenartikel wechseln. Bei anderem Lieferanten wandert die Position in
  die passende Lieferant+Liefertag-Schiene.
- **Idempotenz:** Dieselbe Quelle ersetzt ihren alten Beitrag. Beim manuellen Wechsel werden alte
  Source-Beiträge aus offenen Drafts entfernt, damit kein Doppelbedarf entsteht.
- **KPIs:** Übersicht und Modal zeigen Quellen, Schienen, Positionen, Netto, Lieferanten,
  Klärpunkte und Strategie.

### Übersicht: drei Sichten

Der Bestell-Browser stellt die **einzelnen Bestellungen** in den Vordergrund, nicht den
Lieferanten. Gefiltert wird tabübergreifend nach Datumsachse, Zeitraum, Status, Lieferant,
Produktion, Freitext, „nur mit Positionen" und „nur mit Klärung".

| Sicht | Zweck |
|-------|-------|
| **Bestellungen** | Einzelne Drafts schnell finden und öffnen; zeigt Liefertag, Lieferant, Produktion/Anlass, Positionen, Netto, Status, Strategie und Hinweise. |
| **Liefertage** | Einkauf nach Datum planen; gruppiert zuerst nach Liefertag, darunter Lieferanten-Drafts. |
| **Lieferanten** | Versand-/Bestellsicht; gruppiert zuerst nach Lieferant, darunter offene Liefertage. |
| **Runden** | Persistente Klammer um die beteiligten Lieferantenbelege, mit Summen, Klärpunkten und Sammelversand. |
| **Bedarfe** | Freigegebene Produktionsbedarfe planen; nachträglich geänderte Freigaben bleiben bis zur erneuten Freigabe gesperrt. |

Hinweise wie **leer**, **Klärung**, **Mindestwert**, **Bestellschluss verpasst**,
**Liefertag vorbei** und **Liefertag nicht beliefert** bleiben in allen Sichten sichtbar. Die Suche findet neben
Lieferant, Anlass und Produktion auch interne Bestellnummern (`ord-…`),
Lieferanten-AB-/Bestellnummern und Rechnungsnummern. Bearbeitet wird jede Bestellung im
Vollbild-Editor.

### WaWi-Logik in v1

Das Modul verhält sich zunehmend wie ein schlankes Warenwirtschafts-Cockpit, ohne schon ein
vollständiges Lager zu führen:

- **Lieferanten-Konditionen:** Mindestbestellwert, Frei-Haus-Grenze, Liefertage,
  Bestellschluss und Vorlaufzeit werden pro Lieferant ausgewertet.
- **Operative Warnungen:** Entwürfe markieren leere Bestellungen, Klärbedarf,
  unterschrittene Mindestwerte, verpasste Bestellschlüsse, nicht passende Liefertage und
  abweichend bestätigte Liefertage. Ist beim Lieferanten **kein Bestellschluss gepflegt**
  (weder Vorlaufzeit noch Uhrzeit), warnt der Beleg am **Liefertag** selbst — **Liefertag
  vorbei** bzw. **Liefertag heute** — statt einen Bestellschluss zu behaupten, den es nicht
  gibt. Die Sperrwirkung ist in beiden Fällen dieselbe.
- **Vorschau-Warnungen:** Liefertag und Bestellschluss werden bereits in der Cockpit-Vorschau
  je Lieferant+Liefertag geprüft, bevor Drafts gespeichert werden.
- **Versandsperren:** Leere, ungeklärte, nicht am Liefertag belieferbare, nach
  Bestellschluss liegende oder auf einen **vergangenen Liefertag** datierte Bestellungen können
  nicht versehentlich als gesendet markiert werden. Welche Hinweise sperren und welche nur
  informieren, steht an genau einer Stelle im Code (`OrderService::HARTE_SPERREN` /
  `WEICHE_HINWEISE`); ein Test hält jedes erzeugte Etikett gegen dieses Register, damit ein
  neues nicht stillschweigend als „nur Hinweis" durchrutscht.
- **Freigaben:** Anfrage, Zusage und Ablehnung werden am Beleg geführt (Status, Zeitpunkt,
  Freigeber, Notiz). Eine **abgelehnte** Freigabe sperrt den Versand, eine **offene** nicht —
  die Küche kommt auch bei abwesendem Freigeber an die Ware. Offen im Backlog: eine
  **Betrags-Schwelle je Team/Betrieb**, oberhalb der die Freigabe automatisch entsteht und
  dann auch sperrt. Solange sie von Hand angefragt wird, wäre eine harte Sperre beliebig —
  wer keine Freigabe anfragt, hat keine offene.
- **Lieferantenbestätigung:** Nach dem Absenden können AB-/Bestellnummer, bestätigter
  Liefertag und Bestätigungsnotiz am Beleg gepflegt werden. Speichern einer Bestätigung setzt
  gesendete Belege automatisch auf `bestätigt`.
- **Freigabe light:** Bestellungen können als Freigabe angefragt, freigegeben oder abgelehnt
  markiert werden. Offene Freigaben sind ein Hinweis; abgelehnte Freigaben blockieren den
  Versand. Notiz, Zeitpunkte und freigebende Person bleiben am Beleg sichtbar.
- **Detailpanel:** Der Vollbild-Editor zeigt Hinweise als KPI und im Reiter Kopf/Status mit
  Erklärung, ob ein Hinweis nur informativ ist oder den Versand blockiert.
- **Wareneingang light:** Gesendete oder bestätigte Bestellungen bekommen einen eigenen
  Wareneingangs-Reiter. Pro Zeile kann die tatsächlich gelieferte Gebinde-Menge und eine
  Differenznotiz erfasst werden; Abweichungen erscheinen als **WE-Differenz** in den
  Bestellhinweisen.
- **Wareneingang übernehmen:** Wenn alles wie bestellt geliefert wurde, übernimmt eine
  Massenaktion alle Zeilen mit der bestellten Menge.
- **Geliefert-Status:** Beim Setzen auf `geliefert` werden noch offene Wareneingangs-Zeilen mit
  der bestellten Menge vorbelegt. Bereits erfasste Unter-/Überlieferungen bleiben erhalten.
- **Rechnungsprüfung light:** Nach dem Absenden kann je Zeile die berechnete Menge und der
  berechnete Gebindepreis erfasst werden. Das System vergleicht Rechnung gegen Wareneingang
  beziehungsweise Bestellung und markiert Preis-/Mengenabweichungen als **RE-Differenz**.
- **Rechnungskopf:** Rechnungsnummer, Rechnungsdatum und Rechnungsnotiz werden am Beleg
  gepflegt. Aus Rechnungsdatum und Zahlungsziel des Lieferanten berechnet das Modul die
  Rechnungsfälligkeit; die eigentliche Prüfung bleibt zeilenweise.
- **Offene Posten light:** Rechnungen können als offen, strittig oder bezahlt markiert
  werden. Überfällige offene Rechnungen erscheinen als Hinweis, ohne den Bestellversand zu
  blockieren.
- **Reklamation/Gutschrift light:** WE- oder Rechnungsabweichungen können pro Position als
  Reklamation markiert werden, inklusive reklamierter Menge, erwarteter Gutschrift und Notiz.
  Offene Reklamationen erscheinen als Hinweis am Beleg.
- **Kontingent/Rahmenabruf light:** Lieferantenartikel können eine Kontingentmenge,
  bereits abgerufene Menge, Gültigkeit und Notiz tragen. Bestellzeilen zeigen die freie Menge
  vor und nach der Bestellung; überschrittene oder nicht gültige Kontingente erscheinen als
  Hinweis am Beleg.
- **Kontingentverbrauch:** Wareneingangsbuchungen verbrauchen Kontingent idempotent je
  Bestellzeile. Korrigiert man den Wareneingang, wird nur die Differenz nachgebucht oder
  zurückgenommen.
- **Lager light:** Wareneingangsbuchungen erzeugen idempotente Lagerzugänge je Grundprodukt
  beziehungsweise Lieferantenartikel. Korrekturen buchen nur die Differenz; die Bestellposition
  zeigt den aktuellen verfügbaren Bestand.
- **Lagerorte:** In den Einkaufs-Einstellungen können Lagerorte angelegt, deaktiviert und als
  Standardlager markiert werden. Wareneingänge buchen auf das Standardlager; Anzeigen summieren
  aktive Lagerorte.
- **Nachlieferung light:** Unterlieferte Wareneingangszeilen können als neuer
  Nachlieferungs-Entwurf beim gleichen Lieferanten angelegt werden. Die Fehlmenge wird als
  manuelle Bestellposition übernommen.
- **Rechnung übernehmen:** Wenn die Rechnung zur Lieferung passt, übernimmt eine Massenaktion
  Mengen aus dem Wareneingang und Preise aus der Bestellung.
- **Dokument & CSV:** Bestelldokument und CSV-Export enthalten neben den Bestellpositionen auch
  AB-/Bestellnummer, bestätigten Liefertag, Freigabe, Kontingent, Rechnungsnummer,
  Zahlungsstatus, Wareneingangs- und Rechnungsdifferenzen.
- **Drei-Abgleich:** Bestellt, geliefert und berechnet stehen im Editor nebeneinander. Damit
  werden typische WaWi-Fragen sichtbar, ohne schon eine vollständige Kreditorenbuchhaltung zu
  erzwingen.
- **Aufräumen:** Leere Entwurfs-Bestellungen ohne Positionen können aus der Übersicht heraus
  gelöscht werden. Gesendete Bestellungen und Entwürfe mit Positionen bleiben geschützt.
- **Tool-/API-fähig:** `orders.GET` liefert Listen- und Detaildaten mit Belegnummern,
  Positionen, Warnungen, Sende-Blockern, Freigabe, Wareneingang und Rechnungsprüfung.
  `orders.UPDATE` pflegt neben Draft-Kopfdaten auch Lieferantenbestätigung, Freigabe,
  Rechnungskopf, Zahlungsstatus sowie die Massenaktionen für Wareneingang und Rechnung.
  `orders.UPDATE_LINE` pflegt neben Entwurfs-Mengen auch Kontingent, gelieferte und
  berechnete Mengen/Preise je Position.

V2 bleibt bewusst getrennt: kompletter Reklamationsworkflow, Beleg-/PDF-Erkennung,
Inventur, Lagerorte, Reservierungen, Ersatzlieferungsbuchung, OP-Liste/Kreditorenbuchhaltung und mehrstufiger Genehmigungsworkflow brauchen eigene Buchungsdaten über diese
Bestellzeilen-Erfassung hinaus.

### Featureliste aktueller Stand

**Cockpit & Bedarf**

- Neue Bestellung startet neutral ohne Lieferantenvorauswahl.
- Quellen können als Lieferantenartikel, Grundprodukt, Gericht, Basisrezept oder Produktion
  in einen Arbeitsstand eingefügt werden.
- Produktionen werden in Einkaufsbedarf expandiert und bleiben über die Herkunft
  nachvollziehbar.
- Vorschau schreibt keine Daten und zeigt Lieferant+Liefertag-Schienen, Summen, Herkunft und
  Klärpunkte.
- Bestellungen werden als Drafts je Lieferant+Liefertag gespeichert und idempotent
  aktualisiert.
- Einzelne Zutaten/Positionen können auf alternative Lieferantenartikel gewechselt werden.

**Disposition & Versand**

- Übersicht hat Tabs für konkrete Bestellungen, Liefertage und Lieferanten.
- Filter wirken tabübergreifend auf Zeitraum, Status, Lieferant, Produktion, Suche,
  nur mit Positionen und nur mit Klärung.
- KPI-Kacheln zeigen Belege, versandbereite Schienen, Positionen, Netto, Lieferanten und
  Klärpunkte.
- Warnhinweise markieren leer, Klärung, Mindestwert, Liefertag, Bestellschluss,
  Wareneingang und Rechnung.
- Versand wird blockiert, wenn eine Bestellung fachlich nicht bestellbar ist.
- Leere Drafts können bereinigt werden, ohne echte Bestellungen zu löschen.

**WaWi light**

- Lieferantenbestätigung mit AB-/Bestellnummer, bestätigtem Liefertag und Notiz.
- Wareneingang pro Zeile mit gelieferter Menge, Differenz und Notiz.
- Rechnungskopf mit Rechnungsnummer, Rechnungsdatum und Notiz.
- Rechnungsfälligkeit aus Rechnungsdatum plus Lieferanten-Zahlungsziel.
- Zahlungsstatus offen/strittig/bezahlt mit Bezahlt-am und Zahlungsnotiz.
- Freigabe light mit angefragt/freigegeben/abgelehnt, Notiz, Zeitpunkten und Versandblocker
  bei Ablehnung.
- Kontingent/Rahmenabruf am Lieferantenartikel mit Menge, Verbrauch, Gültigkeit und Hinweis.
- Kontingentverbrauch aus Wareneingang, inklusive Korrektur ohne Doppelbuchung.
- Lagerzugang aus Wareneingang mit Bewegungsjournal und aktuellem Bestand je Artikel.
- Lagerorte in `Einstellungen → Einkauf` inklusive Standardlager.
- Nachlieferungs-Draft aus unterlieferten Wareneingangszeilen.
- Rechnungsprüfung pro Zeile mit berechneter Menge, Preis und Differenz.
- Massenaktionen übernehmen Wareneingang aus Bestellung und Rechnung aus Wareneingang.
- Reklamation/Gutschrift pro Zeile mit Status, Menge, erwartetem Betrag und Notiz.
- Druck/Dokument/CSV enthalten Belegkopf, Freigabe, Kontingent, Wareneingang,
  Rechnungsprüfung und Reklamation.
- MCP/Tool-Zugriff kann Bestellung lesen, Kopf/AB/Freigabe/Rechnung pflegen sowie
  Kontingent/WE/RE pro Zeile oder per Massenaktion aktualisieren.

**Bewusst noch nicht v1**

- Inventur, Lagerorte, Mindestbestände, Reservierungen und Verbrauchsbuchungen aus Produktion.
- Vollständige Kontrakt-/Rahmenvertragsbuchhaltung mit automatischem Abrufverbrauch.
- Mehrstufige Nachlieferungs-/Backorder-Verfolgung mit Lieferavis und Rest-offen-Status.
- Voller Reklamationsworkflow mit Ersatzlieferung, Gutschriftbeleg und Kreditorenbuchung.
- Beleg-/PDF-Erkennung und automatischer Rechnungsimport.
- Vollständige OP-Liste, Zahlungsverkehr und Kreditorenbuchhaltung.
- Mehrstufige Freigabe nach Budget, Warengruppe oder Standort.

---

## Status und Versand

`Entwurf → gesendet → bestätigt → geliefert` (oder `storniert`). Nur der Entwurf ist
bearbeitbar; mit dem Absenden frieren die Preise ein — was danach im Katalog passiert, ändert
die abgesendete Bestellung nicht mehr.

Raus geht die Bestellung als Druckansicht, PDF, CSV oder als vorbereitete E-Mail an die
hinterlegte Bestelladresse.

---

## Was hier zusammenläuft

- **Konditionen** je Lieferant (Liefertage, Bestellschluss, Vorlaufzeit, Mindestmenge,
  Frei-Haus-Grenze, Rückvergütung) pflegst du am Lieferanten in den
  [Stammdaten](stammdaten).
- **Welcher Lieferant** ein Grundprodukt liefert, entscheidet die Bezugsquelle (Lead-Artikel).
  Umgestellt wird sie im [Controlling → Preise](controlling) — dort steht der Vergleich daneben,
  auf dem die Entscheidung beruht.
- Eine abgesendete beziehungsweise gelieferte Bestellung spiegelt sich ins **Einkaufsjournal**.
  Das ist die Ist-Datenbasis, aus der das Controlling Spend, Einsparpotenziale und die gemessene
  Wareneinsatzquote rechnet.

---

> **Lager light.** Das Modul führt jetzt Wareneingangs-Bestände mit Bewegungsjournal. Es ist
> noch keine vollständige Lagerwirtschaft mit Inventur, Mindestbestand, Chargen, MHD,
> Lagerorten oder Produktionsverbrauch.
