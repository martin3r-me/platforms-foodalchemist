# 92 · PWA: Offline-Lesen und Push (Skizze, nach #1003)

Stand 29.10.2026. Eigenes Paket NACH #1003. Nicht Teil der Handy-Ansicht.

## Stufe 1 (in #1003, PR1b): installierbar

Manifest, Icons (inkl. maskable und apple-touch-icon), `display: standalone`, `theme_color`,
`start_url` = Handy-Startseite, `scope` = `/foodalchemist`, iOS-Meta-Tags
(`apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style`), einmaliger Hinweis
„Zum Home-Bildschirm hinzufügen“ für iOS (localStorage-Merker, wegklickbar). **Kein Service Worker.**

## Stufe 2 (dieses Paket): Service Worker für Offline-Lesen

- Nur Lesen: zuletzt angesehene Rezepte und Gerichte (Handy-Detailansicht) und das Handbuch.
- Cache mit Version (Name enthält den Modul-Stand). Neuer Stand → alter Cache wird gelöscht.
- Nie zwischenspeichern: Livewire-Anfragen (`/livewire/update`), alles mit Schreiben, Editoren,
  Einstellungen, Anmeldung. Netz zuerst für HTML, Cache nur als Rückfall mit sichtbarem Hinweis
  „Offline, Stand von …“.
- Team-Wechsel und Abmelden leeren den Cache (keine Inhalte eines anderen Mandanten auf dem Gerät).
- Größe begrenzen (z. B. letzte 30 Einträge), Bilder klein.
- Host: Service Worker muss vom Ursprung ausgeliefert werden (Pfad und Scope mit dem Host food-alchemist.de klären).

## Stufe 3 (dieses Paket): Web-Push für Signale

- Push nur für Signale (Zähler und neue kritische Signale), abbestellbar je Gerät.
- iOS erst ab 16.4 und nur für die installierte App; Android/Chrome auch im Browser.
- Schlüssel (VAPID) und Versand serverseitig; Abo je Benutzer und Gerät, an Team gebunden.
- Klick auf die Mitteilung öffnet das Signal in der Handy-Ansicht.

## Offene Fragen

- Welche Daten dürfen offline auf dem Gerät liegen (EK-Preise, Kundendaten)? Entscheidung Dominique.
- Push-Versanddienst: eigener Weg oder Core-Notifications?
