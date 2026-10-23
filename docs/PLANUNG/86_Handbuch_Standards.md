# Spec 86 – Handbuch (Standards zum Nachschlagen)

Status: **Entwurf**, Dominique entscheidet. Stand 2026-10-23. Abgestimmt mit dem Kurator (Spec 82/84/85).

## Ziel
Zu jedem Foodbook, jeder Speisekarte, jedem Angebot und jedem Speiseplan gibt es ein **digitales Handbuch** für den Kunden und dessen Team (Franchise-Prinzip: Standards anklicken statt Zettel). Man sieht, **wie ein Gericht gemacht, angerichtet und regeneriert wird**. Tablet-first, Handy muss gehen. Nur lesen, kein Login, keine Preise, keine Interna. Die Druckversion bleibt unverändert bestehen.

## Festlegungen (Dominique)
- Je Quelldokument ein **eigener Handbuch-Link**, erzeugt dort, wo heute der Kundenlink (Präsentation) veröffentlicht wird – als zweiter Link daneben, mit eigenem Token, Ablauf und Widerruf.
- Zugriff und Mechanik wie die Veröffentlichung des Foodbooks (Spec 43).
- **Eingefroren** (Snapshot); Änderungen erst nach erneutem Veröffentlichen.
- Navigation folgt dem Aufbau des Quelldokuments (Sektionen, Kapitel, Wochentage); man klickt ins Gericht.
- Optik und Bedienung wie der Wandmonitor (große Flächen, schnell erfassbar). **Der Wandmonitor selbst bleibt unverändert.**
- Zunächst nur Deutsch.

## Inhalt je Gericht
Zubereitung (Schritte, Schrittfotos) · Anrichten (Text, Fotos) · Regeneration je Komponente (Gerät, Temperatur, Zeit, Kerntemperatur, Behälter) · Bestandteile (Garnitur markiert) · Portion, Vehikel/Geschirr · Allergene und Kennzeichnung. Dazu freie Kapitel (z. B. Hygiene, Ausgabe-Regeln).
Fehlt ein Abschnitt, wird er im Handbuch weggelassen; beim Veröffentlichen zeigt der Editor die Gerichte mit Lücken. KI-Entwürfe werden nicht eingeblendet.
**Nie im Snapshot:** EK, VK, Marge, Lieferant, interne Notizen.

## Technik (Entwurf)
- Neuer Typ `handbuch` im bestehenden `PresentationService` (kein zweiter Link-Mechanismus), Route `/p/{type}/{token}`, Tabelle `foodalchemist_presentations`.
- Neuer Snapshot-Builder neben `buildSnapshot`; Darreichungsform der Position (Speiseplan: `presentation_id`) bestimmt Anrichten und Regeneration.
- Daten: `recipe_steps`, `recipe_step_photos`, `recipes.plating_text`, `recipe_regenerations`, `recipe_containers`, `recipe_presentations`, Zutatenrolle/-funktion für Garnitur, Allergen-Spalten am Rezept.
- Eigene mobile/Tablet-View; Stilvorbild `foodalchemist::layouts.kiosk`.
- Veröffentlichen-Bereich in Foodbook, Speisekarte, Angebot, Speiseplan; MCP-Tools im Lockstep.
- Migrationen im Zeitstempelbereich `2026_11_14_*`, nur falls nötig.

## Nicht in dieser Spec
Mehrsprachigkeit, PIN-Schutz, Live-Variante, Umbau des Wandmonitors.

## Abnahme
Pest: Negativtest „keine Preisfelder im Snapshot", Token/Ablauf/Widerruf, Lücken-Report, je Quelltyp ein Test, PolicyTest, Dateigrößen-Wächter. Browser: Tablet quer und 375 px, per Link ohne Login durchklicken.

## Bau-Regeln (Kurator)
Eigener Branch/Worktree, Team-Sicht beachten (`TeamSicht`, `FremdSchreiben::erlaubt`), lokal committen, Branch+Commit+Tests an den Kurator, der pusht und deployt. Nur ein Testlauf gleichzeitig, Slot vom Kurator.
