/**
 * TKMI Badminton — Sponsor Ticker
 * Smoothly scrolls sponsor logos in an infinite loop.
 * Pauses on hover. Dynamically adjusts speed based on content width.
 */
(function () {
  const track = document.getElementById('sponsorTrack');
  if (!track) return;

  // Pause on hover
  track.addEventListener('mouseenter', () => {
    track.style.animationPlayState = 'paused';
  });
  track.addEventListener('mouseleave', () => {
    track.style.animationPlayState = 'running';
  });

  // Adjust animation duration based on number of sponsors
  // (more sponsors = longer duration = same apparent speed)
  const items = track.querySelectorAll(':scope > div');
  const uniqueCount = Math.ceil(items.length / 2); // Half are duplicates
  const baseDuration = 8;  // seconds per sponsor logo
  const duration = Math.max(20, uniqueCount * baseDuration);
  track.style.animationDuration = duration + 's';
})();
