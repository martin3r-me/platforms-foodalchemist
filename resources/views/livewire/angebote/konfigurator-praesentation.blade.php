{{-- Spec 94 · Konfigurator-Editor, Reiter Branding und Präsentation: Design, Branding, Übernehmen, Vorschau, Link. --}}
@php $gesperrt = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
<div class="flex flex-col gap-4" data-kf-praesentation>
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

    <fieldset @disabled($gesperrt) class="contents">
    <x-fa::section title="Design" icon="heroicon-o-swatch" description="Designs gestaltest du unter Einstellungen → Präsentations-Designs (Ausgabeform Konfigurator). Fehlende Konfigurator-Schritte ergänzt das System beim Anzeigen.">
        <x-fa::field label="Design" for="kfp-design"><x-fa::select id="kfp-design" wire:model="branding.presentation_design" :options="$designOptionen" /></x-fa::field>
        @if($designGeaendert)<x-fa::notice tone="warn">Design geändert seit Veröffentlichung. Erneut veröffentlichen, damit der Link es zeigt.</x-fa::notice>@endif
    </x-fa::section>

    <x-fa::section title="Branding" icon="heroicon-o-paint-brush" description="Gesetztes Branding übersteuert die Farben des Designs.">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3">
            <x-fa::field label="Primärfarbe" for="kfp-farbe" hint="#RRGGBB"><x-fa::input id="kfp-farbe" wire:model="branding.brand_color" /></x-fa::field>
            <x-fa::field label="Bandfarbe" for="kfp-band" optional hint="leer = aus der Primärfarbe"><x-fa::input id="kfp-band" wire:model="branding.band_color" /></x-fa::field>
            <x-fa::field label="Footer" for="kfp-footer" optional><x-fa::input id="kfp-footer" wire:model="branding.footer_text" /></x-fa::field>
        </div>
        <div class="flex justify-end"><x-fa::button wire:click="brandingSpeichern" data-kfp-branding-speichern>Branding speichern</x-fa::button></div>
        <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Branding übernehmen von" for="kfp-quelle"><x-fa::select id="kfp-quelle" wire:model.live="quelle" :options="['foodbook' => 'Foodbook', 'angebot' => 'Angebot', 'betrieb' => 'Betrieb (Logo, Design)']" /></x-fa::field>
            <x-fa::field label="Quelle" for="kfp-quelle-id"><x-fa::select id="kfp-quelle-id" wire:model="quelleId" :options="$quellen" placeholder="wählen" class="w-72" /></x-fa::field>
            <x-fa::button icon="heroicon-m-arrow-down-tray" wire:click="brandingUebernehmen" data-kfp-uebernehmen>Übernehmen</x-fa::button>
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Übernehmen kopiert die Werte einmalig, danach ist das Branding des Konfigurators unabhängig.</p>
    </x-fa::section>
    </fieldset>

    <x-fa::section title="Kundenlink" icon="heroicon-o-link" data-kfp-link>
        <div class="flex flex-wrap items-end gap-2">
            <x-fa::field label="Eigener Link-Name" for="kfp-slug" optional hint="leer = Zufalls-Link"><x-fa::input id="kfp-slug" wire:model="slug" placeholder="z. B. diva-licious-2026" /></x-fa::field>
            <x-fa::button variant="ghost" icon="heroicon-m-eye" :href="route('foodalchemist.angebote.konfiguratoren.praesentation', $konfiguratorId)" target="_blank">Vorschau</x-fa::button>
            <fieldset @disabled($gesperrt) class="contents">
                <x-fa::button variant="primary" icon="heroicon-m-globe-alt" wire:click="veroeffentlichen" data-kfp-veroeffentlichen>{{ $live ? 'Neu veröffentlichen' : 'Veröffentlichen' }}</x-fa::button>
                @if($live)<x-fa::button variant="danger" wire:click="zurueckziehen" wire:confirm="Link zurückziehen? Kunden sehen den Konfigurator dann nicht mehr.">Zurückziehen</x-fa::button>@endif
            </fieldset>
        </div>
        @if($live && $link)
            <p class="flex flex-wrap items-center gap-2"><x-fa::badge tone="ok">Live bis {{ $k->presentation_expires_at?->format('d.m.Y') }}</x-fa::badge>
                <a href="{{ $link }}" target="_blank" rel="noopener" class="underline break-all" data-kfp-url>{{ $link }}</a></p>
        @else
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Nicht veröffentlicht. Der Link läuft bis zum Datum „Gültig bis“ (sonst 6 Monate); er zeigt nur Preise je Paket, keine Kalkulation.</p>
        @endif
    </x-fa::section>
</div>
