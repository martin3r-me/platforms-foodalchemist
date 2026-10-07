{{-- faMenu — gemeinsamer Aufklapp-Helfer für Menüs (Status-Chips, „Weitere Aktionen" …).
     Das Menü steht FEST am Bildschirm (nicht im scrollenden Tabellenkasten → wird nicht abgeschnitten),
     rechtsbündig am Auslöser, klappt nach oben, wenn unten kein Platz ist, und schließt beim Scrollen.
     Nutzung: x-data="faMenu()" · Auslöser x-on:click="toggle($event)" · Menü class="hidden" x-bind:class="{ hidden: ! offen }" x-bind:style="pos"
     (Menü ist von Haus aus versteckt → fehlt der Helfer einmal, bleibt es zu statt die Tabelle aufzublähen.)
     Eingebunden in BEIDEN Hüllen: eigenständig im <head> (layouts/standalone), Plattform-Modus über
     shell/page-navbar (demo/office: Core-Layout kennt faMenu nicht — Befund 2026-10-07). Läuft das Skript
     erst nach dem Alpine-Start (wire:navigate), wird direkt registriert. --}}
<script>
  (() => {
    const faMenu = () => ({
      offen: false,
      pos: '',
      toggle(e) {
        if (this.offen) { this.offen = false; return; }
        const r = e.currentTarget.getBoundingClientRect();
        const oben = r.bottom + 280 > window.innerHeight && r.top > 280;
        this.pos = 'position:fixed;z-index:1000;left:' + r.right + 'px;top:' + (oben ? r.top - 4 : r.bottom + 4) + 'px;transform:translate(-100%,' + (oben ? '-100%' : '0') + ')';
        this.offen = true;
      },
      init() {
        const zu = () => { this.offen = false; };
        window.addEventListener('scroll', zu, true);
        window.addEventListener('resize', zu);
      },
    });
    if (window.Alpine) window.Alpine.data('faMenu', faMenu);
    else document.addEventListener('alpine:init', () => window.Alpine.data('faMenu', faMenu));
  })();
</script>
