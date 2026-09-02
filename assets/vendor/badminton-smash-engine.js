/**
 * TKMI Badminton Tournament - Hyper-Realistic 3D Stadium Smash Canvas Engine
 * High-FPS Ballistic Physics, Dynamic Racket Swings, Particle Shockwaves & Sparks
 */
class BadmintonSmashEngine {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) return;
        this.ctx = this.canvas.getContext('2d');
        this.isRunning = false;
        this.animId = null;
        
        // Resize canvas to match display
        this.width = this.canvas.width = this.canvas.offsetWidth * (window.devicePixelRatio || 1);
        this.height = this.canvas.height = this.canvas.offsetHeight * (window.devicePixelRatio || 1);
        
        // Physics & Animation State
        this.time = 0;
        this.particles = [];
        this.shockwaves = [];
        this.trails = [];
        this.shake = 0;
        
        // Court Dimensions in 3D Perspective
        this.court = {
            topY: this.height * 0.38,
            botY: this.height * 0.88,
            netX: this.width * 0.5,
            leftX: this.width * 0.1,
            rightX: this.width * 0.9,
            topWidth: this.width * 0.6,
            botWidth: this.width * 0.84
        };

        // Rackets
        this.leftRacket = {
            x: this.width * 0.22,
            y: this.height * 0.62,
            angle: -0.3,
            swinging: false,
            swingProgress: 0
        };

        this.rightRacket = {
            x: this.width * 0.78,
            y: this.height * 0.48,
            angle: 0.4,
            swinging: false,
            swingProgress: 0
        };

        // Ballistic Shuttlecock State
        // Phases: 0 = flying left-to-right (High Clear), 1 = flying right-to-left (SMASH)
        this.phase = 1; 
        this.shuttle = {
            x: this.width * 0.78,
            y: this.height * 0.48,
            vx: -12,
            vy: 8,
            angle: Math.PI,
            speed: 420, // km/h
            t: 0
        };

        this.lastSmashSpeed = 426;
        this.initEventListeners();
    }

    initEventListeners() {
        window.addEventListener('resize', () => {
            if (!this.canvas) return;
            this.width = this.canvas.width = this.canvas.offsetWidth * (window.devicePixelRatio || 1);
            this.height = this.canvas.height = this.canvas.offsetHeight * (window.devicePixelRatio || 1);
        });
    }

    start() {
        this.isRunning = true;
        this.time = 0;
        this.particles = [];
        this.shockwaves = [];
        this.trails = [];
        this.loop();
    }

    stop() {
        this.isRunning = false;
        if (this.animId) cancelAnimationFrame(this.animId);
    }

    createSparks(x, y, color, count = 25, isSmash = false) {
        for (let i = 0; i < count; i++) {
            const angle = Math.random() * Math.PI * 2;
            const speed = (Math.random() * (isSmash ? 14 : 7)) + 3;
            this.particles.push({
                x: x,
                y: y,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed,
                radius: Math.random() * 3 + 1.5,
                color: color || '#c9a84c',
                alpha: 1,
                decay: Math.random() * 0.04 + 0.02
            });
        }
    }

    createShockwave(x, y, color) {
        this.shockwaves.push({
            x: x,
            y: y,
            radius: 5,
            maxRadius: this.width * 0.18,
            alpha: 0.9,
            color: color || 'rgba(201, 168, 76, '
        });
    }

    update() {
        this.time += 0.016;

        // Decrease shake
        if (this.shake > 0) this.shake *= 0.88;
        if (this.shake < 0.1) this.shake = 0;

        // Update Shuttlecock Physics
        const dt = 0.022;
        this.shuttle.t += dt;

        if (this.phase === 0) {
            // High Defensive Lift / Clear (Left to Right)
            // Parabolic curve: start at left racket, fly high over net, land near right baseline
            const p0 = { x: this.width * 0.22, y: this.height * 0.62 };
            const p1 = { x: this.width * 0.45, y: this.height * 0.18 }; // apex high above net
            const p2 = { x: this.width * 0.78, y: this.height * 0.48 };
            
            const t = Math.min(this.shuttle.t * 1.1, 1);
            const prevX = this.shuttle.x;
            const prevY = this.shuttle.y;

            // Quadratic Bezier
            this.shuttle.x = (1 - t) * (1 - t) * p0.x + 2 * (1 - t) * t * p1.x + t * t * p2.x;
            this.shuttle.y = (1 - t) * (1 - t) * p0.y + 2 * (1 - t) * t * p1.y + t * t * p2.y;

            this.shuttle.angle = Math.atan2(this.shuttle.y - prevY, this.shuttle.x - prevX);

            // Trigger Right Racket Swing near apex arrival
            if (t > 0.75 && !this.rightRacket.swinging) {
                this.rightRacket.swinging = true;
                this.rightRacket.swingProgress = 0;
            }

            // Hit Right Racket -> Execute SMASH!
            if (t >= 1) {
                this.phase = 1;
                this.shuttle.t = 0;
                this.shake = 12; // Screen Shake
                this.lastSmashSpeed = Math.floor(Math.random() * 35) + 395;
                this.createSparks(this.shuttle.x, this.shuttle.y, '#ffd700', 40, true);
                this.createShockwave(this.shuttle.x, this.shuttle.y, 'rgba(255, 215, 0, ');
                this.createShockwave(this.shuttle.x, this.shuttle.y, 'rgba(59, 130, 246, ');
            }
        } else {
            // LIGHTNING DOWNHILL SMASH (Right to Left)
            // Bullet-fast laser trajectory steep downward
            const p0 = { x: this.width * 0.78, y: this.height * 0.48 };
            const p1 = { x: this.width * 0.48, y: this.height * 0.56 };
            const p2 = { x: this.width * 0.22, y: this.height * 0.62 };

            const t = Math.min(this.shuttle.t * 2.4, 1); // 2.4x speed
            const prevX = this.shuttle.x;
            const prevY = this.shuttle.y;

            this.shuttle.x = (1 - t) * (1 - t) * p0.x + 2 * (1 - t) * t * p1.x + t * t * p2.x;
            this.shuttle.y = (1 - t) * (1 - t) * p0.y + 2 * (1 - t) * t * p1.y + t * t * p2.y;

            this.shuttle.angle = Math.atan2(this.shuttle.y - prevY, this.shuttle.x - prevX);

            // Left Racket Defensive Reflex Swing
            if (t > 0.7 && !this.leftRacket.swinging) {
                this.leftRacket.swinging = true;
                this.leftRacket.swingProgress = 0;
            }

            // Hit Left Racket -> Return high clear
            if (t >= 1) {
                this.phase = 0;
                this.shuttle.t = 0;
                this.shake = 5;
                this.createSparks(this.shuttle.x, this.shuttle.y, '#38bdf8', 25, false);
                this.createShockwave(this.shuttle.x, this.shuttle.y, 'rgba(56, 189, 248, ');
            }
        }

        // Add Comet Trail
        this.trails.push({
            x: this.shuttle.x,
            y: this.shuttle.y,
            alpha: 1,
            color: this.phase === 1 ? '#c9a84c' : '#38bdf8'
        });

        // Update Trails
        for (let i = this.trails.length - 1; i >= 0; i--) {
            this.trails[i].alpha -= 0.055;
            if (this.trails[i].alpha <= 0) this.trails.splice(i, 1);
        }

        // Update Particles
        for (let i = this.particles.length - 1; i >= 0; i--) {
            const p = this.particles[i];
            p.x += p.vx;
            p.y += p.vy;
            p.vy += 0.22; // gravity
            p.vx *= 0.96;
            p.alpha -= p.decay;
            if (p.alpha <= 0) this.particles.splice(i, 1);
        }

        // Update Shockwaves
        for (let i = this.shockwaves.length - 1; i >= 0; i--) {
            const sw = this.shockwaves[i];
            sw.radius += 5;
            sw.alpha -= 0.035;
            if (sw.alpha <= 0 || sw.radius >= sw.maxRadius) this.shockwaves.splice(i, 1);
        }

        // Update Racket Swings
        if (this.leftRacket.swinging) {
            this.leftRacket.swingProgress += 0.14;
            this.leftRacket.angle = -0.3 + Math.sin(this.leftRacket.swingProgress * Math.PI) * 0.9;
            if (this.leftRacket.swingProgress >= 1) {
                this.leftRacket.swinging = false;
                this.leftRacket.angle = -0.3;
            }
        }

        if (this.rightRacket.swinging) {
            this.rightRacket.swingProgress += 0.18;
            this.rightRacket.angle = 0.4 - Math.sin(this.rightRacket.swingProgress * Math.PI) * 1.3;
            if (this.rightRacket.swingProgress >= 1) {
                this.rightRacket.swinging = false;
                this.rightRacket.angle = 0.4;
            }
        }
    }

    draw() {
        const ctx = this.ctx;
        ctx.save();

        // Screen Shake
        if (this.shake > 0) {
            const dx = (Math.random() - 0.5) * this.shake;
            const dy = (Math.random() - 0.5) * this.shake;
            ctx.translate(dx, dy);
        }

        // 1. Clear with gradient stadium glow
        ctx.clearRect(0, 0, this.width, this.height);

        // 2. Draw Stadium Lights / Volumetric Glow
        this.drawStadiumLights(ctx);

        // 3. Draw 3D Perspective Badminton Court
        this.drawCourt(ctx);

        // 4. Draw Shockwaves
        this.drawShockwaves(ctx);

        // 5. Draw Comet Trails
        this.drawTrails(ctx);

        // 6. Draw Rackets
        this.drawRacket(ctx, this.leftRacket.x, this.leftRacket.y, this.leftRacket.angle, '#38bdf8', false);
        this.drawRacket(ctx, this.rightRacket.x, this.rightRacket.y, this.rightRacket.angle, '#c9a84c', true);

        // 7. Draw 3D Aerodynamic Shuttlecock
        this.drawShuttlecock(ctx, this.shuttle.x, this.shuttle.y, this.shuttle.angle);

        // 8. Draw Particles / Sparks
        this.drawParticles(ctx);

        // 9. Draw HUD Telemetry & Speedometer
        this.drawHUD(ctx);

        ctx.restore();
    }

    drawStadiumLights(ctx) {
        // Top Left Floodlight Beam
        const g1 = ctx.createRadialGradient(this.width * 0.15, 0, 10, this.width * 0.25, this.height * 0.6, this.width * 0.5);
        g1.addColorStop(0, 'rgba(56, 189, 248, 0.15)');
        g1.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = g1;
        ctx.fillRect(0, 0, this.width, this.height);

        // Top Right Golden Smash Floodlight
        const g2 = ctx.createRadialGradient(this.width * 0.85, 0, 10, this.width * 0.75, this.height * 0.6, this.width * 0.5);
        g2.addColorStop(0, 'rgba(201, 168, 76, 0.2)');
        g2.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = g2;
        ctx.fillRect(0, 0, this.width, this.height);
    }

    drawCourt(ctx) {
        const c = this.court;
        const cx = this.width * 0.5;
        const cyTop = this.height * 0.42;
        const cyBot = this.height * 0.86;
        const topW = this.width * 0.65;
        const botW = this.width * 0.88;

        ctx.save();

        // Court Floor Surface (Deep Emerald/Navy Matte)
        ctx.beginPath();
        ctx.moveTo(cx - topW * 0.5, cyTop);
        ctx.lineTo(cx + topW * 0.5, cyTop);
        ctx.lineTo(cx + botW * 0.5, cyBot);
        ctx.lineTo(cx - botW * 0.5, cyBot);
        ctx.closePath();

        const courtGrad = ctx.createLinearGradient(0, cyTop, 0, cyBot);
        courtGrad.addColorStop(0, '#0a233f');
        courtGrad.addColorStop(1, '#07182c');
        ctx.fillStyle = courtGrad;
        ctx.fill();

        // Outer Boundary Neon Glow
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.45)';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Inner Badminton Lines (Center Service Line, Doubles Sidelines)
        ctx.strokeStyle = 'rgba(201, 168, 76, 0.35)';
        ctx.lineWidth = 1.5;

        // Center line
        ctx.beginPath();
        ctx.moveTo(cx, cyTop);
        ctx.lineTo(cx, cyBot);
        ctx.stroke();

        // 3D NET in the Center
        const netTopY = cyTop - 35;
        const netBotY = cyBot - 15;
        const netYMid = (cyTop + cyBot) * 0.5;
        const netW = (topW + botW) * 0.5 * 0.52;

        // Net Mesh Gradient
        ctx.fillStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.fillRect(cx - 3, netYMid - 45, 6, 65);

        // Net Top Tape Cord (Bright White-Gold)
        ctx.beginPath();
        ctx.moveTo(cx - netW * 0.5, netYMid - 32);
        ctx.lineTo(cx + netW * 0.5, netYMid - 32);
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 3;
        ctx.shadowColor = '#ffffff';
        ctx.shadowBlur = 8;
        ctx.stroke();
        ctx.shadowBlur = 0;

        // Net Posts
        ctx.fillStyle = '#c9a84c';
        ctx.fillRect(cx - netW * 0.5 - 2, netYMid - 36, 4, 45);
        ctx.fillRect(cx + netW * 0.5 - 2, netYMid - 36, 4, 45);

        ctx.restore();
    }

    drawRacket(ctx, x, y, angle, color, isRight) {
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(angle);

        // Racket Shaft & Handle
        ctx.strokeStyle = '#1e293b';
        ctx.lineWidth = 4;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(0, 45);
        ctx.lineTo(0, -5);
        ctx.stroke();

        // Handle Grip (TKMI Gold or Cyan Tape)
        ctx.strokeStyle = color;
        ctx.lineWidth = 5;
        ctx.beginPath();
        ctx.moveTo(0, 45);
        ctx.lineTo(0, 25);
        ctx.stroke();

        // Isometric Racket Head Frame (Oval)
        ctx.strokeStyle = color;
        ctx.lineWidth = 3;
        ctx.shadowColor = color;
        ctx.shadowBlur = 10;
        ctx.beginPath();
        ctx.ellipse(0, -32, 16, 25, 0, 0, Math.PI * 2);
        ctx.stroke();
        ctx.shadowBlur = 0;

        // String Mesh
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
        ctx.lineWidth = 1;
        for (let i = -10; i <= 10; i += 5) {
            ctx.beginPath();
            ctx.moveTo(i, -52);
            ctx.lineTo(i, -12);
            ctx.stroke();
        }
        for (let j = -48; j <= -16; j += 6) {
            ctx.beginPath();
            ctx.moveTo(-12, j);
            ctx.lineTo(12, j);
            ctx.stroke();
        }

        ctx.restore();
    }

    drawShuttlecock(ctx, x, y, angle) {
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(angle);

        // Shuttlecock Shadow on Court
        ctx.save();
        ctx.shadowColor = '#000000';
        ctx.shadowBlur = 15;
        ctx.restore();

        // 1. Feathers Skirt (Conical high-detail fan)
        const featherGrad = ctx.createLinearGradient(-18, 0, 10, 0);
        featherGrad.addColorStop(0, '#ffffff');
        featherGrad.addColorStop(0.8, '#e2e8f0');
        featherGrad.addColorStop(1, '#94a3b8');

        ctx.fillStyle = featherGrad;
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 1;

        ctx.beginPath();
        ctx.moveTo(-20, -11);
        ctx.lineTo(-20, 11);
        ctx.lineTo(4, 7);
        ctx.lineTo(4, -7);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        // Feather Ribs Lines
        ctx.strokeStyle = '#64748b';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(-20, -7);
        ctx.lineTo(4, -3);
        ctx.moveTo(-20, 0);
        ctx.lineTo(4, 0);
        ctx.moveTo(-20, 7);
        ctx.lineTo(4, 3);
        ctx.stroke();

        // Fastening Ribbon / Band (TKMI Gold or Bohra Red)
        ctx.fillStyle = '#ef4444';
        ctx.fillRect(-8, -8.5, 3, 17);

        // 2. Cork Base (Rounded Dome Head)
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = '#c9a84c';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(8, 0, 7.5, -Math.PI / 2, Math.PI / 2, false);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        // Cork Impact Glow
        if (this.phase === 1) {
            ctx.shadowColor = '#c9a84c';
            ctx.shadowBlur = 20;
            ctx.fillStyle = 'rgba(255, 215, 0, 0.4)';
            ctx.beginPath();
            ctx.arc(8, 0, 10, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.restore();
    }

    drawTrails(ctx) {
        for (let i = 0; i < this.trails.length; i++) {
            const tr = this.trails[i];
            ctx.save();
            ctx.globalAlpha = tr.alpha * 0.7;
            ctx.fillStyle = tr.color;
            ctx.shadowColor = tr.color;
            ctx.shadowBlur = 12;
            ctx.beginPath();
            ctx.arc(tr.x, tr.y, (i / this.trails.length) * 6 + 1.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }
    }

    drawParticles(ctx) {
        for (const p of this.particles) {
            ctx.save();
            ctx.globalAlpha = p.alpha;
            ctx.fillStyle = p.color;
            ctx.shadowColor = p.color;
            ctx.shadowBlur = 8;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }
    }

    drawShockwaves(ctx) {
        for (const sw of this.shockwaves) {
            ctx.save();
            ctx.strokeStyle = sw.color + sw.alpha + ')';
            ctx.lineWidth = 3;
            ctx.shadowColor = '#ffffff';
            ctx.shadowBlur = 15;
            ctx.beginPath();
            ctx.arc(sw.x, sw.y, sw.radius, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }
    }

    drawHUD(ctx) {
        ctx.save();
        
        // Speedometer Pill
        const speedX = this.width * 0.5;
        const speedY = this.height * 0.16;

        ctx.fillStyle = 'rgba(15, 32, 68, 0.8)';
        ctx.strokeStyle = 'rgba(201, 168, 76, 0.4)';
        ctx.lineWidth = 1.5;
        
        // Rounded telemetry pill
        const pillW = 160;
        const pillH = 34;
        ctx.beginPath();
        ctx.roundRect(speedX - pillW * 0.5, speedY - pillH * 0.5, pillW, pillH, 17);
        ctx.fill();
        ctx.stroke();

        // Speed Text
        ctx.fillStyle = '#c9a84c';
        ctx.font = 'bold 12px monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(`⚡ SMASH: ${this.lastSmashSpeed} KM/H`, speedX, speedY);

        ctx.restore();
    }

    loop() {
        if (!this.isRunning) return;
        this.update();
        this.draw();
        this.animId = requestAnimationFrame(() => this.loop());
    }
}
