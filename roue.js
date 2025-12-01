// === 🎊 Confettis Vanilla ===
(function () {
  const TWO_PI = Math.PI * 2;
  let ctx,
    canvas,
    W,
    H,
    rafId = null;
  function resize() {
    canvas.width = W = window.innerWidth;
    canvas.height = H = window.innerHeight;
  }
  function rand(a, b) {
    return Math.random() * (b - a) + a;
  }
  function makeParticle() {
    const size = rand(6, 12),
      hue = [45, 47, 50, 52][Math.floor(rand(0, 4))];
    return {
      x: rand(W * 0.2, W * 0.8),
      y: -20,
      vx: rand(-3, 3),
      vy: rand(3, 7),
      g: 0.12,
      drag: 0.995,
      size,
      rot: rand(0, TWO_PI),
      vr: rand(-0.2, 0.2),
      hue,
      sat: 100,
      light: rand(45, 65),
      life: rand(60, 110),
    };
  }
  let particles = [];
  function step() {
    ctx.clearRect(0, 0, W, H);
    particles.forEach((p) => {
      p.vx *= p.drag;
      p.vy = p.vy * p.drag + p.g;
      p.x += p.vx;
      p.y += p.vy;
      p.rot += p.vr;
      p.life--;
      const w = p.size,
        h = p.size * (0.6 + 0.4 * Math.sin(p.rot * 2));
      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot * 0.2);
      ctx.fillStyle = `hsl(${p.hue} ${p.sat}% ${p.light}%)`;
      ctx.fillRect(-w / 2, -h / 2, w, h);
      ctx.restore();
    });
    particles = particles.filter((p) => p.life > 0 && p.y < H + 30);
    if (!particles.length) {
      cancelAnimationFrame(rafId);
      rafId = null;
      return;
    }
    rafId = requestAnimationFrame(step);
  }
  function burst(n = 180) {
    for (let i = 0; i < n; i++) particles.push(makeParticle());
    if (!rafId) rafId = requestAnimationFrame(step);
  }
  window.fireConfetti = function () {
    if (!canvas) {
      canvas = document.getElementById("confetti");
      if (!canvas) return;
      ctx = canvas.getContext("2d");
      resize();
      window.addEventListener("resize", resize);
    }
    burst(180);
    setTimeout(() => burst(120), 250);
  };
})();

