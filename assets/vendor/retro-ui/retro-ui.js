/**
 * Retro UI — Retro Component Scripts & Interactive Grid Tilt
 * Package: assets/vendor/retro-ui/
 */

(function(window) {
  'use strict';

  const RetroUI = {
    /**
     * Interactive 3D Perspective Tilt on Mouse Movement
     */
    initPerspectiveTilt: function(selector = '.retro-tilt') {
      const elements = document.querySelectorAll(selector);
      if (!elements.length) return;

      elements.forEach(el => {
        el.addEventListener('mousemove', (e) => {
          const rect = el.getBoundingClientRect();
          const x = e.clientX - rect.left - rect.width / 2;
          const y = e.clientY - rect.top - rect.height / 2;
          const rotateX = -(y / (rect.height / 2)) * 10;
          const rotateY = (x / (rect.width / 2)) * 10;
          el.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
        });

        el.addEventListener('mouseleave', () => {
          el.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg)';
        });
      });
    }
  };

  window.RetroUI = RetroUI;

  document.addEventListener('DOMContentLoaded', () => {
    RetroUI.initPerspectiveTilt();
  });
})(window);
