/**
 * ============================================================
 * MAGIC UI + RETRO UI + SMOOTH UI + UNLUMEN JAVASCRIPT ENGINE
 * TKMI Badminton Tournament Platform
 * ============================================================
 */

document.addEventListener('DOMContentLoaded', () => {
  initSpotlightCards();
  initNumberTickers();
  initCourtParticles();
  initSmoothPillTabs();
});

/* --- 1. UNLUMEN / ACETERNITY: SPOTLIGHT CARD MOUSE TRACKER --- */
function initSpotlightCards() {
  const cards = document.querySelectorAll('.spotlight-card');
  if (!cards.length) return;

  document.addEventListener('mousemove', (e) => {
    cards.forEach((card) => {
      const rect = card.getBoundingClientRect();
      const x = e.clientX - rect.left;
      const y = e.clientY - rect.top;
      card.style.setProperty('--mouse-x', `${x}px`);
      card.style.setProperty('--mouse-y', `${y}px`);
    });
  });
}

/* --- 2. MAGIC UI: NUMBER TICKER / ANIMATED COUNTERS --- */
function initNumberTickers() {
  const tickers = document.querySelectorAll('[data-ticker]');
  if (!tickers.length) return;

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          const el = entry.target;
          const targetVal = parseFloat(el.getAttribute('data-ticker')) || 0;
          const duration = parseInt(el.getAttribute('data-duration')) || 1500;
          const prefix = el.getAttribute('data-prefix') || '';
          const suffix = el.getAttribute('data-suffix') || '';
          
          animateValue(el, 0, targetVal, duration, prefix, suffix);
          observer.unobserve(el);
        }
      });
    },
    { threshold: 0.2 }
  );

  tickers.forEach((t) => observer.observe(t));
}

function animateValue(obj, start, end, duration, prefix = '', suffix = '') {
  let startTimestamp = null;
  const isInt = Number.isInteger(end);

  const step = (timestamp) => {
    if (!startTimestamp) startTimestamp = timestamp;
    const progress = Math.min((timestamp - startTimestamp) / duration, 1);
    
    // Ease out cubic
    const easeProgress = 1 - Math.pow(1 - progress, 3);
    const currentVal = start + (end - start) * easeProgress;
    
    obj.innerHTML = prefix + (isInt ? Math.floor(currentVal) : currentVal.toFixed(1)) + suffix;
    
    if (progress < 1) {
      window.requestAnimationFrame(step);
    } else {
      obj.innerHTML = prefix + end + suffix;
    }
  };
  
  window.requestAnimationFrame(step);
}

/* --- 3. MAGIC UI: CONFETTI BURST (Championship & Match Win) --- */
function fireConfetti(type = 'cannon') {
  if (typeof confetti !== 'function') return;

  if (type === 'cannon') {
    confetti({
      particleCount: 100,
      spread: 70,
      origin: { y: 0.6 },
      colors: ['#c9a84c', '#0f2044', '#3b82f6', '#10b981', '#ffffff']
    });
  } else if (type === 'fireworks') {
    const duration = 3 * 1000;
    const animationEnd = Date.now() + duration;
    const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 };

    const interval = setInterval(function() {
      const timeLeft = animationEnd - Date.now();
      if (timeLeft <= 0) {
        return clearInterval(interval);
      }
      const particleCount = 50 * (timeLeft / duration);
      confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } });
      confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } });
    }, 250);
  } else if (type === 'stars') {
    confetti({
      shapes: ['star'],
      colors: ['#FFE87C', '#FFD700', '#FFA500'],
      particleCount: 80,
      spread: 100,
      origin: { y: 0.5 }
    });
  }
}

function randomInRange(min, max) {
  return Math.random() * (max - min) + min;
}

/* --- 4. RETRO UI / MAGIC UI: COURT PARTICLES AMBIENCE --- */
function initCourtParticles() {
  const canvas = document.getElementById('court-particles');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  let width = (canvas.width = canvas.offsetWidth);
  let height = (canvas.height = canvas.offsetHeight);

  window.addEventListener('resize', () => {
    if (!canvas) return;
    width = canvas.width = canvas.offsetWidth;
    height = canvas.height = canvas.offsetHeight;
  });

  const particles = [];
  const particleCount = Math.min(Math.floor(width / 20), 40);

  for (let i = 0; i < particleCount; i++) {
    particles.push({
      x: Math.random() * width,
      y: Math.random() * height,
      radius: Math.random() * 2 + 0.5,
      color: Math.random() > 0.5 ? 'rgba(201, 168, 76, ' : 'rgba(59, 130, 246, ',
      opacity: Math.random() * 0.4 + 0.1,
      speedX: (Math.random() - 0.5) * 0.4,
      speedY: (Math.random() - 0.5) * 0.4
    });
  }

  function render() {
    ctx.clearRect(0, 0, width, height);

    particles.forEach((p) => {
      p.x += p.speedX;
      p.y += p.speedY;

      if (p.x < 0) p.x = width;
      if (p.x > width) p.x = 0;
      if (p.y < 0) p.y = height;
      if (p.y > height) p.y = 0;

      ctx.beginPath();
      ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
      ctx.fillStyle = p.color + p.opacity + ')';
      ctx.fill();
    });

    requestAnimationFrame(render);
  }

  render();
}

/* --- 5. SMOOTH UI: PILL TABS WITH SLIDING INDICATOR --- */
function initSmoothPillTabs() {
  const containers = document.querySelectorAll('.smooth-tab-group');
  containers.forEach((group) => {
    const tabs = group.querySelectorAll('.smooth-tab');
    const indicator = group.querySelector('.smooth-tab-indicator');
    if (!tabs.length || !indicator) return;

    function updateIndicator(activeTab) {
      const rect = activeTab.getBoundingClientRect();
      const parentRect = group.getBoundingClientRect();
      indicator.style.left = `${rect.left - parentRect.left}px`;
      indicator.style.width = `${rect.width}px`;
      indicator.style.height = `${rect.height}px`;
    }

    const initial = group.querySelector('.smooth-tab.active') || tabs[0];
    if (initial) updateIndicator(initial);

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabs.forEach((t) => t.classList.remove('active'));
        tab.classList.add('active');
        updateIndicator(tab);
      });
    });
  });
}