// === Protection singleton ===
if (window.__ROUE_APP_LOADED__) {
  console.warn("[roue] déjà chargé");
} else {
  window.__ROUE_APP_LOADED__ = true;

  // --- Mémorise l’email d’arrivée (paramètre ?e=)
  (function () {
    try {
      const u = new URL(window.location.href);
      const e = u.searchParams.get("e");
      if (e) sessionStorage.setItem("avc_email", e.trim());
    } catch (_) {}
  })();

  // === 🔐 Verrou localStorage par campagne + email ===
  const CAMPAIGN_ID = "Grand public";
  const lockKey = (email) => `avc_played_${CAMPAIGN_ID}_${email}`;
  const dataKey = (email) => `${lockKey(email)}_data`;

  function hasPlayed(email) {
    try {
      return localStorage.getItem(lockKey(email)) === "1";
    } catch (_) {
      return false;
    }
  }
  function markPlayed(email, payload) {
    try {
      localStorage.setItem(lockKey(email), "1");
      localStorage.setItem(dataKey(email), JSON.stringify(payload || {}));
    } catch (_) {}
  }
  function readPlayed(email) {
    try {
      return JSON.parse(localStorage.getItem(dataKey(email)) || "null");
    } catch (_) {
      return null;
    }
  }

  // ====== ⚖️ Réglage des probabilités ======
  // Objectif : "Pas de cadeau" tombe le plus souvent (perdant).
  // Exemple : 65% consolation / 35% gagnants (répartis à parts égales).
  const PROBA_CONSOLATION = 0.9; // ajustable rapidement
  const JITTER_DEG = 3; // léger décalage pour varier l’arrêt visuel

  // Utilitaire : tirage d’index selon des poids
  function weightedIndex(weights) {
    const s = weights.reduce((a, b) => a + b, 0);
    let r = Math.random() * s;
    for (let i = 0; i < weights.length; i++) {
      if ((r -= weights[i]) <= 0) return i;
    }
    return weights.length - 1;
  }

  // === 🎡 Application Vue ===
  new Vue({
    el: "#app",
    data() {
      return {
        prizes: [
          { text: "Echarpe de Supporter", win: true, isConsolation: false },
          {
            text: "Ballon Coupe du Monde 2026",
            win: true,
            isConsolation: false,
          },
          { text: "Cape de supporter", win: true, isConsolation: false },
          {
            text: "Pas de cadeau",
            win: false,
            isConsolation: true,
          },
        ],
        weights: [], // calculées au mounted()
        r: 0,
        isShowResult: false,
        isSpinning: false,
        hideButton: false,
        hasSaved: false,
        spinSeq: 0,
        savedPrizeText: null,
        savedWin: null,
        alreadySpunServer: false,
      };
    },
    computed: {
      length() {
        return this.prizes.length;
      },
      awardIdx() {
        const frac = this.r - Math.floor(this.r);
        return Math.floor(frac * this.length) % this.length;
      },
      selectedPrize() {
        return this.prizes[this.awardIdx] || null;
      },
      isWin() {
        // si déjà sauvegardé (rechargé/retour), on respecte l'état stocké
        if (this.savedWin !== null) return !!this.savedWin;
        return this.selectedPrize ? !!this.selectedPrize.win : false;
      },
      statusLabel() {
        return this.isWin
          ? "⭐ C’est gagné ! ⭐"
          : "Merci pour votre participation";
      },
      result() {
        if (this.savedPrizeText) return this.savedPrizeText;
        if (this.length === 0) return null;
        const v = this.awardIdx;
        return this.prizes[v].text;
      },
    },
    methods: {
      getEmail() {
        try {
          const u = new URL(window.location.href);
          const e = u.searchParams.get("e");
          if (e) return e.trim();
          const s = sessionStorage.getItem("avc_email");
          if (s) return s;
        } catch (_) {}
        return "";
      },

      attachTransitionOnce(seq) {
        const wheel = this.$refs.roulette;
        if (!wheel) return;
        const onEnd = (ev) => {
          if (ev.target !== wheel) return;
          if (ev.propertyName && ev.propertyName !== "transform") return;
          if (seq !== this.spinSeq) return;

          wheel.classList.remove("turning");
          this.isSpinning = false;
          setTimeout(() => {
            this.isShowResult = true;
            if (this.isWin) window.fireConfetti && fireConfetti();
            this.sendResult();
          }, 250);
        };
        wheel.addEventListener("transitionend", onEnd, {
          passive: true,
          once: true,
        });
      },

      // Tire un index pondéré avec PROBA_CONSOLATION majoritaire
      pickWeightedIndex() {
        const idxConso = this.prizes.findIndex((p) => p.isConsolation);
        if (idxConso < 0) return 0;

        // Porte : on décide d'abord si consolation ou non
        if (Math.random() < PROBA_CONSOLATION) {
          this.weights = this.prizes.map((_, i) => (i === idxConso ? 1 : 0));
          return idxConso;
        }

        // Sinon, on répartit équitablement entre les gagnants
        const winnerIdxs = this.prizes
          .map((p, i) => ({ p, i }))
          .filter((x) => !x.p.isConsolation)
          .map((x) => x.i);

        const k = winnerIdxs.length;
        if (k === 0) {
          this.weights = [];
          return idxConso;
        }

        const pick = Math.floor(Math.random() * k);
        this.weights = this.prizes.map((_, i) =>
          winnerIdxs.includes(i) ? 1 / k : 0
        );
        return winnerIdxs[pick];
      },

      // Force la roue à s’arrêter sur un index cible (avec léger jitter)
      setRToIndex(idx) {
        const turns = 12; // nbr de tours visuels
        const base = Math.floor(Math.random() * 2) + 6; // 6 à 7 tours pour varier
        const slice = 1 / this.length;
        const jitter = (Math.random() - 0.5) * (JITTER_DEG / 360); // petit décalage
        this.r = base + idx * slice + slice / 2 + jitter;
      },

      async sendResult() {
        if (this.hasSaved) return;
        this.hasSaved = true;
        const email = this.getEmail();
        if (!email) return alert("Email manquant");

        const frac = this.r - Math.floor(this.r);
        const prize_idx = Math.floor(frac * this.length) % this.length;
        const prize_text = this.prizes[prize_idx].text;
        const win = this.prizes[prize_idx].win ? 1 : 0;

        const payload = {
          email,
          campaign_id: CAMPAIGN_ID,
          prize_idx,
          prize_text,
          win,
          r_value: this.r,
          prizes_version: "v2-weighted",
        };

        markPlayed(email, payload);
        this.savedPrizeText = prize_text;
        this.savedWin = !!win;

        try {
          const res = await fetch("spin.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
          });

          if ([200, 208].includes(res.status)) {
            const data = await res.json();
            // on respecte le verdict serveur
            this.savedPrizeText = data.final_prize_text || prize_text;
            this.savedWin = !!data.final_win;

            const clean = {
              ...payload,
              prize_text: this.savedPrizeText,
              win: this.savedWin ? 1 : 0,
              final_prize_text: this.savedPrizeText,
              final_win: this.savedWin ? 1 : 0,
            };
            markPlayed(email, clean);

            this.hideButton = true;
          } else if (res.status === 409) {
            this.hideButton = true;
          }
        } catch (_) {}
      },

      turning() {
        const email = this.getEmail();
        if (!email) return alert("Email requis pour jouer.");

        // 🔐 Blocage serveur prioritaire
        if (this.alreadySpunServer) {
          this.hideButton = true;
          this.isShowResult = true;
          return alert("Un tirage est déjà enregistré pour cet email.");
        }

        // Ensuite seulement, on regarde le localStorage
        if (hasPlayed(email)) {
          const d = readPlayed(email);
          if (d) {
            this.r = d.r_value || 0.12;
            this.savedPrizeText = d.final_prize_text || d.prize_text || null;
            this.savedWin = d.final_win ?? d.win ? true : false;
            this.isShowResult = true;
          }
          this.hideButton = true;
          return alert("Un tirage est déjà enregistré pour cet email.");
        }

        if (this.isSpinning) return;
        this.isSpinning = true;
        this.hideButton = true;
        this.isShowResult = false;
        this.hasSaved = false;
        this.savedPrizeText = null;
        this.savedWin = null;

        const beforeUnload = (e) => {
          if (this.isSpinning) {
            e.preventDefault();
            e.returnValue = "";
            return "";
          }
        };
        window.addEventListener("beforeunload", beforeUnload);

        // Tirage pondéré → on cible directement la tranche
        const idx = this.pickWeightedIndex();
        this.setRToIndex(idx);

        const spinMs = 6000;
        this.spinSeq++;
        this.attachTransitionOnce(this.spinSeq);
        const wheel = this.$refs.roulette;
        wheel.style.transition = `transform ${spinMs}ms cubic-bezier(0.15, 0.85, 0.10, 1)`;
        wheel.style.transform = `rotate(${this.r}turn)`;
        wheel.classList.add("turning");

        setTimeout(
          () => window.removeEventListener("beforeunload", beforeUnload),
          spinMs + 500
        );
      },
    },

    mounted() {
      const email = this.getEmail();
      if (!email) return;

      // 1️⃣ Check serveur
      fetch("status.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, campaign_id: CAMPAIGN_ID }),
      })
        .then((res) => {
          if (!res.ok) throw new Error("status error");
          return res.json();
        })
        .then((data) => {
          if (data.already_spun) {
            this.alreadySpunServer = true;
            this.hideButton = true;
            this.isShowResult = true;
            this.r = data.r_value || 0.12;
            this.savedPrizeText = data.final_prize_text || null;
            this.savedWin = data.final_win ? true : false;
            return; // on ne regarde même pas le localStorage
          }

          // 2️⃣ Pas encore joué côté serveur → on peut utiliser le localStorage
          if (hasPlayed(email)) {
            this.hideButton = true;
            this.isShowResult = true;
            const d = readPlayed(email);
            if (d) {
              this.r = d.r_value || 0.12;
              this.savedPrizeText = d.final_prize_text || d.prize_text || null;
              this.savedWin = d.final_win ?? d.win ? true : false;
            }
          } else {
            // pas joué nulle part → bouton dispo
            this.hideButton = false;
          }
        })
        .catch(() => {
          // en cas de souci serveur, on retombe sur ton comportement actuel
          if (email && hasPlayed(email)) {
            this.hideButton = true;
            this.isShowResult = true;
            const d = readPlayed(email);
            if (d) {
              this.r = d.r_value || 0.12;
              this.savedPrizeText = d.final_prize_text || d.prize_text || null;
              this.savedWin = d.final_win ?? d.win ? true : false;
            }
          }
        });

      // initialisation des poids
      this.pickWeightedIndex();
    },
  });
}
