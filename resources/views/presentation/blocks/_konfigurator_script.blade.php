{{-- Spec 94 · Logik des Konfigurator-Links (ohne Framework): Auswahl → Paket zur Gästezahl → Preis aus der eingefrorenen
     Preistabelle. Rechnet nichts selbst außer Summe und × Gäste. Auswahl im URL-Hash zum Teilen. --}}
@php
    $konfigDaten = [
        'pakete' => $content['pakete'] ?? [],
        'preise' => $content['preise'] ?? [],
        'konzepte' => $content['konzepte'] ?? [],
        'addons' => $content['addons'] ?? [],
        'tageszeit_aktiv' => (bool) ($content['tageszeit_aktiv'] ?? false),
    ];
@endphp
<script type="application/json" id="pt-konfig-daten">@json($konfigDaten)</script>
<script>
(function () {
    var el = document.getElementById('pt-konfig-daten');
    if (!el) { return; }
    var d = JSON.parse(el.textContent);
    var q = function (s) { return document.querySelector(s); };
    var qa = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
    var euro = function (v) { return v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; };
    var z = { konzept: null, tageszeit: null, abend: null, addons: [], pax: null };
    var konzept = function (id) { return d.konzepte.filter(function (k) { return k.id === id; })[0] || null; };

    var paxFeld = q('[data-konfig-pax]');
    z.pax = paxFeld ? parseInt(paxFeld.value, 10) : null;

    function paketIndex() {
        for (var i = 0; i < d.pakete.length; i++) { if (z.pax >= d.pakete[i].von && z.pax <= d.pakete[i].bis) { return i; } }
        return null;
    }
    function zeitFuer(k) {
        if (z.tageszeit && (z.tageszeit === 'day_night' ? (k.lunch) : k[z.tageszeit])) { return z.tageszeit; }
        return k.dinner ? 'dinner' : 'lunch';
    }
    function schluessel(k, t) {
        if (t === 'day_night') { return 'dn' + k.id + ':' + (z.abend || k.id); }
        return 'k' + k.id + ':' + t;
    }
    function addonSichtbar(zeile, t) {
        var geb = zeile.getAttribute('data-gebunden'), tz = zeile.getAttribute('data-tageszeit');
        if (geb && (!z.konzept || String(z.konzept) !== geb)) { return false; }
        if (tz !== 'beide' && t !== 'day_night' && tz !== t) { return false; }
        return true;
    }
    function render() {
        var k = z.konzept ? konzept(z.konzept) : null;
        var t = k ? zeitFuer(k) : null;
        if (k && !d.tageszeit_aktiv) { z.tageszeit = t; }
        qa('[data-konfig-konzept]').forEach(function (b) { b.setAttribute('aria-pressed', String(parseInt(b.getAttribute('data-konfig-konzept'), 10) === z.konzept)); });
        qa('[data-konfig-tageszeit]').forEach(function (b) {
            var wert = b.getAttribute('data-konfig-tageszeit');
            var moeglich = !k || (wert === 'day_night' ? (k.lunch && d.konzepte.some(function (x) { return x.dinner; })) : k[wert]);
            b.disabled = !moeglich;
            b.setAttribute('aria-pressed', String(t === wert));
        });
        var abendFeld = q('[data-konfig-abend-feld]');
        if (abendFeld) { abendFeld.hidden = t !== 'day_night'; }
        var i = paketIndex();
        var tab = i !== null ? d.preise[i] || {} : {};
        qa('[data-konfig-addon-zeile]').forEach(function (zeile) {
            var id = parseInt(zeile.getAttribute('data-konfig-addon-zeile'), 10);
            var sicht = addonSichtbar(zeile, t);
            zeile.hidden = !sicht;
            var box = zeile.querySelector('[data-konfig-addon]');
            if (!sicht && box.checked) { box.checked = false; }
            var p = tab['a' + id];
            var preisEl = zeile.querySelector('[data-konfig-addon-preis]');
            if (preisEl) { preisEl.textContent = p !== undefined ? '+ ' + euro(p) + ' pro Person' : ''; }
        });
        z.addons = qa('[data-konfig-addon]').filter(function (b) { return b.checked; }).map(function (b) { return parseInt(b.value, 10); });

        var paketEl = q('[data-konfig-paket]'), ppEl = q('[data-konfig-pp]'), gesEl = q('[data-konfig-gesamt]'), hinweis = q('[data-konfig-hinweis]'), indi = q('[data-konfig-indikativ]');
        var pp = null;
        if (k && i !== null && tab[schluessel(k, t)] !== undefined) {
            pp = tab[schluessel(k, t)];
            z.addons.forEach(function (a) { if (tab['a' + a] !== undefined) { pp += tab['a' + a]; } });
        }
        if (paketEl) { paketEl.textContent = i !== null ? 'Paket: ' + d.pakete[i].name : 'Für diese Gästezahl gibt es kein Paket.'; }
        if (ppEl) { ppEl.textContent = pp !== null ? euro(pp) : '–'; }
        if (gesEl) { gesEl.textContent = pp !== null ? euro(pp * z.pax) : '–'; }
        if (hinweis) { hinweis.hidden = !!k; }
        if (indi) { indi.hidden = !(k && ((t === 'lunch' && k.indikativ_lunch) || (t === 'dinner' && k.indikativ_dinner) || (t === 'day_night' && (k.indikativ_lunch || k.indikativ_dinner)))); }

        var feld = function (n, v) { var f = q('[data-konfig-feld="' + n + '"]'); if (f) { f.value = v === null || v === undefined ? '' : v; } };
        feld('konzept', z.konzept); feld('tageszeit', t); feld('abend_konzept', t === 'day_night' ? (z.abend || (k && k.id)) : '');
        feld('addons', z.addons.join(',')); feld('pax', z.pax);
        var sende = q('[data-konfig-form] button[type="submit"]');
        if (sende && sende.getAttribute('data-ziel') !== '0') { sende.disabled = pp === null || q('[data-konfig-form]').getAttribute('action') === '#'; }
        if (k) { history.replaceState(null, '', '#k=' + k.id + '&t=' + (t || '') + '&ab=' + (z.abend || '') + '&a=' + z.addons.join('.') + '&p=' + z.pax); }
    }

    qa('[data-konfig-konzept]').forEach(function (b) { b.addEventListener('click', function () { z.konzept = parseInt(b.getAttribute('data-konfig-konzept'), 10); render(); }); });
    qa('[data-konfig-tageszeit]').forEach(function (b) { b.addEventListener('click', function () { z.tageszeit = b.getAttribute('data-konfig-tageszeit'); render(); }); });
    var abend = q('[data-konfig-abend]');
    if (abend) { abend.addEventListener('change', function () { z.abend = parseInt(abend.value, 10); render(); }); }
    qa('[data-konfig-addon]').forEach(function (b) { b.addEventListener('change', render); });
    var regler = q('[data-konfig-pax-regler]');
    var setzePax = function (v) { var n = parseInt(v, 10); if (!isNaN(n)) { z.pax = n; if (regler) { regler.value = n; } if (paxFeld) { paxFeld.value = n; } render(); } };
    if (regler) { regler.addEventListener('input', function () { setzePax(regler.value); }); }
    if (paxFeld) { paxFeld.addEventListener('change', function () { setzePax(paxFeld.value); }); }
    var teilen = q('[data-konfig-teilen]');
    if (teilen) { teilen.addEventListener('click', function () { if (navigator.clipboard) { navigator.clipboard.writeText(location.href); teilen.textContent = 'Link kopiert'; } }); }

    // Auswahl aus dem Link-Hash übernehmen
    var h = {};
    location.hash.replace(/^#/, '').split('&').forEach(function (p) { var kv = p.split('='); if (kv[0]) { h[kv[0]] = decodeURIComponent(kv[1] || ''); } });
    if (h.k && konzept(parseInt(h.k, 10))) { z.konzept = parseInt(h.k, 10); }
    if (h.t) { z.tageszeit = h.t; }
    if (h.ab) { z.abend = parseInt(h.ab, 10) || null; if (abend && z.abend) { abend.value = z.abend; } }
    if (h.p) { setzePax(h.p); }
    if (h.a) { h.a.split('.').forEach(function (a) { var box = q('[data-konfig-addon][value="' + a + '"]'); if (box) { box.checked = true; } }); }
    render();
    if (h.s === 'anfrage') { var ziel = document.getElementById('konfig-anfrage'); if (ziel) { ziel.scrollIntoView({ block: 'start' }); } }
})();
</script>
