{{-- Spec 82 §8 / §13 B6 — Abos: Master vergibt/kündigt/zieht zurück, Mandant sieht „Meine Abos". Kündigen/Zurückziehen nur mit Vorschau + Bestätigung. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $datum = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') : '—';
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Abos" icon="heroicon-o-arrow-path-rounded-square" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        @if($istMaster)
            <x-fa::page-header title="Abos" subtitle="Sammlungen des Master-Teams an Mandanten vergeben. Mandanten sehen nur veröffentlichte Fassungen, nie den Arbeitsstand und nie Master-Preise." />
        @else
            <x-fa::page-header title="Meine Abos" subtitle="Rezepte, die {{ $oberteamName }} vom Master abonniert hat. Sie sind schreibgeschützt; Kosten und Verkaufspreise rechnet das System mit Ihren eigenen Preisen." />
        @endif

        @if($fehler)<x-fa::notice tone="crit" data-abos-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-abos-meldung>{{ $meldung }}</x-fa::notice>@endif

        @if($vorschau)
            <x-fa::section title="{{ $vorschau['titel'] }}" icon="heroicon-o-exclamation-triangle" data-abos-vorschau>
                @forelse($vorschau['mandanten'] as $m)
                    @php $v = $m['vorschau']; @endphp
                    <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2" data-abos-vorschau-mandant>
                        <div class="font-medium">{{ $m['name'] }}</div>
                        @if($v['genutzt'] === [] && $v['mitkopiert'] === [] && $v['verschwindet'] === [])
                            <p class="{{ $leise }}">Keine Wirkung: Die Rezepte bleiben über ein anderes Abo verfügbar.</p>
                        @endif
                        @if($v['genutzt'] !== [])
                            <div data-abos-vorschau-genutzt>
                                <x-fa::badge tone="warn">Genutzt, wird eigene Kopie</x-fa::badge>
                                <ul class="mt-1 text-[length:var(--fa-text-sm)]">
                                    @foreach($v['genutzt'] as $r)<li>{{ $r['name'] }} <span class="{{ $leise }}">({{ implode(', ', $r['wo']) }})</span></li>@endforeach
                                </ul>
                            </div>
                        @endif
                        @if($v['mitkopiert'] !== [])
                            <div data-abos-vorschau-mitkopiert>
                                <x-fa::badge tone="info">Unter-Rezepte, werden mitkopiert</x-fa::badge>
                                <ul class="mt-1 text-[length:var(--fa-text-sm)]">@foreach($v['mitkopiert'] as $r)<li>{{ $r['name'] }}</li>@endforeach</ul>
                            </div>
                        @endif
                        @if($v['verschwindet'] !== [])
                            <div data-abos-vorschau-verschwindet>
                                <x-fa::badge tone="crit">Ungenutzt, verschwindet</x-fa::badge>
                                <ul class="mt-1 text-[length:var(--fa-text-sm)]">@foreach($v['verschwindet'] as $r)<li>{{ $r['name'] }}</li>@endforeach</ul>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="{{ $leise }}">Kein Mandant hat diese Sammlung abonniert. Das Rezept verlässt nur die Sammlung.</p>
                @endforelse
                <p class="{{ $leise }} mt-2">Archivierte Ausgaben und angenommene oder abgelehnte Angebote behalten ihre Fassung.</p>
                <label class="mt-2 inline-flex items-center gap-2 text-[length:var(--fa-text-md)] cursor-pointer">
                    <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" wire:model.live="bestaetigt" data-abos-bestaetigung />
                    Ich habe die Vorschau gelesen.
                </label>
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="primary" wire:click="ausfuehren" :disabled="! $bestaetigt" data-abos-ausfuehren>{{ $vorschau['knopf'] }}</x-fa::button>
                </div>
            </x-fa::section>
        @endif

        @if($istMaster)
            @unless($darfSchreiben)
                <x-fa::notice tone="info" data-abos-nur-lesen>Abos vergeben, kündigen und zurückziehen darf nur die Rolle „Freigeben“. Sie sehen den Stand.</x-fa::notice>
            @endunless

            @if($darfSchreiben)
                <x-fa::section title="Abo vergeben" icon="heroicon-o-plus" description="Nur Mandanten mit gebuchtem Stamm-Paket können abonnieren." data-abos-vergeben>
                    <div class="flex flex-wrap items-end gap-2">
                        <x-fa::field label="Sammlung" for="abo-sammlung">
                            <x-fa::select id="abo-sammlung" size="sm" class="w-64 max-w-full" wire:model="neuSammlungId" placeholder="Sammlung wählen" data-abos-sammlung-wahl>
                                @foreach($sammlungen as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Mandant" for="abo-mandant">
                            <x-fa::select id="abo-mandant" size="sm" class="w-64 max-w-full" wire:model="neuMandantId" placeholder="Mandant wählen" data-abos-mandant-wahl>
                                @foreach($mandanten as $m)
                                    <option value="{{ $m['team_id'] }}" @disabled(! $m['gebucht'])>{{ $m['name'] }}{{ $m['gebucht'] ? '' : ' (kein Stamm-Paket)' }}</option>
                                @endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::button size="sm" variant="primary" icon="heroicon-o-check" wire:click="vergeben" data-abos-vergeben-knopf>Vergeben</x-fa::button>
                    </div>
                </x-fa::section>
            @endif

            <x-fa::section title="Vergebene Abos" icon="heroicon-o-users" data-abos-liste>
                @if($abos === [])
                    <x-fa::empty compact icon="heroicon-o-arrow-path-rounded-square" title="Noch keine Abos">Vergeben Sie eine Sammlung an einen Mandanten.</x-fa::empty>
                @else
                    <div class="overflow-x-auto -mx-4">
                    <table class="fa-table fa-table--compact">
                        <thead><tr><th>Mandant</th><th>Sammlung</th><th>Seit</th><th></th></tr></thead>
                        <tbody>
                            @foreach($abos as $a)
                                <tr wire:key="abo-{{ $a['id'] }}" data-abos-zeile="{{ $a['id'] }}">
                                    <td class="font-medium">{{ $a['mandant'] }}</td>
                                    <td>{{ $a['sammlung'] }}</td>
                                    <td class="whitespace-nowrap">{{ $datum($a['seit']) }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        @if($darfSchreiben)
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-x-mark" wire:click="kuendigenVorschau({{ $a['id'] }})" data-abos-kuendigen>Kündigen …</x-fa::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </x-fa::section>

            <x-fa::section title="Sammlungen und Fassungen" icon="heroicon-o-rectangle-stack" description="Mandanten sehen je Rezept die zuletzt veröffentlichte Fassung. Veröffentlichen geht im Rezept über das Menü „Veröffentlichen …“." data-abos-sammlungen>
                @forelse($sammlungen as $s)
                    <div class="flex flex-col gap-1" wire:key="sam-{{ $s['id'] }}" data-abos-sammlung="{{ $s['id'] }}">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $s['name'] }}</span>
                            <x-fa::badge tone="neutral">{{ $s['abonnenten'] }} Abo(s)</x-fa::badge>
                        </div>
                        @if($s['rezepte'] === [])
                            <p class="{{ $leise }}">Keine Rezepte in dieser Sammlung.</p>
                        @else
                            <div class="overflow-x-auto -mx-4">
                            <table class="fa-table fa-table--compact">
                                <thead><tr><th>Rezept</th><th>Fassung</th><th>Notiz</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($s['rezepte'] as $r)
                                        <tr wire:key="sam-{{ $s['id'] }}-r-{{ $r['id'] }}" data-abos-rezept="{{ $r['id'] }}">
                                            <td>{{ $r['name'] }}</td>
                                            <td class="whitespace-nowrap">
                                                @if($r['fassung'])
                                                    Fassung {{ $r['fassung']->fassung_nr }} · {{ $datum($r['fassung']->veroeffentlicht_at) }}
                                                @else
                                                    <x-fa::badge tone="warn" data-abos-unveroeffentlicht>Nicht veröffentlicht</x-fa::badge>
                                                @endif
                                            </td>
                                            <td class="{{ $leise }}">{{ $r['fassung']?->aenderungsnotiz }}</td>
                                            <td class="text-right whitespace-nowrap">
                                                @if($darfSchreiben)
                                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-uturn-left" wire:click="zurueckziehenVorschau({{ $s['id'] }}, {{ $r['id'] }})" data-abos-zurueckziehen>Zurückziehen …</x-fa::button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @endif
                    </div>
                @empty
                    <x-fa::empty compact icon="heroicon-o-rectangle-stack" title="Keine Sammlungen">Sammlungen legen Sie unter „Teams“ an.</x-fa::empty>
                @endforelse
            </x-fa::section>
        @else
            @unless($istOberteam)
                <x-fa::notice tone="info" data-abos-unterteam>Die Abos gehören zu {{ $oberteamName }}. Kündigen kann nur ein Admin dort.</x-fa::notice>
            @endunless

            @forelse($abos as $a)
                <x-fa::section title="{{ $a['sammlung'] }}" icon="heroicon-o-arrow-path-rounded-square" description="Abonniert seit {{ $datum($a['seit']) }}" data-abos-mein-abo="{{ $a['id'] }}">
                    @if(($rezepte[$a['id']] ?? []) === [])
                        <p class="{{ $leise }}">Noch keine veröffentlichten Rezepte.</p>
                    @else
                        <div class="overflow-x-auto -mx-4">
                        <table class="fa-table fa-table--compact">
                            <thead><tr><th>Rezept</th><th>Fassung</th><th>Änderung</th></tr></thead>
                            <tbody>
                                @foreach($rezepte[$a['id']] as $f)
                                    <tr wire:key="mein-{{ $a['id'] }}-{{ $f->id }}">
                                        <td>{{ $f->name }}</td>
                                        <td class="whitespace-nowrap">Fassung {{ $f->fassung_nr }} · {{ $datum($f->veroeffentlicht_at) }}</td>
                                        <td>
                                            <span class="{{ $leise }}">{{ $f->aenderungsnotiz }}</span>
                                            @if($f->fassung_allergen_geaendert)<x-fa::badge tone="warn" data-abos-allergen-hinweis>Allergene geändert</x-fa::badge>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                    @if($darfKuendigen)
                        <div class="pt-2">
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-x-mark" wire:click="kuendigenVorschau({{ $a['id'] }})" data-abos-kuendigen>Abo kündigen …</x-fa::button>
                        </div>
                    @endif
                </x-fa::section>
            @empty
                <x-fa::empty icon="heroicon-o-arrow-path-rounded-square" title="Keine Abos">{{ $oberteamName }} hat keine Sammlung des Masters abonniert.</x-fa::empty>
            @endforelse
        @endif
    </x-ui-page-container>
</x-ui-page>
