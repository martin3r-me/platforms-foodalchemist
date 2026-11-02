{{-- Spec 94 · Konfigurator-Schritt 5: Anfrage. Sendet nur die Auswahl; den Preis bestimmt der Server aus der Preistabelle.
     Honeypot (website) und Mindest-Ausfüllzeit (ts); in der internen Vorschau ohne Ziel. --}}
@php
    $ref = request()->route('token');
    $ziel = $ref !== null && request()->route('type') === 'konfigurator' ? route('foodalchemist.presentation.konfigurator_anfrage', ['token' => $ref]) : null;
    $status = request()->query('anfrage');
@endphp
<section class="pt-measure pt-konfig-schritt" id="konfig-anfrage" data-konfig-schritt="anfrage">
    <p class="pt-kicker">Schritt 5</p>
    <h2>{{ $style['titel'] ?? 'Unverbindlich anfragen' }}</h2>
    @if($status === 'ok')
        <p class="pt-konfig-ok" data-konfig-anfrage-ok>Vielen Dank. Ihre Anfrage ist angekommen, wir melden uns zeitnah.</p>
    @else
        @if($status === 'fehler')<p class="pt-konfig-fehler">Die Anfrage konnte nicht gesendet werden. Bitte Auswahl und Pflichtfelder prüfen.</p>@endif
        @if(isset($errors) && $errors->any())<p class="pt-konfig-fehler">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ $ziel ?? '#' }}" class="pt-konfig-form" data-konfig-form>
            @csrf
            <input type="hidden" name="konzept" data-konfig-feld="konzept">
            <input type="hidden" name="tageszeit" data-konfig-feld="tageszeit">
            <input type="hidden" name="abend_konzept" data-konfig-feld="abend_konzept">
            <input type="hidden" name="addons" data-konfig-feld="addons">
            <input type="hidden" name="pax" data-konfig-feld="pax">
            <input type="hidden" name="ts" value="{{ encrypt(now()->timestamp) }}">
            <label class="pt-konfig-honig" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            <label>Name*<input type="text" name="name" required maxlength="120" value="{{ old('name') }}"></label>
            <label>Firma<input type="text" name="firma" maxlength="160" value="{{ old('firma') }}"></label>
            <label>E-Mail*<input type="email" name="email" required maxlength="190" value="{{ old('email') }}"></label>
            <label>Telefon<input type="tel" name="telefon" maxlength="60" value="{{ old('telefon') }}"></label>
            <label>Wunschdatum<input type="date" name="datum" value="{{ old('datum') }}"></label>
            <label class="pt-konfig-breit">Nachricht<textarea name="nachricht" rows="4" maxlength="2000">{{ old('nachricht') }}</textarea></label>
            <label class="pt-konfig-breit pt-konfig-check"><input type="checkbox" name="datenschutz" value="1" required> Ich bin einverstanden, dass meine Angaben zur Bearbeitung der Anfrage gespeichert werden.*</label>
            <button type="submit" class="pt-cta-btn" @disabled($ziel === null)>Anfrage senden</button>
        </form>
    @endif
</section>
