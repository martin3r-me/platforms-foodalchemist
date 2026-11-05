# Wettbewerberbeobachtung

## Ablauf und Zuständigkeit

„Wettbewerber“ ist ein eigener Bereich in Trend Research. Profile enthalten Firmenname, Namensvarianten/Marken, Website, Suchkontext, Ausschlüsse, Branchen, Region/Gebiet und eine eigene Profilnotiz. Das Observatorium zeigt unter „Wettbewerb“ ausschließlich bestätigte Firmenzuordnungen, gruppierte Meldungen, Quellen und eigene Bewertungen. Auf der Handyoberfläche sind diese Inhalte lesbar; redaktionelle Änderungen erfolgen weiterhin am Computer.

Ein neuer Artikel bleibt im vorhandenen Trend-Eingang. Der kostenlose lokale Matcher sucht aktive Profile über ganze Namen/Marken, die eigene Unternehmensdomain oder ausdrücklich zugeordnete Quellen. Die Herausgeberdomain eines Presseartikels wird nicht mit der Unternehmensdomain gleichgesetzt. Jede automatische Zuordnung bleibt vorgeschlagen. Ein Kurator bestätigt den Firmenbezug, korrigiert die Art der Marktbewegung und kann eine eigene Einordnung ergänzen oder den Vorschlag ablehnen. Wiederholte Bestandssuchen überschreiben keine bestätigten oder abgelehnten Entscheidungen.

Ein Artikel kann mehreren Unternehmen zugeordnet sein; es entstehen keine Kopien im Eingang. Zuordnungen verändern keine Trendstatus, Radarfreigaben, Pinnwand-Beziehungen oder persönlichen Merker. Ein als Trendsignal ungeeigneter Artikel darf dennoch eine bestätigte Unternehmensmeldung sein. Die vorhandene Observatorium-Gruppierung gleicher längerer Titel/URLs bleibt bestehen, ist aber keine semantische Dublettenerkennung.

## Quellen und Kosten

Je Profil wird eine Google-News-RSS-Suche mit Firmenname/Marken und optionalem zusätzlichem Suchkontext gespeichert. Ein ausdrücklich eingegebener Presse-RSS kann hinzukommen; weitere bereits vorhandene eigene Quellen lassen sich zuordnen. Neue Suchen starten pausiert und wöchentlich. Das Speichern und die lokale Bestandssuche verursachen weder Abruf noch KI-Aufträge. Erst die bewusste Aktivierung führt zum vorhandenen Quellenzeitplan und gegebenenfalls zur bisherigen KI-Auswertung mit deren Budget. Es wird keine neue externe API benötigt.

Bei eigens am Profil angelegten Google-/Pressefeeds wird ohne ausdrücklichen Quell-Einfilter kein allgemeines Food-Stichwort verlangt, damit Betreiber-/Personal-/Standortmeldungen nicht schon vor dem Eingang verschwinden. Ausschlussfilter bleiben erhalten; ein ausdrücklich gesetzter Einfilter der Quelle hat Vorrang. Weitere zugeordnete Bestandsquellen behalten ihre Filter. Websites ohne tatsächlichen Feed werden nicht automatisch auf Preis- oder Speisekartenänderungen überwacht.

## Einordnung und Darstellung

Arten: Unternehmensmeldung, Angebote & Konzepte, Standorte & Expansion, Aufträge & Vergaben, Partnerschaften, Personal & Führung. Der Matcher schlägt eine Art nach Stichworten vor; bei Bestätigung kann sie korrigiert werden. Eigene Einordnungen werden als solche gekennzeichnet. Es entstehen weder automatische SWOT-Urteile noch unbelegte Chancen-/Risikoaussagen. Die Profilübersicht zählt bestätigte Fundstellen nach Art insgesamt; die Meldungsfilter gelten für die darunterliegende Recherche. Branche und Region beziehen sich in der Wettbewerbsansicht auf das Unternehmen, Datum und Suchtext auf die Meldung.

## Sichtbarkeit und Schnittstellen

Profile, Fundstellen und Eingänge beachten die bestehende Eltern-/Kind-Sichtbarkeit. Änderungen an Profilen nur im eigenen Team und ab Kuratieren. Zuordnungen nur zu eigenen Eingängen; für alle Leseansichten müssen sowohl Profil als auch Eingang im Sichtbereich liegen. Bestätigungen speichern Grund, Art, eigene Einordnung, prüfenden Benutzer und Zeitpunkt der letzten Prüfung. Die Tabellen sind eine zusätzliche Projektion; der Artikelbestand bleibt die gemeinsame Quelle.

MCP: `foodalchemist.wettbewerber.GET`, `.POST`, `.PUT`, `.ZUORDNEN`. UI und MCP verwenden denselben Service und dieselben Rechte. GET liefert Profile oder paginierte Fundstellen, PUT kann den eigenen Bestand kostenlos nach Firmenbezug prüfen. ZUORDNEN ist eine redaktionelle Entscheidung, keine Trendfreigabe.

## Rollout

Additive Migration mit Profil- und Zuordnungstabelle, kein Backfill und keine Aktivierung vorhandener Quellen. Vor Deployment Go einholen; anschließend ausschließlich über food-alchemist.de/update.sh im Foodalchemist-Standardumfang. Nach dem Deployment Profile anlegen, Bestand auf Firmenbezug prüfen und Vorschläge bestätigen. RSS-Aktivierung je Profil bewusst entscheiden.

## Validierung

75 unterschiedliche relevante Feature-/Regressionstests erfolgreich geprüft: Wettbewerberprofile, pausierte RSS-Quellen, Namen-/Domainzuordnung, Ablehnungen, bestätigte Veröffentlichung, mehrere Firmen je Artikel, fremde Profil-/Quell-/Artikel-IDs, Eltern-/Kind-Sicht, Leserrechte, Handy-Schreibsperre und direkte mobile Research-Navigation. Zusätzlich bestehende Observatorium-, Quellen-, Suchthemen-, Handy- und MCP-Registrierungsprüfungen. Isolierte SQLite-In-Memory-Datenbanken, keine Live-Datenänderungen und keine bezahlten KI-Aufrufe. Lokale Layoutprüfung mit ausdrücklich fiktiven Testunternehmen am Laptop und bei 390 Pixeln Breite; kein horizontaler Überlauf. Die mobile Unternehmensübersicht ist aufklappbar, damit Meldungen unmittelbar sichtbar bleiben.
