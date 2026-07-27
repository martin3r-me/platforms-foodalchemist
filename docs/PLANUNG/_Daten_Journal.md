# Daten-Journal — was die Läufe über den echten Bestand herausfinden

> **Zweck:** Die Routine misst in fast jedem Lauf gegen die echte Dev-MySQL (MySQL-Smoke, Gegenproben, Stichproben). Dabei fallen **Fakten über den Datenbestand** an — nicht über den Code. Die landen bisher als Nebensätze in Code-Einträgen des [_Verbesserungs_Backlog.md](_Verbesserungs_Backlog.md) und gehen verloren, sobald der Code-Befund geschlossen wird. Hier stehen sie eigenständig.
>
> **Der Unterschied in einem Satz:** Backlog = „der Code macht das falsch". Journal = „**die Daten sehen so aus**". Zweite Frage, andere Zielgruppe: das Backlog liest Dominique als Tech-Lead, das Journal als F&B-Manager.
>
> **Diese Liste ist ein Befund-Speicher, keine Aufgabenliste.** Nichts hier ist eine Entscheidung.

---

## ⛔ Regeln für die Routine (verbindlich)

1. **NIE selbst heilen.** Ein Eintrag ist ein Befund für Dominique — **kein Auftrag, Daten zu korrigieren**. Massen-Datenheilung ist Spec 05 Etappe 2 und läuft bewusst nicht in der Routine.
2. **Max. 1–2 Einträge pro Lauf.** Nur was beim Arbeiten ohnehin gemessen wurde — **keine Extra-Abfragen auf Verdacht** und schon gar keine Voll-Scans über 264k-Tabellen.
3. **Zahl oder nichts.** Ein Eintrag ohne konkrete Zahl (n von m, Verteilung, Beispiel-ID) ist kein Eintrag. „Die Daten sind lückenhaft" gehört nicht hierher.
4. **Read-only.** Gemessen wird lesend. Kein `UPDATE`, kein `--apply`, kein Bulk-Command zur Messung.
5. **Fachlich, nicht technisch.** Gehört der Befund in erster Linie dem Code (falsche Abfrage, fehlende Abstraktion), ist er ein V-Eintrag. Hierher gehört, was **auch bei perfektem Code** noch ein Problem wäre.
6. **Kein Rätselraten über Ursachen.** Was gemessen wurde, wird berichtet. Warum es so ist, entscheidet Dominique — die Routine kennt die Betriebsgeschichte nicht.

## Kategorien
`bestandslücke` (fehlt) · `plausibilität` (sieht falsch aus) · `altlast` (aus einer Migration/Import-Ära) · `verteilung` (so ist der Bestand strukturiert) · `regelwerk-frage` (das Regelwerk beantwortet diesen Fall nicht)

## Eintrags-Format
```
### D-<nr> · <kurztitel>
- **Kategorie:** <aus Liste>   **Gemessen:** <YYYY-MM-DD>, Lauf zu <Etappe>   **Quelle:** Dev-MySQL / Fixture
- **Zahl:** die Messung, so knapp wie möglich.
- **Was das heißt:** fachliche Bedeutung — was folgt daraus für Rezepte/Preise/Angebote.
- **Offene Entscheidung:** was Dominique dazu entscheiden müsste (oder: keine).
```

---

## Einträge

> Die folgenden vier sind **rückwirkend** aus Nebensätzen bestehender V-Einträge herausgezogen (2026-07-27, Session-Triage) — damit das Format nicht theoretisch bleibt und die Zahlen nicht mit ihren Code-Einträgen verschwinden.

