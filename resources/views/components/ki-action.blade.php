{{--
    Spec 53 / Paket C — sofortiges Feedback für Cockpit-Aktionsknöpfe (KI-Jobs UND einfache
    Freigabe-/Verwurf-Klicks, die einen Livewire-Roundtrip anstoßen). Löst die kopierten
    wire:loading-Span-Paare ab: EIN Klick → sofort sichtbarer Zwischenzustand, noch bevor der
    Server geantwortet hat (Alpine `pending`), plus eine zweite, serverseitige Absicherung
    (`wire:loading.attr="disabled"`).

    Props:
      action    (Pflicht) voller Wire-Ausdruck inkl. Argumenten, z. B. "neuAnreichern(12, true)".
      target    wire:target-Ausdruck; Default = action (byte-gleich mit dem $wire-Aufruf unten —
                aus DERSELBEN Variable erzeugt, damit beide nie auseinanderlaufen).
      label     Idle-Text (variant=icon: nur als title/aria-label, nicht sichtbar).
      busy      Text während der Anfrage läuft (Default „Wird eingereiht …").
      flash     Text nach Erfolg, kurz eingeblendet (Default: "<label> — erledigt").
      error     Persistenter Server-Fehler (z. B. deferred.enrich.error) → Ruhezustand zeigt
                Fehler-Optik + $retry-Text statt $label, title = $error.
      retry     Label im Fehlerzustand (Default = label).
      variant   link (Default, Text-Link-Optik) | ghostXs | ghost | primary | icon (nur Icon, kein Text).
      icon      Heroicon-Name (optional bei link/ghostXs/primary, Pflicht sinngemäß bei icon).
      title     Tooltip-Override (Default: $error im Fehlerzustand, sonst der Anzeige-Text).
      confirm   window.confirm()-Text VOR dem $wire-Call (wire:confirm greift nicht bei $wire.*-Calls).
      disabled  hart deaktiviert (z. B. „geplant" wartet auf Freigabe der Stufe darüber).
      before    Roher Alpine-Ausdruck, SOFORT beim Klick ausgeführt (vor dem $wire-Call), z. B.
                "tab='worker'" — für Buttons, die zusätzlich zum Wire-Call einen reinen
                Client-Zustand umschalten. Nur mit statischen, entwicklerkontrollierten Strings
                befüllen (kein Nutzer-Input) — landet ungeprüft im Alpine-Ausdruck.

    Zeilen-Scope: der Row-Root braucht `x-data="{ busy: null }"` (step-zeile.blade.php, ergebnis.blade.php) —
    dieses Element schreibt beim Klick optimistisch hinein, damit Nachbar-Knöpfe der Zeile
    währenddessen sichtbar deaktiviert sind (kein Doppelklick auf eine andere Aktion derselben Zeile).
--}}
@props([
    'action',
    'target' => null,
    'label' => null,
    'busy' => 'Wird eingereiht …',
    'flash' => null,
    'error' => null,
    'retry' => null,
    'variant' => 'link',
    'icon' => null,
    'title' => null,
    'confirm' => null,
    'disabled' => false,
    'before' => null,
])
@php
    // Byte-gleich: wire:target und der $wire-Aufruf entstehen aus DERSELBEN Variable — sonst
    // matcht wire:loading den falschen Request, sobald jemand nur eine Stelle anpasst.
    $wireAusdruck = (string) $action;
    $wireZiel = $target !== null && $target !== '' ? (string) $target : $wireAusdruck;
    $hatFehler = $error !== null && trim((string) $error) !== '';
    $anzeigeLabel = $hatFehler ? ($retry ?: ($label ?: 'neu versuchen')) : ($label ?: '');
    $anzeigeTitel = $title ?: ($hatFehler ? $error : $anzeigeLabel);
    $variantKlasse = match ($variant) {
        'primary' => \Platform\FoodAlchemist\Support\Ui::maps()['btnPrimary'],
        'ghostXs' => \Platform\FoodAlchemist\Support\Ui::maps()['btnGhostXs'],
        'ghost' => \Platform\FoodAlchemist\Support\Ui::maps()['btnGhost'],
        'icon' => 'inline-flex items-center text-gray-400 hover:text-gray-200',
        default => 'text-violet-300 hover:text-violet-200 underline decoration-violet-300/40 underline-offset-2',
    };
    $fehlerKlasse = $hatFehler ? 'text-rose-300 hover:text-rose-200' : '';
    $hatConfirm = $confirm !== null && $confirm !== '';
    $hartDeaktiviert = $disabled ? 'true' : 'false';
@endphp
<span
    {{ $attributes->merge(['class' => trim($variantKlasse . ' ' . $fehlerKlasse) . ' cursor-pointer select-none' . ($disabled ? ' opacity-40 pointer-events-none' : '')]) }}
    role="button"
    tabindex="0"
    @if($disabled) aria-disabled="true" @endif
    data-ki-action="{{ $wireAusdruck }}"
    wire:loading.attr="disabled"
    wire:target="{{ $wireZiel }}"
    x-data="{ pending: false, ok: false, err: null }"
    x-cloak
    :class="{ 'opacity-50 cursor-wait': pending, 'opacity-30 pointer-events-none': !pending && (typeof busy !== 'undefined' && busy) }"
    @if($anzeigeTitel !== '' && $anzeigeTitel !== null) title="{{ $anzeigeTitel }}" @endif
    @if($variant === 'icon') aria-label="{{ $label }}" @endif
    @if($hatConfirm) data-ki-confirm="{{ $confirm }}" @endif
    @keydown.enter.prevent="$el.click()"
    @keydown.space.prevent="$el.click()"
    @click="
        if ({{ $hartDeaktiviert }} || pending || $el.hasAttribute('disabled')) { return }
        if ($el.dataset.kiConfirm && ! window.confirm($el.dataset.kiConfirm)) { return }
        @if($before){{ $before }};@endif
        pending = true; ok = false; err = null;
        if (typeof busy !== 'undefined') { busy = true }
        // Absicherung: antwortet der Server nie (Netzwerkabbruch, 419/500 ohne rejectetes Promise —
        // ungeklärt, ob Livewire das in jeder Version tut), bleibt der Knopf sonst für immer im Spinner.
        let kiTimeout = setTimeout(() => {
            pending = false; err = 'Keine Antwort — Seite prüfen.';
            if (typeof busy !== 'undefined') { busy = false }
        }, 45000)
        $wire.{{ $wireAusdruck }}
            .then(() => {
                clearTimeout(kiTimeout)
                pending = false; ok = true;
                if (typeof busy !== 'undefined') { busy = false }
                setTimeout(() => { ok = false }, 1600)
            })
            .catch((e) => {
                clearTimeout(kiTimeout)
                pending = false; err = (e && e.message) ? e.message : 'Fehler — bitte erneut versuchen.';
                if (typeof busy !== 'undefined') { busy = false }
            })
    "
>
    <template x-if="pending">
        <span class="inline-flex items-center gap-1">
            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')
            @if($variant !== 'icon')<span>{{ $busy }}</span>@endif
        </span>
    </template>
    <template x-if="!pending && ok">
        <span class="inline-flex items-center gap-1 text-emerald-400">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            @if($variant !== 'icon')<span>{{ $flash ?: ($anzeigeLabel !== '' ? $anzeigeLabel . ' — erledigt' : 'Erledigt') }}</span>@endif
        </span>
    </template>
    <template x-if="!pending && !ok && err">
        <span class="inline-flex items-center gap-1 text-rose-300">
            @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5')
            @if($variant !== 'icon')<span x-text="err"></span>@endif
        </span>
    </template>
    <template x-if="!pending && !ok && !err">
        <span class="inline-flex items-center gap-1">
            @if($icon)@svg($icon, 'w-3.5 h-3.5')@endif
            @if($variant !== 'icon')<span>{{ $anzeigeLabel }}</span>@endif
        </span>
    </template>
</span>
