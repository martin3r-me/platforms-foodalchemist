<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\HtmlString;
use Livewire\Features\SupportEvents\SupportEvents;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;

/**
 * Handy-Lesemodus (#1003): am Handy (unter 768 px) sind in Lese-Bereichen alle Ändern-Aktionen aus.
 *
 * Ansicht: Eine Aktion meldet sich mit einem Schlüssel an (`data-fa-aendern="kopf.aktion"`); das CSS
 * (foodalchemist-pass.css) blendet sie in [data-fa-handy-lesen] am Handy aus, außer sie steht in
 * config('foodalchemist.handy.aendern_frei') — dann trägt sie data-fa-aendern-frei und bleibt sichtbar.
 *
 * Server: Livewire-Anfragen vom Handy tragen die Kopfzeile X-FA-Handy: 1 (gesetzt in detail-sidebar); jede Anfrage
 * zusätzlich das Cookie fa_handy (gesetzt von JS_MODUS, dieselbe Entscheidung). So kann auch der erste Seitenaufbau
 * (Direktlink ?rezept=…) die Handbuch-Ansicht statt des Desktop-Details rendern.
 * Komponenten mit Concerns\HandyLesebereich nehmen dann nur ihre Lese-Methoden und freigeschaltete Methoden an
 * (Schlüssel = Methodenname oder 'ki.<methode>', dieselbe Liste wie oben). Schutz vor versehentlichem Ändern,
 * keine Rechteprüfung: die Kopfzeile kommt vom Gerät.
 *
 * Zentral statt pro View: spätere Handy-Wartung schaltet einzelne Aktionen hier frei, ohne Views anzufassen.
 */
final class HandyLesemodus
{
    public const KOPFZEILE = 'X-FA-Handy';

    /** Cookie mit derselben Entscheidung wie <html data-fa-handy> ('1' | '0'), unverschlüsselt (FoodAlchemistServiceProvider). */
    public const COOKIE = 'fa_handy';

    public const MELDUNG = 'Am Handy kannst du hier nur lesen. Bitte am Computer bearbeiten.';

    /**
     * EINE Entscheidung „Handy-Oberfläche“ für Layout, CSS und Server — nie die Fensterbreite allein:
     * Touch-Gerät ohne Maus (hover: none, pointer: coarse) UND schmaler als 768 px, oder bewusst gewählt. Ein schmales Laptop-Fenster
     * (Maus) ist nie Handy. Notausgang: ?ansicht=desktop bzw. ?ansicht=handy (merkt sich das Gerät, localStorage).
     * Setzt <html data-fa-handy>, daran hängen die CSS-Regeln. Für x-data-Attribute: keine doppelten Anführungszeichen.
     */
    public const JS_MODUS = "(window.faHandyModus = window.faHandyModus || function () {"
        ." let a = null;"
        ." try { const p = new URLSearchParams(location.search).get('ansicht');"
        ." if (p === 'handy' || p === 'desktop') localStorage.setItem('fa-ansicht', p);"
        ." a = localStorage.getItem('fa-ansicht'); } catch (e) {}"
        ." const h = a === 'handy' || (a !== 'desktop' && window.matchMedia('(hover: none) and (pointer: coarse) and (max-width: 47.99rem)').matches);"
        ." document.documentElement.toggleAttribute('data-fa-handy', h);"
        ." document.cookie = 'fa_handy=' + (h ? '1' : '0') + '; path=/; max-age=31536000; SameSite=Lax';"
        ." return h; })";

    public static function frei(string $schluessel): bool
    {
        return in_array($schluessel, (array) config('foodalchemist.handy.aendern_frei', []), true);
    }

    /** Attribute für ein Ändern-Element: `<button {{ HandyLesemodus::attr('gp.artikel-vorschlagen') }}>`. */
    public static function attr(string $schluessel): HtmlString
    {
        $attr = 'data-fa-aendern="'.e($schluessel).'"';

        return new HtmlString(self::frei($schluessel) ? $attr.' data-fa-aendern-frei' : $attr);
    }

    public static function anfrageVomHandy(): bool
    {
        return request()->header(self::KOPFZEILE) === '1' || request()->cookie(self::COOKIE) === '1';
    }

    /**
     * Livewire-Aufruf am Handy abweisen? Interne Aufrufe ($refresh, $set …) laufen immer. Ein Ereignis
     * (`__dispatch`, z. B. recipe-selected aus der Liste) zählt als Aufruf seiner Listener-Methode.
     */
    public static function abgewiesen(object $component, string $methode, array $params = []): bool
    {
        if (! in_array(HandyLesebereich::class, class_uses_recursive($component), true)
            || str_starts_with($methode, '$')
            || ! self::anfrageVomHandy()) {
            return false;
        }
        if ($methode === '__dispatch') {
            $ereignis = (string) ($params[0] ?? '');
            if (! in_array($ereignis, SupportEvents::getListenerEventNames($component), true)) {
                return false;   // unbekanntes Ereignis: Livewire meldet den Fehler selbst
            }
            $methode = (string) SupportEvents::getListenerMethodName($component, $ereignis);
        }

        return ! in_array($methode, $component->handyLeseMethoden(), true)
            && ! self::frei($methode)
            && ! self::frei('ki.'.$methode);
    }
}
