/**
 * TKMI Badminton — Global JS Utilities
 */

// ---- Flash messages auto-dismiss ---------------------------
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-auto-dismiss]').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity 0.5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, parseInt(el.dataset.autoDismiss) || 4000);
  });
});

// ---- Confirm dialogs ---------------------------------------
function confirmAction(message = 'Are you sure?') {
  return window.confirm(message);
}

// ---- Copy to clipboard -------------------------------------
function copyToClipboard(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const original = btn.textContent;
    btn.textContent = 'Copied!';
    btn.classList.add('text-green-600');
    setTimeout(() => {
      btn.textContent = original;
      btn.classList.remove('text-green-600');
    }, 2000);
  });
}

// ---- Format score diff with +/- sign ----------------------
function formatDiff(diff) {
  if (diff > 0) return '+' + diff;
  return String(diff);
}
