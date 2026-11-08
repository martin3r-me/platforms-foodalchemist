{{-- Spec 94 · Konfigurator-Editor, Reiter Branding und Präsentation — aufgebaut wie im Foodbook: „Präsentation für den Kunden“
     (Design, Gültig bis als Pflicht, eigener Link-Name, Vorschau, Veröffentlichen, Link) und Branding (Logo/Titelbild über
     x-foodalchemist::branding-bilder, Farben, Übernehmen). Kein Kontakt-Knopf: die Anfrage im Konfigurator ersetzt ihn. --}}
@php
    $gesperrt = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $hinweisKlasse = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
<div class="flex flex-col gap-4" data-kf-praesentation>
    @if($fehler)<x-fa::notice tone="crit" data-kfp-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($hinweis)<x-fa::notice tone="ok">{{ $hinweis }}</x-fa::notice>@endif

    <x-fa::section title="Präsentation für den Kunden" icon="heroicon-o-presentation-chart-bar" description="Ein Link zum Konfigurator. Beim Veröffentlichen wird der Stand eingefroren; er zeigt nur Preise je Paket, keine Kalkulation." data-kfp-link>
        <x-slot:actions>
            <x-fa::button variant="ghost" size="sm" :href="route('foodalchemist.einstellungen', ['sektion' => 'praesentations-designs'])" target="_blank" icon-right="heroicon-m-arrow-top-right-on-square">Designs gestalten</x-fa::button>
        </x-slot:actions>

        <fieldset @disabled($gesperrt) class="contents">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-fa::field label="Design" for="kfp-design"><x-fa::select id="kfp-design" wire:model="branding.presentation_design" :options="$designOptionen" /></x-fa::field>
                <x-fa::field label="Gültig bis" for="kfp-gueltig" required>
                    <x-fa::input id="kfp-gueltig" type="date" wire:model.live="gueltigBis" data-kfp-gueltig />
                </x-fa::field>
            </div>

            <x-fa::field label="Eigener Link-Name" for="kfp-slug" optional>
                <x-fa::input id="kfp-slug" wire:model.live.debounce.400ms="slug" placeholder="z. B. diva-licious-2026" data-kfp-slug />
                <p class="{{ $hinweisKlasse }}">
                    Kundenlink:
                    <span class="font-mono break-all text-[var(--fa-ink-2)]">{{ url('/p/konfigurator') }}/{{ trim($slug) !== '' ? \Illuminate\Support\Str::slug($slug) : '(automatischer Code)' }}</span>.
                    Gilt nach dem Veröffentlichen. Leer lassen ergibt einen zufälligen Code.
                </p>
            </x-fa::field>
        </fieldset>

        @if($designGeaendert)
            <x-fa::notice tone="warn" title="Design nach der Veröffentlichung geändert">Der Kundenlink zeigt weiter den veröffentlichten Stand. Zum Übernehmen neu veröffentlichen.</x-fa::notice>
        @endif

        <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
            <fieldset @disabled($gesperrt) class="contents">
                @if($live)
                    <x-fa::button variant="danger" wire:click="zurueckziehen" wire:confirm="Link zurückziehen? Kunden sehen den Konfigurator dann nicht mehr." data-kfp-zurueckziehen>Veröffentlichung zurückziehen</x-fa::button>
                @endif
            </fieldset>
            <x-fa::button variant="ghost" icon="heroicon-m-eye" :href="route('foodalchemist.angebote.konfiguratoren.praesentation', $konfiguratorId)" target="_blank">Vorschau öffnen</x-fa::button>
            <fieldset @disabled($gesperrt) class="contents">
                <x-fa::button variant="primary" icon="heroicon-m-globe-alt" wire:click="veroeffentlichen"
                    wire:confirm="Diesen Stand als Kundenlink veröffentlichen? Der Stand wird eingefroren."
                    data-kfp-veroeffentlichen :disabled="$gueltigBis === ''">{{ $live ? 'Neu veröffentlichen' : 'Veröffentlichen' }}</x-fa::button>
            </fieldset>
        </div>
        @if($gueltigBis === '')
            <x-fa::signal tone="warn" class="self-end">Zum Veröffentlichen ein Datum bei „Gültig bis“ setzen.</x-fa::signal>
        @endif

        @if($link)
            <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3" x-data>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex-1 min-w-[12rem] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-2.5 py-1.5 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] break-all select-all" data-kfp-url>{{ $link }}</div>
                    <x-fa::button size="sm" icon="heroicon-m-link" href="#" x-on:click.prevent="navigator.clipboard.writeText('{{ $link }}'); $el.textContent='Kopiert'">Link kopieren</x-fa::button>
                </div>
                <p class="{{ $hinweisKlasse }} flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span>Freigegeben am {{ $k->presentation_published_at?->format('d.m.Y') ?? '–' }}, gültig bis {{ $k->presentation_expires_at?->format('d.m.Y') ?? '–' }}</span>
                    @if($live)<x-fa::badge tone="ok">Erreichbar</x-fa::badge>@else<x-fa::badge tone="warn">Nicht erreichbar oder abgelaufen</x-fa::badge>@endif
                </p>
            </div>
        @endif
    </x-fa::section>

    <fieldset @disabled($gesperrt) class="contents">
    <x-fa::section title="Branding" icon="heroicon-o-swatch" description="Logo und Titelbild erscheinen im Kopf des Kundenlinks, wenn das Design sie zeigt. Die Farben kommen aus dem Design, das gestaltest du unter „Designs gestalten“." data-kfp-branding>
        <x-foodalchemist::branding-bilder :logo-url="$logoUrl" :cover-url="$coverUrl" />

        <div class="flex flex-wrap items-end gap-3 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Betrieb" for="kfp-betrieb" optional hint="Ohne eigenes Logo gilt das Logo des Betriebs; es wird beim Veröffentlichen eingefroren.">
                <x-fa::select id="kfp-betrieb" wire:model="branding.outlet_id" :options="$betriebe" placeholder="kein Betrieb" class="w-72" data-kfp-betrieb />
            </x-fa::field>
            @if($betriebsLogo)
                <div class="flex items-center gap-2" data-kfp-logo-aus-betrieb>
                    <img src="{{ $betriebsLogo }}" alt="Logo des Betriebs" class="h-10 max-w-[120px] object-contain rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-1" />
                    <x-fa::badge>Logo aus Betrieb</x-fa::badge>
                </div>
            @endif
            <x-fa::button class="ml-auto" wire:click="brandingSpeichern" data-kfp-branding-speichern>Speichern</x-fa::button>
        </div>

        <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Branding übernehmen von" for="kfp-quelle"><x-fa::select id="kfp-quelle" wire:model.live="quelle" :options="['foodbook' => 'Foodbook', 'angebot' => 'Angebot', 'betrieb' => 'Betrieb (Logo, Design)']" /></x-fa::field>
            <x-fa::field label="Quelle" for="kfp-quelle-id"><x-fa::select id="kfp-quelle-id" wire:model="quelleId" :options="$quellen" placeholder="wählen" class="w-72" /></x-fa::field>
            <x-fa::button icon="heroicon-m-arrow-down-tray" wire:click="brandingUebernehmen" data-kfp-uebernehmen>Übernehmen</x-fa::button>
        </div>
        <p class="{{ $hinweisKlasse }}">Übernehmen kopiert die Werte einmalig, danach ist das Branding des Konfigurators unabhängig.</p>
    </x-fa::section>

    <x-fa::section title="Bilder der Zeitfenster" icon="heroicon-o-photo" description="Optional je Karte im Schritt „Wann startet euer Event?“. Ohne Bild bleibt die Karte wie bisher. Gilt ab dem nächsten Veröffentlichen." data-kfp-zeitbilder>
        @unless($k?->tageszeit_aktiv)<p class="{{ $hinweisKlasse }}">Der Schritt Zeitfenster ist bei diesem Konfigurator aus; die Bilder erscheinen erst, wenn er an ist.</p>@endunless
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach($zeitBilder as $t => $zb)
                <div class="flex flex-col gap-2" data-kfp-zeitbild="{{ $t }}">
                    <span class="text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink)]">{{ $zb['label'] }}</span>
                    @if($zb['url'])
                        <img src="{{ $zb['url'] }}" alt="Bild {{ $zb['label'] }}" class="w-full aspect-[4/3] object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                        <x-fa::button variant="ghost" size="sm" wire:click="zeitBildEntfernen('{{ $t }}')" data-kfp-zeitbild-entfernen="{{ $t }}">Entfernen</x-fa::button>
                    @else
                        <div class="w-full aspect-[4/3] rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line)] bg-[var(--fa-ground)] flex items-center justify-center {{ $hinweisKlasse }}">kein Bild</div>
                    @endif
                    <input type="file" accept="image/*" wire:model="{{ $zb['prop'] }}" class="block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer file:mr-2 file:h-7 file:px-2.5 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:text-[length:var(--fa-text-sm)] file:font-medium hover:file:bg-[var(--fa-hover)]" data-kfp-zeitbild-upload="{{ $t }}" aria-label="Bild {{ $zb['label'] }} hochladen">
                </div>
            @endforeach
        </div>
    </x-fa::section>
    </fieldset>
</div>
