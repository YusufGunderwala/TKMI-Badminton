/**
 * Smooth UI — Interactive Tabs, Modals & Fluid Physics
 * Package: assets/vendor/smooth-ui/
 */

(function(window) {
  'use strict';

  const SmoothUI = {
    /**
     * Smooth Sliding Pill Indicator for Tab Groups
     */
    initTabs: function() {
      const groups = document.querySelectorAll('.smooth-tabs-container');
      groups.forEach(group => {
        const tabs = group.querySelectorAll('.smooth-tab-item');
        tabs.forEach(tab => {
          tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
          });
        });
      });
    }
  };

  window.SmoothUI = SmoothUI;

  document.addEventListener('DOMContentLoaded', () => {
    SmoothUI.initTabs();
  });
})(window);
