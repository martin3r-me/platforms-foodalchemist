{{-- Speisekarte-Editor (Stufe A) — Browser links, Karten-Editor rechts (Rubrik-Baum,
     Gericht-Picker, Live-Preis). Dritte Ausgabeform neben Foodbook + Speiseplan. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'veroeffentlicht' => 'Veröffentlicht', 'archiviert' => 'Archiviert'])
@php($statusVariant = ['entwurf' => 'secondary', 'aktiv' => 'success', 'veroeffentlicht' => 'primary', 'archiviert' => 'secondary'])
@php($typLabel = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte', 'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Speisekarte" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Speisekarte'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Speisekarten" width="w-72">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Karte suchen …" class="{{ $input }}" />
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center" data-sk-neu>+ Neue Karte</button>
                <div class="mt-2 space-y-1">
                    @forelse($karten as $k)
                        <button type="button" wire:key="sk-{{ $k->id }}" wire:click="waehle({{ $k->id }})" data-sk-zeile="{{ $k->id }}"
                            class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition-all {{ $karteId === $k->id ? 'bg-violet-500/10 text-violet-700' : 'hover:bg-black/[0.03] text-gray-700' }}">
                            <div class="font-medium truncate">{{ $k->name }}</div>
                            <div class="text-[10px] text-gray-500">{{ $typLabel[$k->karten_typ] ?? $k->karten_typ }} · {{ $k->sections_count }} Rubriken</div>
                        </button>
                    @empty
                        <div class="px-2 py-6 text-center text-[11px] text-gray-500">Keine Karten. Oben „+ Neue Karte".</div>
                    @endforelse
                </div>
                <div class="pt-1">{{ $karten->links() }}</div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if(! $karte)
            <div class="relative overflow-hidden {{ $card }} p-10 text-center text-sm text-gray-500">
                <div class="{{ $cardAccent }}"></div>
                Wähle links eine Speisekarte oder lege eine neue an.
            </div>
        @else
            {{-- Karten-Kopf / Meta --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-head-{{ $karte->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <div class="md:col-span-2">
                        <div class="{{ $label }} mb-1">Name</div>
                        <input type="text" wire:model="name" class="{{ $input }}" />
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Kartentyp</div>
                        <select wire:model="kartenTyp" class="{{ $input }}">
                            @foreach($typLabel as $key => $lbl)
                                <option value="{{ $key }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Status</div>
                        <select wire:model="status" class="{{ $input }}">
                            @foreach($statusLabel as $key => $lbl)
                                <option value="{{ $key }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Gültig ab</div>
                        <input type="date" wire:model="gueltigVon" class="{{ $input }}" />
                    </div>
                    <div>
                        <div class="{{ $label }} mb-1">Gültig bis</div>
                        <input type="date" wire:model="gueltigBis" class="{{ $input }}" />
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-sk-speichern>Speichern</button>
                    <a href="{{ route('foodalchemist.speisekarte.dokument', $karte->id) }}" target="_blank" class="{{ $btnGhost }}">Dokument</a>
                    <a href="{{ route('foodalchemist.speisekarte.praesentation', $karte->id) }}" target="_blank" class="{{ $btnGhost }}">Präsentation</a>
                    <button type="button" wire:click="duplizieren" class="{{ $btnGhost }}">Duplizieren</button>
                    <span class="flex-1"></span>
                    <button type="button" wire:click="loeschen" wire:confirm="Diese Speisekarte wirklich löschen?" class="{{ $btnGhost }} text-red-600">Löschen</button>
                </div>
            </div>

            {{-- Branding / CI (Stufe C) --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-brand-{{ $karte->id }}" x-data="{ auf: false }">
                <div class="{{ $cardAccent }}"></div>
                <button type="button" @click="auf = !auf" class="flex items-center gap-2 w-full text-left">
                    <span class="font-semibold text-gray-900 text-sm">Branding / CI</span>
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">Design</span>
                    <span class="flex-1"></span>
                    <span class="text-xs text-gray-400" x-text="auf ? '▲' : '▼'"></span>
                </button>
                <div x-show="auf" x-cloak class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <div>
                            <div class="{{ $label }} mb-1">Markenfarbe</div>
                            <input type="color" wire:model="brandColor" class="h-8 w-16 rounded border border-black/10" />
                            <input type="text" wire:model="brandColor" class="{{ $input }} inline-block w-28 ml-2" />
                        </div>
                        <div>
                            <div class="{{ $label }} mb-1">Bandfarbe (optional)</div>
                            <input type="text" wire:model="bandColor" placeholder="leer = Markenfarbe" class="{{ $input }} w-40" />
                        </div>
                        <div>
                            <div class="{{ $label }} mb-1">Fußzeile</div>
                            <input type="text" wire:model="footerText" placeholder="z. B. Restaurant Adler · Musterstr. 1" class="{{ $input }}" />
                        </div>
                        @error('brandColor')<div class="text-[11px] text-red-500">{{ $message }}</div>@enderror
                        <button type="button" wire:click="brandingSpeichern" class="{{ $btnGhost }}">Branding speichern</button>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <div class="{{ $label }} mb-1">Logo</div>
                            @if($logoPath)
                                <div class="flex items-center gap-2">
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="Logo" class="h-8 rounded bg-white/60 p-1" />
                                    <button type="button" wire:click="brandingLogoEntfernen" class="{{ $btnGhostXs }} text-red-600">entfernen</button>
                                </div>
                            @endif
                            <input type="file" wire:model="logoUpload" accept="image/*" class="text-xs mt-1" />
                            <div wire:loading wire:target="logoUpload" class="text-[11px] text-gray-400">lädt …</div>
                        </div>
                        <div>
                            <div class="{{ $label }} mb-1">Titelbild</div>
                            @if($coverPath)
                                <div class="flex items-center gap-2">
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($coverPath) }}" alt="Cover" class="h-12 rounded" />
                                    <button type="button" wire:click="brandingCoverEntfernen" class="{{ $btnGhostXs }} text-red-600">entfernen</button>
                                </div>
                            @endif
                            <input type="file" wire:model="coverUpload" accept="image/*" class="text-xs mt-1" />
                            <div wire:loading wire:target="coverUpload" class="text-[11px] text-gray-400">lädt …</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Rubriken + Positionen --}}
            <div class="relative overflow-hidden {{ $card }} p-4" wire:key="sk-body-{{ $karte->id }}">
                <div class="{{ $cardAccent }}"></div>

                <div class="flex items-center gap-2 mb-3">
                    <input type="text" wire:model="neueRubrik" wire:keydown.enter="rubrikNeu" placeholder="Neue Rubrik (z. B. Vorspeisen) …" class="{{ $input }} max-w-xs" />
                    <button type="button" wire:click="rubrikNeu" class="{{ $btnGhost }}">+ Rubrik</button>
                </div>

                @forelse($karte->sections->whereNull('parent_id') as $rubrik)
                    @include('foodalchemist::livewire.speisekarte.partials.rubrik', ['rubrik' => $rubrik, 'depth' => 0])
                @empty
                    <div class="px-2 py-8 text-center text-[11px] text-gray-500">Noch keine Rubriken. Oben eine anlegen.</div>
                @endforelse
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
