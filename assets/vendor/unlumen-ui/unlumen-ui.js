/**
 * Unlumen UI — Interactive Mouse Tracking Engine
 * Package: assets/vendor/unlumen-ui/
 */

(function(window) {
  'use strict';

  const UnlumenUI = {
    /**
     * Mouse tracking for Spotlight glow elements
     */
    initSpotlight: function(selector = '.unlumen-spotlight-card, .spotlight-card') {
      const cards = document.querySelectorAll(selector);
      if (!cards.length) return;

      document.addEventListener('mousemove', (e) => {
        cards.forEach((card) => {
          const rect = card.getBoundingClientRect();
          const x = e.clientX - rect.left;
          const y = e.clientY - rect.top;
          card.style.setProperty('--unlumen-x', `${x}px`);
          card.style.setProperty('--unlumen-y', `${y}px`);
          card.style.setProperty('--mouse-x', `${x}px`);
          card.style.setProperty('--mouse-y', `${y}px`);
        });
      });
    }
  };

  window.UnlumenUI = UnlumenUI;

  document.addEventListener('DOMContentLoaded', () => {
    UnlumenUI.initSpotlight();
  });
})(window);
