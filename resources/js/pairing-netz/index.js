/**
 * Platform FoodAlchemist Pairing-Netz — IIFE Entry
 *
 * Exportiert auf window.PlatformFoodAlchemistPairingNetz und registriert die
 * Alpine-Komponente automatisch (Muster: platforms-core/resources/js/workshop).
 */
import { pairingNetzGraph } from './graph.js';

function autoRegister() {
  const Alpine = window.Alpine;
  if (!Alpine) return;
  Alpine.data('pairingNetzGraph', pairingNetzGraph);
}

if (typeof document !== 'undefined') {
  document.addEventListener('livewire:init', autoRegister);
  if (document.readyState !== 'loading') {
    setTimeout(autoRegister, 0);
  } else {
    document.addEventListener('DOMContentLoaded', autoRegister);
  }
}

export { pairingNetzGraph };