### D-001 · 91 % der lebenden Konzept-Slots hängen an gelöschten Konzepten
- **Kategorie:** altlast   **Gemessen:** 2026-07-26, Lauf zu 21·S4a   **Quelle:** Dev-MySQL
- **Zahl:** 4 Konzepte existieren, 3 davon soft-deleted. Von 23 lebenden Slot-Zeilen hängen **21 an einem gelöschten Konzept**. Dazu: **alle 3** lebenden Planungs-Gerüste gehören Konzepten, die am 2026-07-21 gelöscht wurden — kein einziges lebendes Konzept hat ein Gerüst.
- **Was das heißt:** Jede Auswertung, die an den Slots ansetzt statt am Konzept, zählt Papierkorb mit — bei dieser Verteilung um **Faktor 10** falsch. Betrifft künftige Belegungs-, Preis- und Coverage-Statistiken.
- **Offene Entscheidung:** Sollen die Waisen mit-soft-gelöscht werden (Aufräumen im Sinne „verbessern, nicht löschen"), oder ist der Bestand auf der Dev-DB ohnehin Wegwerf-Material und die Zahl damit nur ein Warnsignal für Produktivdaten?

### D-002 · Der „Kern"-Anker ist im Bestand ein Beutel, kein Hauptdarsteller
- **Kategorie:** plausibilität   **Gemessen:** 2026-07-26, Lauf zu 21·S4b-2   **Quelle:** Dev-MySQL
- **Zahl:** 352 `role='kern'`-Zeilen auf 89 Rezepte — Ø **4** je Rezept, Spitze **24** (Rezept 2619). Alle mit `source='ki'`, `ai_confidence` durchgehend **NULL**. `role` kennt im ganzen Bestand keinen zweiten Wert. Beispiel Rezept 1568 („Crumble: Grana Padano"): kern = butter, kaese, ei, olivenoel_extra_vergine, salz, weisser_pfeffer.
- **Was das heißt:** „Worum geht es in diesem Gericht" hat keine Datenquelle. Welcher Anker ein Sub-Rezept in den Teller-Score einbringt, entscheidet die Einfüge-Reihenfolge. Ein erster Dramaturgie-Entwurf hätte „beide Gänge fangen mit Butter an" als gleiche Hauptzutat gemeldet.
- **Offene Entscheidung:** Soll die Identität explizit gepflegt werden (eine Rolle `identitaet` oder `is_primary`, KI-Vorschlag + menschlich überschreibbar)? Ohne sie bleibt „Protein des Gangs" in Foodbook und Concepter unanzeigbar. Verwandter Code-Befund: V-019.

### D-003 · Bei den GPs ohne Lead-LA fehlt meist der Artikel selbst, nicht die Auswahl
- **Kategorie:** bestandslücke   **Gemessen:** 2026-07-26, Lauf zu 21·S3b-3   **Quelle:** Dev-MySQL, Stichprobe
- **Zahl:** Von 5 gesampelten `gp_ohne_lead`-GPs haben **4 überhaupt keinen Lieferantenartikel**; genau **1** ist der echte Fall „Artikel vorhanden, Auswahl offen".
- **Was das heißt:** Zwei völlig verschiedene Lagen tragen heute dieselbe Metrik und denselben Fixer-Knopf. Bei 4 von 5 verspricht der Knopf eine Reparatur, die er nicht leisten kann (es gibt nichts zu wählen) — das ist eine **Beschaffungs**-Lücke, kein Datenpflege-Thema. Und dort wo er wirkt, ändert er einen EK, der vorher unauffällig war.
- **Offene Entscheidung:** Ist die Stichprobe repräsentativ? Falls ja, ist der größere Teil dieser Metrik ein Einkaufs-Thema (Artikel beschaffen/anlegen lassen) und kein Systemthema. Verwandt: V-014.

### D-004 · Kein einziges Rezept ist nach Zeitstempel „alt" — der Bestand wirkt durchgehend frisch angefasst
- **Kategorie:** verteilung   **Gemessen:** 2026-07-26, Lauf zu 21·S1a   **Quelle:** Dev-MySQL
- **Zahl:** `updated_at` liegt bei **allen** Rezepten zwischen 2026-06-18 und 2026-07-07 — **0** älter als 180 Tage. `last_modified_by`: `aggregator` (1317), `promoter_260` (849), `taxo_313`/`merge_307` (je 63) — also fast überall ein Maschinen-Lauf. Unreferenziert wären **2095** Rezepte.
- **Was das heißt:** Man kann am Bestand heute nicht ablesen, was fachlich seit langem niemand angefasst hat — jeder Bulk-Recompute setzt die Uhr für alle zurück. Die 2095 unreferenzierten Rezepte sind der eigentliche Pflege-Befund, und er ist derzeit unsichtbar, weil die Alters-Klausel ihn wegfiltert.
- **Offene Entscheidung:** Sind 2095 unreferenzierte Rezepte erwartbar (Import-Bestand, der auf Verwendung wartet) oder Aufräum-Masse? Die Antwort bestimmt, ob ein „verwaist"-Signal überhaupt gewollt ist. Verwandt: V-006.
