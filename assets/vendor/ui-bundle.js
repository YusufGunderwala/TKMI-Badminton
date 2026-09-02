/**
 * TKMI Next-Gen UI Master Bundle Script
 * Bundles: Magic UI + Retro UI + Smooth UI + Unlumen UI
 */

(function(window) {
  'use strict';

  // Master Global UI Controller
  window.TKMI_UI = {
    version: '2.0.0',
    celebrate: function(type = 'fireworks') {
      if (window.MagicUI && typeof window.MagicUI.confetti === 'function') {
        window.MagicUI.confetti(type);
      }
    }
  };
})(window);
