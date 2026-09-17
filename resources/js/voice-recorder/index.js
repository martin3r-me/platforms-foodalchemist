/**
 * Spec 53 / Paket D — gemeinsamer MediaRecorder-Baustein für Sprachbefehl (voice-modal) und
 * Diktat (planung/partials/diktat). Vorher hatte jede Aufnahmestelle ihre eigene Kopie mit
 * hart kodiertem `audio/webm;codecs=opus` — auf Safari wirft das `NotSupportedError`, BEVOR
 * überhaupt aufgenommen wird (Safari kennt kein Opus/WebM), und ohne try/catch um
 * `getUserMedia` blieb das unsichtbar.
 *
 * `window.FaVoiceRecorder(opts)` liefert ein Alpine-Datenobjekt (Factory, keine Singleton-
 * Instanz — jede Aufnahmestelle bekommt ihren eigenen Zustand). Idempotent: mehrfaches Laden
 * des Bundles (z. B. über zwei Seiten mit `data-navigate-once`) überschreibt die Factory nicht
 * neu, sondern lässt die zuerst geladene Fassung stehen.
 */
(function (global) {
  if (global.FaVoiceRecorder) {
    return;
  }

  // Reihenfolge ist die Priorität: erstes vom Browser unterstütztes Format gewinnt.
  // '' am Ende = MediaRecorder ohne mimeType (Browser wählt selbst) — letzter Ausweg.
  var MIME_KANDIDATEN = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/mp4;codecs=mp4a.40.2',
    'audio/mp4',
    'audio/ogg;codecs=opus',
    '',
  ];

  var EXT_VON_MIME = {
    'audio/webm': 'webm',
    'audio/mp4': 'mp4',
    'audio/ogg': 'ogg',
  };

  function unterstuetztAufnahme() {
    return !!(
      global.isSecureContext !== false
      && typeof MediaRecorder !== 'undefined'
      && global.navigator
      && global.navigator.mediaDevices
      && typeof global.navigator.mediaDevices.getUserMedia === 'function'
    );
  }

  function gewaehlteMime() {
    if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
      return '';
    }
    for (var i = 0; i < MIME_KANDIDATEN.length; i++) {
      var kandidat = MIME_KANDIDATEN[i];
      if (kandidat === '' || MediaRecorder.isTypeSupported(kandidat)) {
        return kandidat;
      }
    }

    return '';
  }

  function endungVon(mime) {
    var basis = String(mime || '').split(';')[0].trim().toLowerCase();

    return EXT_VON_MIME[basis] || 'webm';
  }

  function fehlertextFuerGetUserMedia(err) {
    var namen = {
      NotAllowedError: 'Mikrofonzugriff verweigert — in der Adressleiste erlauben und neu laden.',
      NotFoundError: 'Kein Mikrofon gefunden.',
      NotReadableError: 'Mikrofon ist belegt oder nicht lesbar (von einer anderen App genutzt?).',
      SecurityError: 'Mikrofonzugriff ist in diesem Kontext nicht erlaubt (HTTPS erforderlich).',
    };

    return namen[err && err.name] || ('Aufnahme konnte nicht gestartet werden: ' + ((err && err.message) || 'unbekannter Fehler'));
  }

  /**
   * @param {object} opts
   * @param {string} opts.property      Livewire-Upload-Property (z. B. 'audio', 'briefAudio')
   * @param {number} [opts.maxMs]       Auto-Stopp nach dieser Aufnahmedauer (Default 20000)
   * @param {number} [opts.minMs]       Mindestdauer, sonst „Aufnahme zu kurz" (Default 700)
   * @param {(string|Function)} [opts.before]  Optionaler Hook (Alpine-Methodenname oder Funktion),
   *                                     VOR dem Mikrofonzugriff awaited (z. B. `$wire.set('diktatZiel', …)`).
   */
  global.FaVoiceRecorder = function (opts) {
    opts = opts || {};
    var property = opts.property;
    var maxMs = opts.maxMs || 20000;
    var minMs = opts.minMs || 700;
    var before = opts.before || null;

    return {
      rec: null,
      stream: null,
      chunks: [],
      laeuft: false,
      unterstuetzt: unterstuetztAufnahme(),
      fehler: null,
      sekunden: 0,
      pegel: 0,
      hochladenLaeuft: false,
      hochladenProgress: 0,
      _timer: null,
      _autoStop: null,
      _audioCtx: null,
      _analyser: null,
      _pegelRaf: null,
      _startedAt: 0,

      async start() {
        this.fehler = null;
        if (this.laeuft) {
          return;
        }
        if (!this.unterstuetzt) {
          this.fehler = 'Sprachaufnahme wird von diesem Browser nicht unterstützt.';

          return;
        }
        // WICHTIG (Safari): der AudioContext MUSS synchron im Klick-Handler entstehen, sonst
        // bleibt er 'suspended' und der Pegel bewegt sich nie. Deshalb VOR jedem `await`.
        try {
          var Ctx = global.AudioContext || global.webkitAudioContext;
          this._audioCtx = Ctx ? new Ctx() : null;
        } catch (e) {
          this._audioCtx = null;
        }

        if (typeof before === 'function') {
          await before();
        } else if (typeof before === 'string' && typeof this[before] === 'function') {
          await this[before]();
        }

        try {
          this.stream = await global.navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } });
        } catch (err) {
          this.fehler = fehlertextFuerGetUserMedia(err);
          this._schliesseAudioCtx();

          return;
        }

        var mime = gewaehlteMime();
        this.chunks = [];
        try {
          this.rec = mime ? new MediaRecorder(this.stream, { mimeType: mime }) : new MediaRecorder(this.stream);
        } catch (err) {
          this.fehler = fehlertextFuerGetUserMedia(err);
          this._raeumeAuf();

          return;
        }

        var self = this;
        this.rec.ondataavailable = function (e) {
          if (e.data && e.data.size > 0) {
            self.chunks.push(e.data);
          }
        };
        this.rec.onstop = function () {
          self._verarbeiteAufnahme(mime);
        };

        this.rec.start();
        this.laeuft = true;
        this.sekunden = 0;
        this._startedAt = Date.now();
        this._starteTimer();
        this._startePegel();
      },

      stop() {
        if (this.rec && this.laeuft) {
          this.rec.stop();                                            // onstop() räumt Timer/Pegel/Stream auf
        } else {
          this._raeumeAuf();
        }
        this.laeuft = false;
      },

      _verarbeiteAufnahme(mime) {
        var dauerMs = Date.now() - this._startedAt;
        var blob = new Blob(this.chunks, { type: mime || 'audio/webm' });
        this._raeumeAuf();

        if (dauerMs < minMs || blob.size === 0) {
          this.fehler = 'Aufnahme zu kurz — bitte etwas länger sprechen.';

          return;
        }

        var datei = new File([blob], 'befehl.' + endungVon(mime), { type: blob.type || 'audio/webm' });
        var self = this;
        this.hochladenLaeuft = true;
        this.hochladenProgress = 0;
        this.$wire.upload(
          property,
          datei,
          function () {                                                // finish
            self.hochladenLaeuft = false;
            self.hochladenProgress = 100;
          },
          function (err) {                                             // error — vorher: leerer Callback, Fehler unsichtbar
            self.hochladenLaeuft = false;
            self.hochladenProgress = 0;
            var status = err && err.status ? ' (HTTP ' + err.status + ')' : '';
            self.fehler = 'Hochladen fehlgeschlagen' + status + '.';
          },
          function (event) {                                           // progress
            self.hochladenProgress = (event && event.detail && event.detail.progress) || 0;
          },
        );
      },

      _starteTimer() {
        var self = this;
        this._timer = global.setInterval(function () {
          self.sekunden = Math.round((Date.now() - self._startedAt) / 1000);
        }, 250);
        this._autoStop = global.setTimeout(function () {
          self.stop();
        }, maxMs);
      },

      _startePegel() {
        if (!this._audioCtx || !this.stream) {
          return;
        }
        try {
          var quelle = this._audioCtx.createMediaStreamSource(this.stream);
          this._analyser = this._audioCtx.createAnalyser();
          this._analyser.fftSize = 512;
          quelle.connect(this._analyser);
          var puffer = new Uint8Array(this._analyser.fftSize);
          var self = this;
          var schleife = function () {
            if (!self.laeuft || !self._analyser) {
              return;
            }
            self._analyser.getByteTimeDomainData(puffer);
            var summe = 0;
            for (var i = 0; i < puffer.length; i++) {
              var v = (puffer[i] - 128) / 128;
              summe += v * v;
            }
            self.pegel = Math.min(1, Math.sqrt(summe / puffer.length) * 4);
            self._pegelRaf = global.requestAnimationFrame(schleife);
          };
          schleife();
        } catch (e) {
          // Pegelanzeige ist kosmetisch — kein Abbruch der Aufnahme, wenn sie fehlschlägt.
        }
      },

      _schliesseAudioCtx() {
        if (this._audioCtx) {
          try { this._audioCtx.close(); } catch (e) { /* bereits zu */ }
          this._audioCtx = null;
        }
      },

      _raeumeAuf() {
        if (this._timer) { global.clearInterval(this._timer); this._timer = null; }
        if (this._autoStop) { global.clearTimeout(this._autoStop); this._autoStop = null; }
        if (this._pegelRaf) { global.cancelAnimationFrame(this._pegelRaf); this._pegelRaf = null; }
        this._analyser = null;
        this._schliesseAudioCtx();
        this.pegel = 0;
        if (this.stream) {
          this.stream.getTracks().forEach(function (t) { t.stop(); });
          this.stream = null;
        }
      },
    };
  };
})(window);
