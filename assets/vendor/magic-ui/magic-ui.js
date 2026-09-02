/**
 * Magic UI — Interactive Component Scripts
 * Package: assets/vendor/magic-ui/
 */

(function(window) {
  'use strict';

  const MagicUI = {
    /**
     * Initializes all Number Tickers in the DOM ([data-magic-ticker="42"])
     */
    initNumberTickers: function() {
      const tickers = document.querySelectorAll('[data-magic-ticker], [data-ticker]');
      if (!tickers.length) return;

      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const el = entry.target;
            const targetVal = parseFloat(el.getAttribute('data-magic-ticker') || el.getAttribute('data-ticker')) || 0;
            const duration = parseInt(el.getAttribute('data-duration')) || 1400;
            const prefix = el.getAttribute('data-prefix') || '';
            const suffix = el.getAttribute('data-suffix') || '';

            MagicUI.animateValue(el, 0, targetVal, duration, prefix, suffix);
            observer.unobserve(el);
          }
        });
      }, { threshold: 0.2 });

      tickers.forEach((t) => observer.observe(t));
    },

    /**
     * Animate a value from start to end with cubic ease-out
     */
    animateValue: function(obj, start, end, duration, prefix = '', suffix = '') {
      let startTimestamp = null;
      const isInt = Number.isInteger(end);

      const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        const ease = 1 - Math.pow(1 - progress, 3);
        const val = start + (end - start) * ease;
        obj.innerHTML = prefix + (isInt ? Math.floor(val) : val.toFixed(1)) + suffix;
        if (progress < 1) {
          window.requestAnimationFrame(step);
        } else {
          obj.innerHTML = prefix + end + suffix;
        }
      };

      window.requestAnimationFrame(step);
    },

    /**
     * Confetti Celebrations wrapper
     */
    confetti: function(preset = 'cannon') {
      if (typeof confetti !== 'function') return;

      if (preset === 'cannon') {
        confetti({
          particleCount: 120,
          spread: 80,
          origin: { y: 0.6 },
          colors: ['#c9a84c', '#0f2044', '#3b82f6', '#10b981', '#ffffff']
        });
      } else if (preset === 'fireworks') {
        const duration = 3.5 * 1000;
        const end = Date.now() + duration;
        const interval = setInterval(function() {
          if (Date.now() > end) return clearInterval(interval);
          confetti({
            startVelocity: 30,
            spread: 360,
            ticks: 60,
            origin: { x: Math.random() * 0.4 + 0.1, y: Math.random() - 0.2 },
            colors: ['#c9a84c', '#3b82f6', '#ffffff']
          });
          confetti({
            startVelocity: 30,
            spread: 360,
            ticks: 60,
            origin: { x: Math.random() * 0.4 + 0.5, y: Math.random() - 0.2 },
            colors: ['#ef4444', '#c9a84c', '#10b981']
          });
        }, 300);
      }
    }
  };

  window.MagicUI = MagicUI;

  document.addEventListener('DOMContentLoaded', () => {
    MagicUI.initNumberTickers();
  });
})(window);
