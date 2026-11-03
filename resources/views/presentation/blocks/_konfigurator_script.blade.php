{{-- Spec 94 · Logik des Konfigurator-Links (ohne Framework): Assistent mit einem Schritt pro Ansicht (Schritt-Leiste, Weiter,
     Zurück), Gästezahl steuert Paket und Preise, Preise nur aus der eingefrorenen Preistabelle (Summe und × Gäste). Auswahl
     im URL-Hash zum Teilen und nach dem Absenden. Ohne Skript sind alle Schritte untereinander sichtbar. --}}
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
    var setze = function (sel, text) { qa(sel).forEach(function (e) { e.textContent = text; }); };
    var setzeIn = function (wurzel, sel, text) { var e = wurzel.querySelector(sel); if (e) { e.textContent = text; } };
    var z = { konzept: null, tageszeit: null, abend: null, addons: [], pax: null, schritt: null };
    var konzept = function (id) { return d.konzepte.filter(function (k) { return k.id === id; })[0] || null; };
    var min = d.pakete.length ? d.pakete[0].von : 1, max = d.pakete.length ? d.pakete[d.pakete.length - 1].bis : 1;
    var start = q('[data-konfig-pax]');
    z.pax = start ? parseInt(start.value, 10) : min;
    document.documentElement.classList.add('pt-konfig-js');

    // Schritte: nur die in der Leiste stehen (= vorhandene)
    var schritte = qa('[data-konfig-zu]').map(function (b) { return b.getAttribute('data-konfig-zu'); });
    var kopf = q('[data-konfig-kopfleiste]');
    var zeitZuerst = !kopf || kopf.getAttribute('data-konfig-reihenfolge') !== 'konzept_zuerst';
    var hatZeit = schritte.indexOf('tageszeit') >= 0;
    var erfuellt = function (s) { return s === 'konzepte' ? !!z.konzept : (s === 'tageszeit' ? !!z.tageszeit : true); };
    // Konzept zur Tageszeit verfügbar? (Tag = Lunch-Concept, Abend = Dinner-Concept, Tag und Abend = Lunch-Concept)
    var passt = function (k, t) { return !t || (t === 'dinner' ? k.dinner : k.lunch); };
    qa('[data-konfig-gruppe]').forEach(function (sec) {
        var g = sec.getAttribute('data-konfig-gruppe'), i = schritte.indexOf(g);
        var nr = sec.querySelector('.pt-konfig-nr'); if (nr && i >= 0) { nr.setAttribute('data-nr', String(i + 1)); }
        if (i < 0 || sec.getAttribute('data-konfig-schritt') === 'summe') { return; }
        var nav = document.createElement('div');
        nav.className = 'pt-konfig-weiter';
        if (i > 0) { var zur = document.createElement('button'); zur.type = 'button'; zur.className = 'pt-konfig-chip'; zur.textContent = '← ' + q('[data-konfig-zu="' + schritte[i - 1] + '"]').textContent; zur.setAttribute('data-konfig-geh', schritte[i - 1]); nav.appendChild(zur); }
        if (i < schritte.length - 1) { var wei = document.createElement('button'); wei.type = 'button'; wei.className = 'pt-cta-btn'; wei.textContent = 'Weiter'; wei.setAttribute('data-konfig-geh', schritte[i + 1]); wei.setAttribute('data-konfig-weiter', ''); nav.appendChild(wei); }
        if (nav.children.length) { sec.appendChild(nav); }
    });
    function zeige(schritt, scrollen) {
        if (schritte.indexOf(schritt) < 0) { schritt = schritte[0]; }
        // vorwärts nur, wenn die Schritte davor erfüllt sind (Konzept gewählt, ggf. Zeitfenster gewählt)
        for (var j = 0; j < schritte.indexOf(schritt); j++) { if (!erfuellt(schritte[j])) { schritt = schritte[j]; break; } }
        z.schritt = schritt;
        qa('[data-konfig-gruppe]').forEach(function (sec) { sec.hidden = sec.getAttribute('data-konfig-gruppe') !== schritt; });
        var gaeste = q('[data-konfig-gaeste]'); if (gaeste) { gaeste.hidden = schritt !== schritte[0]; }
        qa('[data-konfig-zu]').forEach(function (b) {
            var an = b.getAttribute('data-konfig-zu') === schritt;
            b.setAttribute('aria-current', an ? 'step' : 'false');
            if (an && b.scrollIntoView) { b.parentNode.scrollLeft = Math.max(0, b.offsetLeft - 16); }
        });
        if (scrollen) { var ziel = q('[data-konfig-seite]'); var leiste = q('[data-konfig-kopfleiste]'); if (leiste) { window.scrollTo({ top: leiste.getBoundingClientRect().top + window.scrollY - 1, behavior: 'smooth' }); } else if (ziel) { ziel.scrollIntoView(); } }
        render();
    }

    function paketIndex() {
        for (var i = 0; i < d.pakete.length; i++) { if (z.pax >= d.pakete[i].von && z.pax <= d.pakete[i].bis) { return i; } }
        return null;
    }
    function zeitFuer(k) {
        if (z.tageszeit && (z.tageszeit === 'day_night' ? k.lunch : k[z.tageszeit])) { return z.tageszeit; }
        return k.dinner ? 'dinner' : 'lunch';
    }
    function schluessel(k, t) { return t === 'day_night' ? 'dn' + k.id + ':' + (z.abend || k.id) : 'k' + k.id + ':' + t; }
    function addonSichtbar(zeile, t) {
        var geb = zeile.getAttribute('data-gebunden'), tz = zeile.getAttribute('data-tageszeit');
        if (geb && (!z.konzept || String(z.konzept) !== geb)) { return false; }
        return tz === 'beide' || t === 'day_night' || tz === t;
    }
    function render() {
        if (zeitZuerst && hatZeit && z.konzept && z.tageszeit && !passt(konzept(z.konzept), z.tageszeit)) { z.konzept = null; }
        var k = z.konzept ? konzept(z.konzept) : null;
        var t = k ? zeitFuer(k) : (hatZeit ? z.tageszeit : null);
        if (k && !d.tageszeit_aktiv) { z.tageszeit = t; }
        var i = paketIndex(), tab = i !== null ? d.preise[i] || {} : {};

        qa('[data-konfig-konzept]').forEach(function (b) { b.setAttribute('aria-pressed', String(parseInt(b.getAttribute('data-konfig-konzept'), 10) === z.konzept)); });
        var sichtbar = 0;
        qa('[data-konfig-karte]').forEach(function (karte) {
            var kk = konzept(parseInt(karte.getAttribute('data-konfig-karte'), 10));
            var an = !(zeitZuerst && hatZeit && z.tageszeit) || passt(kk, z.tageszeit);
            karte.hidden = !an; if (an) { sichtbar++; }
            var tz = z.tageszeit || (kk.dinner ? 'dinner' : 'lunch');
            // Kartenpreis live: dieses Konzept bei aktueller Gästezahl und Tageszeit (wie die Leiste bei einer Tageszeit ohne
            // Extras); bei „Tag und Abend“ der eigene Teil, der Kombipreis steht in der Leiste.
            var feld = karte.querySelector('[data-konfig-karte-preis-feld]');
            if (feld && i !== null) {
                var kt = zeitFuer(kk), kombi = kt === 'day_night';
                if (kombi) { kt = kk.lunch ? 'lunch' : 'dinner'; }
                var kp = tab['k' + kk.id + ':' + kt];
                feld.hidden = kp === undefined;
                setzeIn(feld, '[data-konfig-karte-preis]', kp !== undefined ? euro(kp) : '');
                var kh = feld.querySelector('[data-konfig-karte-kombi]'); if (kh) { kh.hidden = !kombi; }
            }
            var badge = karte.querySelector('[data-konfig-indikativ-badge]');
            if (badge) { badge.hidden = !((tz === 'lunch' && kk.indikativ_lunch) || (tz === 'dinner' && kk.indikativ_dinner) || (tz === 'day_night' && (kk.indikativ_lunch || kk.indikativ_dinner))); }
        });
        var keins = q('[data-konfig-kein-konzept]'); if (keins) { keins.hidden = sichtbar > 0; }
        qa('[data-konfig-tageszeit]').forEach(function (b) {
            var wert = b.getAttribute('data-konfig-tageszeit');
            var moeglich = !k || (wert === 'day_night' ? (k.lunch && d.konzepte.some(function (x) { return x.dinner; })) : k[wert]);
            b.disabled = !moeglich;
            b.setAttribute('aria-pressed', String(t === wert));
            var p = k && moeglich ? tab[schluessel(k, wert)] : undefined;
            if (!k) {   // noch kein Konzept (Tageszeit zuerst): günstigster Preis der Stufe für dieses Zeitfenster
                d.konzepte.forEach(function (x) { if (passt(x, wert)) { var v = tab[wert === 'day_night' ? 'dn' + x.id + ':' + x.id : 'k' + x.id + ':' + wert]; if (v !== undefined && (p === undefined || v < p)) { p = v; } } });
                b.disabled = !d.konzepte.some(function (x) { return passt(x, wert) && (wert !== 'day_night' || d.konzepte.some(function (y) { return y.dinner; })); });
                if (b.disabled && z.tageszeit === wert) { z.tageszeit = null; }
            }
            var pe = b.querySelector('[data-konfig-tageszeit-preis]');
            if (pe) { pe.textContent = p !== undefined ? (k ? '' : 'ab ') + euro(p) + ' pro Person, all-in netto' : ''; }
        });
        var abendFeld = q('[data-konfig-abend-feld]'); if (abendFeld) { abendFeld.hidden = t !== 'day_night'; }
        qa('[data-konfig-addon-zeile]').forEach(function (zeile) {
            var id = parseInt(zeile.getAttribute('data-konfig-addon-zeile'), 10), sicht = addonSichtbar(zeile, t);
            zeile.hidden = !sicht;
            var box = zeile.querySelector('[data-konfig-addon]');
            if (!sicht && box.checked) { box.checked = false; }
            var pe = zeile.querySelector('[data-konfig-addon-preis]');
            if (pe) { pe.textContent = tab['a' + id] !== undefined ? '+ ' + euro(tab['a' + id]) + ' pro Person' : ''; }
        });
        z.addons = qa('[data-konfig-addon]').filter(function (b) { return b.checked; }).map(function (b) { return parseInt(b.value, 10); });

        var pp = null, ab = false;
        if (k && i !== null && tab[schluessel(k, t)] !== undefined) {
            pp = tab[schluessel(k, t)];
            z.addons.forEach(function (a) { if (tab['a' + a] !== undefined) { pp += tab['a' + a]; } });
        } else if (i !== null) {   // noch kein Konzept: günstigster Preis der Stufe als „ab“
            Object.keys(tab).forEach(function (s) { if (s.charAt(0) === 'k' && (pp === null || tab[s] < pp)) { pp = tab[s]; } });
            ab = pp !== null;
        }
        setze('[data-konfig-pp]', pp !== null ? euro(pp) : '—');
        setze('[data-konfig-gesamt]', pp !== null ? euro(pp * z.pax) : '—');
        qa('[data-konfig-ab]').forEach(function (e) { e.hidden = !ab; });
        setze('[data-konfig-pax-anzeige]', String(z.pax));
        setze('[data-konfig-stufe]', i !== null ? d.pakete[i].von + '–' + d.pakete[i].bis : 'keine');
        qa('[data-konfig-pax]').forEach(function (f) { if (document.activeElement !== f) { f.value = z.pax; } });
        qa('[data-konfig-pax-regler]').forEach(function (r) { r.value = z.pax; });
        var paketEl = q('[data-konfig-paket]'); if (paketEl) { paketEl.textContent = i !== null ? 'Preisstufe ' + d.pakete[i].name : 'Für diese Gästezahl gibt es keine Preisstufe.'; }
        var hinweis = q('[data-konfig-hinweis]'); if (hinweis) { hinweis.hidden = !!k; }
        var indi = q('[data-konfig-indikativ]');
        if (indi) { indi.hidden = !(k && ((t === 'lunch' && k.indikativ_lunch) || (t === 'dinner' && k.indikativ_dinner) || (t === 'day_night' && (k.indikativ_lunch || k.indikativ_dinner)))); }
        var liste = q('[data-konfig-auswahl]');
        if (liste) {
            var zeilen = [];
            if (k) { zeilen.push(k.titel + ' · ' + ({ lunch: 'Tag', dinner: 'Abend', day_night: 'Tag und Abend' })[t] + (t === 'day_night' && z.abend && z.abend !== k.id ? ' (Abend: ' + (konzept(z.abend) || {}).titel + ')' : '')); }
            z.addons.forEach(function (a) { var x = d.addons.filter(function (y) { return y.id === a; })[0]; if (x) { zeilen.push('+ ' + x.titel); } });
            zeilen.push(z.pax + ' Gäste');
            liste.innerHTML = '';
            zeilen.forEach(function (text) { var li = document.createElement('li'); li.textContent = text; liste.appendChild(li); });
        }
        qa('[data-konfig-weiter]').forEach(function (b) { var sec = b.closest('[data-konfig-gruppe]'); b.disabled = !erfuellt(sec ? sec.getAttribute('data-konfig-gruppe') : 'konzepte'); });

        var feld = function (n, v) { var f = q('[data-konfig-feld="' + n + '"]'); if (f) { f.value = v === null || v === undefined ? '' : v; } };
        feld('konzept', z.konzept); feld('tageszeit', t); feld('abend_konzept', t === 'day_night' ? (z.abend || (k && k.id)) : '');
        feld('addons', z.addons.join(',')); feld('pax', z.pax);
        var form = q('[data-konfig-form]'), sende = form ? form.querySelector('button[type="submit"]') : null;
        if (sende) { sende.disabled = !k || ab || form.getAttribute('action') === '#'; }
        history.replaceState(null, '', '#k=' + (z.konzept || '') + '&t=' + (t || '') + '&ab=' + (z.abend || '') + '&a=' + z.addons.join('.') + '&p=' + z.pax + '&s=' + (z.schritt || ''));
    }

    var setzePax = function (v) { var n = parseInt(v, 10); if (!isNaN(n)) { z.pax = Math.max(min, Math.min(max, n)); render(); } };
    qa('[data-konfig-konzept]').forEach(function (b) { b.addEventListener('click', function () { z.konzept = parseInt(b.getAttribute('data-konfig-konzept'), 10); render(); }); });
    // Stufe 5b: Detailansicht je Konzept (Dialog), „Konzept wählen“ direkt daraus
    qa('[data-konfig-details]').forEach(function (b) { b.addEventListener('click', function () { var dlg = q('[data-konfig-detail="' + b.getAttribute('data-konfig-details') + '"]'); if (dlg && dlg.showModal) { dlg.showModal(); } }); });
    qa('[data-konfig-detail-zu]').forEach(function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });
    qa('[data-konfig-detail]').forEach(function (dlg) { dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } }); });
    qa('[data-konfig-waehlen]').forEach(function (b) { b.addEventListener('click', function () { z.konzept = parseInt(b.getAttribute('data-konfig-waehlen'), 10); b.closest('dialog').close(); render(); }); });
    // Extra aus dem Speisen-Detail dazunehmen: Haken setzen wie ein Klick auf die Karte
    qa('[data-konfig-addon-waehlen]').forEach(function (b) { b.addEventListener('click', function () {
        var box = q('[data-konfig-addon][value="' + b.getAttribute('data-konfig-addon-waehlen') + '"]');
        if (box && !box.checked) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); }
        b.closest('dialog').close(); render();
    }); });
    qa('[data-konfig-tageszeit]').forEach(function (b) { b.addEventListener('click', function () { z.tageszeit = b.getAttribute('data-konfig-tageszeit'); render(); }); });
    var abend = q('[data-konfig-abend]'); if (abend) { abend.addEventListener('change', function () { z.abend = parseInt(abend.value, 10); render(); }); }
    qa('[data-konfig-addon]').forEach(function (b) { b.addEventListener('change', render); });
    qa('[data-konfig-pax-regler]').forEach(function (r) { r.addEventListener('input', function () { setzePax(r.value); }); });
    qa('[data-konfig-pax]').forEach(function (f) { f.addEventListener('change', function () { setzePax(f.value); }); });
    var schritt = function () { return z.pax >= 200 ? 25 : 10; };
    qa('[data-konfig-pax-minus]').forEach(function (b) { b.addEventListener('click', function () { setzePax(z.pax - schritt()); }); });
    qa('[data-konfig-pax-plus]').forEach(function (b) { b.addEventListener('click', function () { setzePax(z.pax + schritt()); }); });
    qa('[data-konfig-zu]').forEach(function (b) { b.addEventListener('click', function () { zeige(b.getAttribute('data-konfig-zu'), true); }); });
    document.addEventListener('click', function (e) { var g = e.target.closest('[data-konfig-geh]'); if (g) { zeige(g.getAttribute('data-konfig-geh'), true); } });
    var teilen = q('[data-konfig-teilen]');
    if (teilen) { teilen.addEventListener('click', function () { if (navigator.clipboard) { navigator.clipboard.writeText(location.href); teilen.textContent = 'Link kopiert'; } }); }

    // Auswahl aus dem Link-Hash übernehmen (Teilen, Rückkehr nach dem Absenden)
    var h = {};
    location.hash.replace(/^#/, '').split('&').forEach(function (p) { var kv = p.split('='); if (kv[0]) { h[kv[0]] = decodeURIComponent(kv[1] || ''); } });
    if (h.k && konzept(parseInt(h.k, 10))) { z.konzept = parseInt(h.k, 10); }
    if (h.t) { z.tageszeit = h.t; }
    if (h.ab) { z.abend = parseInt(h.ab, 10) || null; if (abend && z.abend) { abend.value = z.abend; } }
    if (h.p) { var n = parseInt(h.p, 10); if (!isNaN(n)) { z.pax = Math.max(min, Math.min(max, n)); } }
    if (h.a) { h.a.split('.').forEach(function (a) { var box = q('[data-konfig-addon][value="' + a + '"]'); if (box) { box.checked = true; } }); }
    var status = new URLSearchParams(location.search).get('anfrage');
    zeige(status ? 'anfrage' : (h.s || schritte[0]), !!status);
})();
</script>
