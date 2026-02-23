/* public/assets/js/produits.js */
console.log("✅ produits.js chargé (FULL stable + auto-carousel)");

// ==================================================
// ✅ HANDLERS ROBUSTES (mode sombre / contact / IA)
// - Event delegation => marche même si le DOM est remplacé (Turbo, Ajax, etc.)
// - Évite le bug: "déjà initialisé -> skip" puis plus aucun clic ne marche
// ==================================================
(function pharmacieDelegates() {
  if (window.__PHARMACIE_DELEGATES__) return;
  window.__PHARMACIE_DELEGATES__ = true;

  const THEME_KEY = "theme"; // localStorage
  let contactInFlight = false;
  let aiInFlight = false;

  function applySavedTheme() {
    const theme = localStorage.getItem(THEME_KEY) === "dark" ? "dark" : "light";
    document.body.classList.toggle("dark", theme === "dark");
  }

  function toggleTheme() {
    const next = document.body.classList.contains("dark") ? "light" : "dark";
    localStorage.setItem(THEME_KEY, next);
    applySavedTheme();
  }

  async function openContactQrModal() {
    if (contactInFlight) return;

    const modalEl = document.getElementById("contactQrModal");
    if (!modalEl) return;

    if (!window.bootstrap || !bootstrap.Modal) {
      console.error("❌ Bootstrap Modal non chargé");
      return;
    }

    contactInFlight = true;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const loading = document.getElementById("contactQrLoading");
    const content = document.getElementById("contactQrContent");
    const errorBox = document.getElementById("contactQrError");
    const errorMsg = document.getElementById("contactQrErrorMsg");
    const img = document.getElementById("contactQrImg");

    const showLoading = () => {
      loading?.classList.remove("d-none");
      content?.classList.add("d-none");
      errorBox?.classList.add("d-none");
    };
    const showError = (msg) => {
      loading?.classList.add("d-none");
      content?.classList.add("d-none");
      errorBox?.classList.remove("d-none");
      if (errorMsg) errorMsg.textContent = msg || "Erreur inconnue.";
    };
    const showContent = () => {
      loading?.classList.add("d-none");
      errorBox?.classList.add("d-none");
      content?.classList.remove("d-none");
    };

    try {
      showLoading();
      modal.show();

      const res = await fetch("/contact-pharmacie/qr.json", {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });

      const raw = await res.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error("Réponse non-JSON (redirect / erreur serveur)." );
      }

      if (!res.ok) throw new Error(data.error || `Erreur HTTP ${res.status}`);
      if (!data.ok || !data.qrPath) throw new Error(data.error || "qrPath manquant");

      const fullUrl = new URL(data.qrPath, window.location.origin).toString() + "?v=" + Date.now();
      if (!img) throw new Error("contactQrImg introuvable");

      img.onload = () => showContent();
      img.onerror = () => showError("Image QR non chargée (/qrcodes/... inaccessible)");
      img.src = fullUrl;
    } catch (err) {
      console.error(err);
      showError(err?.message || "Erreur");
    } finally {
      contactInFlight = false;
    }
  }

  function toggleAiPanel() {
    const panel = document.getElementById("aiPanel");
    const input = document.getElementById("aiInput");
    if (!panel) return;

    panel.classList.toggle("d-none");
    if (!panel.classList.contains("d-none")) {
      setTimeout(() => input && input.focus(), 50);
    }
  }

  function closeAiPanel() {
    const panel = document.getElementById("aiPanel");
    if (!panel) return;
    panel.classList.add("d-none");
  }

  function addAiMsg(text, who) {
    const body = document.getElementById("aiBody");
    if (!body) return;
    const div = document.createElement("div");
    div.className = "ai-msg " + (who === "user" ? "ai-user" : "ai-bot");
    div.textContent = text;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
  }

  async function askAi() {
    if (aiInFlight) return;

    const input = document.getElementById("aiInput");
    const send = document.getElementById("aiSend");
    if (!input) return;

    const message = (input.value || "").trim();
    if (!message) return;

    aiInFlight = true;
    input.value = "";
    addAiMsg(message, "user");

    if (send) send.disabled = true;
    input.disabled = true;

    try {
      const res = await fetch("/api/pharmacie/ai", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message }),
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data || !data.ok) {
        addAiMsg((data && data.reply) ? data.reply : "Erreur IA. Réessaie.", "bot");
      } else {
        addAiMsg(data.reply, "bot");
      }
    } catch (_) {
      addAiMsg("Erreur réseau. Vérifie ta connexion.", "bot");
    } finally {
      if (send) send.disabled = false;
      input.disabled = false;
      input.focus();
      aiInFlight = false;
    }
  }

  // Appliquer le thème dès que possible
  document.addEventListener("DOMContentLoaded", applySavedTheme);
  document.addEventListener("turbo:load", applySavedTheme);
  applySavedTheme();

  // Click delegation
  document.addEventListener("click", (e) => {
    const target = e.target && e.target.closest ? e.target.closest("#themeToggle, #btnContactQr, #aiFab, #aiClose, #aiSend") : null;
    if (!target) return;

    if (target.id === "themeToggle") {
      e.preventDefault();
      toggleTheme();
      return;
    }

    if (target.id === "btnContactQr") {
      e.preventDefault();
      openContactQrModal();
      return;
    }

    if (target.id === "aiFab") {
      e.preventDefault();
      toggleAiPanel();
      return;
    }

    if (target.id === "aiClose") {
      e.preventDefault();
      closeAiPanel();
      return;
    }

    if (target.id === "aiSend") {
      e.preventDefault();
      askAi();
    }
  });

  // Enter-to-send delegation
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Enter") return;
    const isAiInput = e.target && e.target.id === "aiInput";
    if (!isAiInput) return;
    e.preventDefault();
    askAi();
  });
})();

// ✅ anti double init
if (window.__PHARMACIE_INIT_DONE__) {
  console.warn("⚠️ produits.js déjà initialisé -> skip");
} else {
  window.__PHARMACIE_INIT_DONE__ = true;

  document.addEventListener("DOMContentLoaded", () => {
    // ==================================================
    // 1) FLASH AUTO-SUPPRESSION + FERMETURE
    // ==================================================
    setTimeout(() => {
      document.querySelectorAll(".alert-custom").forEach((alert) => {
        alert.style.animation = "slideOut 0.4s ease forwards";
        setTimeout(() => alert.remove(), 400);
      });
    }, 3000);

    document.addEventListener("click", (e) => {
      if (e.target && e.target.classList.contains("btn-close-custom")) {
        const parent = e.target.closest(".alert-custom");
        if (parent) parent.remove();
      }
    });

    // ==================================================
    // 2) PANIER AJAX (boutons grille produits)
    // ==================================================
    document.querySelectorAll(".btn-add-cart").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const id = btn.dataset.id;
        const stock = parseInt(btn.dataset.stock || "0", 10);

        btn.disabled = true;

        try {
          // vérifier quantité dans panier si endpoint existe
          const checkRes = await fetch(`/panier/verifier/${id}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
            cache: "no-store",
          });

          const checkData = await checkRes.json().catch(() => ({}));
          const quantiteDansPanier = parseInt(checkData.quantite || "0", 10);

          if (stock > 0 && quantiteDansPanier >= stock) {
            showFlashMessage(`Stock insuffisant ! Max ${stock}`, "error");
            btn.disabled = false;
            return;
          }

          const addRes = await fetch(`/panier/ajouter/${id}`, {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            cache: "no-store",
          });

          const addData = await addRes.json().catch(() => ({}));

          if (!addRes.ok || !addData.success) {
            showFlashMessage(addData.message || "Erreur ajout", "error");
            btn.disabled = false;
            return;
          }

          showFlashMessage(addData.message || "Ajouté au panier", "success");
          updateCartBadge(addData.count);
          btn.disabled = false;
        } catch (e) {
          console.error(e);
          showFlashMessage("Erreur réseau", "error");
          btn.disabled = false;
        }
      });
    });

    // ==================================================
    // 3) FILTRES + TRI
    // ==================================================
    const searchInput = document.getElementById("searchInput");
    const clearSearchBtn = document.getElementById("clearSearch");
    const sortPriceSelect = document.getElementById("sortPrice");
    const sortStockSelect = document.getElementById("sortStock");
    const filterCategorySelect = document.getElementById("filterCategory");
    const produitsContainer = document.getElementById("produitsContainer");
    const produitItems = Array.from(document.querySelectorAll(".produit-item"));
    const noResults = document.getElementById("noResults");
    const resultCount = document.getElementById("count");

    function filterAndSortProducts() {
      if (!searchInput || !filterCategorySelect || !produitsContainer) return;

      const searchTerm = (searchInput.value || "").toLowerCase().trim();
      const selectedCategory = (filterCategorySelect.value || "").toLowerCase().trim();
      const sortPriceOrder = sortPriceSelect ? sortPriceSelect.value : "";
      const sortStockOrder = sortStockSelect ? sortStockSelect.value : "";

      let visibleItems = [];

      produitItems.forEach((item) => {
        const nom = (item.dataset.nom || "").toLowerCase();
        const categorie = (item.dataset.categorie || "").toLowerCase();
        const description = (item.dataset.description || "").toLowerCase();

        const matchesSearch =
          !searchTerm ||
          nom.includes(searchTerm) ||
          categorie.includes(searchTerm) ||
          description.includes(searchTerm);

        const matchesCategory = !selectedCategory || categorie === selectedCategory;

        if (matchesSearch && matchesCategory) {
          item.style.display = "";
          visibleItems.push(item);
        } else {
          item.style.display = "none";
        }
      });

      if (sortPriceOrder) {
        visibleItems.sort((a, b) => {
          const pa = parseFloat(a.dataset.prix || "0");
          const pb = parseFloat(b.dataset.prix || "0");
          return sortPriceOrder === "asc" ? pa - pb : pb - pa;
        });
      }

      if (sortStockOrder) {
        visibleItems.sort((a, b) => {
          const sa = parseInt(a.dataset.stock || "0", 10);
          const sb = parseInt(b.dataset.stock || "0", 10);
          return sortStockOrder === "asc" ? sa - sb : sb - sa;
        });
      }

      visibleItems.forEach((item) => produitsContainer.appendChild(item));

      const visibleCount = visibleItems.length;
      if (resultCount) resultCount.textContent = String(visibleCount);

      if (noResults) {
        if (visibleCount === 0) {
          noResults.style.display = "flex";
          produitsContainer.style.display = "none";
        } else {
          noResults.style.display = "none";
          produitsContainer.style.display = "";
        }
      }

      if (clearSearchBtn) clearSearchBtn.style.display = searchTerm ? "block" : "none";
    }

    if (searchInput) searchInput.addEventListener("input", filterAndSortProducts);
    if (sortPriceSelect) sortPriceSelect.addEventListener("change", filterAndSortProducts);
    if (sortStockSelect) sortStockSelect.addEventListener("change", filterAndSortProducts);
    if (filterCategorySelect) filterCategorySelect.addEventListener("change", filterAndSortProducts);

    if (clearSearchBtn) {
      clearSearchBtn.addEventListener("click", () => {
        if (searchInput) searchInput.value = "";
        if (filterCategorySelect) filterCategorySelect.value = "";
        if (sortPriceSelect) sortPriceSelect.value = "";
        if (sortStockSelect) sortStockSelect.value = "";
        if (searchInput) searchInput.focus();
        filterAndSortProducts();
      });
    }

    filterAndSortProducts();

    // ==================================================
    // 4) RECHERCHE VOCALE
    // ==================================================
    const voiceBtn = document.getElementById("voiceSearchBtn");
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
      if (voiceBtn) voiceBtn.style.display = "none";
    } else if (voiceBtn && searchInput) {
      const recognition = new SpeechRecognition();
      recognition.lang = "fr-FR";
      recognition.interimResults = false;
      recognition.maxAlternatives = 1;

      voiceBtn.addEventListener("click", () => {
        try { recognition.start(); } catch (_) {}
      });

      recognition.onstart = () => {
        voiceBtn.classList.add("listening");
        voiceBtn.innerHTML = '<i class="bi bi-mic-mute-fill"></i>';
      };
      recognition.onend = () => {
        voiceBtn.classList.remove("listening");
        voiceBtn.innerHTML = '<i class="bi bi-mic-fill"></i>';
      };
      recognition.onerror = () => {
        voiceBtn.classList.remove("listening");
        voiceBtn.innerHTML = '<i class="bi bi-mic-fill"></i>';
      };
      recognition.onresult = (event) => {
        const text = (event.results?.[0]?.[0]?.transcript || "").trim();
        if (!text) return;
        searchInput.value = text;
        filterAndSortProducts();
      };
    }

    // ==================================================
    // 5) QR CONTACT (modal)
    // ==================================================
    // Handled globally by pharmacieDelegates()

    // ==================================================
    // 6) ✅ RECO RAILS (Best + AI) — AUTO TRAIN STABLE
    // ==================================================
    // ==================================================
// 6) ✅ RECO RAILS (Best + AI) — TRAIN AUTO (CSS) STABLE
// ==================================================
loadRail("bestTrack", "bestEmpty", "/produits/api/best-sellers", { uniqueByCategory: true, autoScroll: true });
loadRail("aiTrack", "aiEmpty", "/produits/api/reco-ai", { uniqueByCategory: false, autoScroll: true });

async function loadRail(trackId, emptyId, apiUrl, opts = {}) {
  const track = document.getElementById(trackId);
  const empty = document.getElementById(emptyId);
  if (!track) return;

  // reset
  track.classList.remove("auto-scroll");
  track.style.removeProperty("--reco-shift");
  track.innerHTML = "";
  if (empty) empty.style.display = "none";

  try {
    const res = await fetch(apiUrl, {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      cache: "no-store",
    });

    const data = await res.json().catch(() => ({}));

    // texte explicatif IA
    if (apiUrl.includes("/reco-ai")) {
      const txt = document.getElementById("aiBasedOnText");
      if (txt && data?.explainText) txt.textContent = data.explainText;
    }

    if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
      if (empty) empty.style.display = "block";
      return;
    }

    renderRail(track, data.items, opts);

  } catch (e) {
    console.error("Reco rail error:", e);
    if (empty) {
      empty.style.display = "block";
      empty.textContent = "Erreur de chargement";
    }
  }
}

function renderRail(trackEl, items, { uniqueByCategory = false, autoScroll = true } = {}) {
  // 1) filtrage 1 par catégorie (option)
  let list = items;
  if (uniqueByCategory) {
    const seen = new Set();
    const out = [];
    for (const p of items) {
      const cat = (p.categorie || "Autre").trim().toLowerCase();
      if (seen.has(cat)) continue;
      seen.add(cat);
      out.push(p);
    }
    list = out;
  }

  // 2) anti doublons id
  const seenId = new Set();
  list = list.filter((p) => {
    const id = p?.id;
    if (!id) return true;
    if (seenId.has(id)) return false;
    seenId.add(id);
    return true;
  });

  // 3) HTML cartes
  const html = list.map((p) => {
    const badges = Array.isArray(p.badges) ? p.badges : [];
    const badgeHtml = badges.length
      ? `<div class="reco-badges">${badges.map(b => `<span class="reco-badge">${escapeHtml(b)}</span>`).join("")}</div>`
      : "";

    const img = p.image || "";
    return `
      <div class="reco-card">
        ${badgeHtml}
        <img class="reco-img" src="${img}" alt="${escapeHtml(p.nom || "")}" loading="lazy"
             onerror="this.src='https://via.placeholder.com/260x160/e5e7eb/6b7280?text=Image'">
        <div class="reco-body">
          <div class="reco-cat">${escapeHtml(p.categorie || "")}</div>
          <div class="reco-name">${escapeHtml(p.nom || "")}</div>
          <div class="reco-price">${Number(p.prix ?? 0).toFixed(2)} DT</div>
          <button class="btn btn-sm btn-primary w-100 reco-btn" type="button" data-add-rail="${p.id}">
            <i class="bi bi-cart-plus me-1"></i> Ajouter
          </button>
        </div>
      </div>
    `;
  }).join("");

  // inner principal
  trackEl.innerHTML = `<div class="reco-inner">${html}</div>`;

  // bind add
  trackEl.querySelectorAll("[data-add-rail]").forEach((btn) => {
    btn.addEventListener("click", () => addToCartFromRail(btn.getAttribute("data-add-rail")));
  });

  // 4) auto-scroll CSS (train)
  if (autoScroll) enableCssTrain(trackEl);
}

function enableCssTrain(trackEl) {
  const inner = trackEl.querySelector(".reco-inner");
  if (!inner) return;

  // pas assez d'items => pas de train
  const cards = inner.querySelectorAll(".reco-card");
  if (cards.length < 4) return;

  // clone 1 fois pour défilement infini
  const clone = inner.cloneNode(true);
  clone.classList.add("reco-clone");
  trackEl.appendChild(clone);

  // attendre que les images donnent une vraie largeur
  waitImagesLoaded(trackEl).then(() => {
    const shift = inner.scrollWidth; // largeur du 1er inner
    if (!shift || shift < 200) return;

    trackEl.style.setProperty("--reco-shift", shift + "px");
    trackEl.classList.add("auto-scroll");
  });
}

function waitImagesLoaded(trackEl) {
  const imgs = Array.from(trackEl.querySelectorAll("img"));
  if (imgs.length === 0) return Promise.resolve();

  return Promise.all(
    imgs.map((img) => {
      if (img.complete) return Promise.resolve();
      return new Promise((resolve) => {
        img.addEventListener("load", resolve, { once: true });
        img.addEventListener("error", resolve, { once: true });
      });
    })
  );
}

async function addToCartFromRail(productId) {
  try {
    const res = await fetch(`/panier/ajouter/${productId}`, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      cache: "no-store",
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
      showFlashMessage(data.message || "Erreur ajout", "error");
      return;
    }

    showFlashMessage(data.message || "Ajouté", "success");
    updateCartBadge(data.count);

  } catch (e) {
    console.error(e);
    showFlashMessage("Erreur réseau", "error");
  }
}

function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
    // ==================================================
    // 7) GROQ CHAT
    // ==================================================
    const fab = document.getElementById("aiFab");
    const panel = document.getElementById("aiPanel");
    const closeBtn = document.getElementById("aiClose");
    const body = document.getElementById("aiBody");
    const aiInput = document.getElementById("aiInput");
    const send = document.getElementById("aiSend");

    const addMsg = (text, who) => {
      if (!body) return;
      const div = document.createElement("div");
      div.className = "ai-msg " + (who === "user" ? "ai-user" : "ai-bot");
      div.textContent = text;
      body.appendChild(div);
      body.scrollTop = body.scrollHeight;
    };

    // Handled globally by pharmacieDelegates()

    const ask = async () => {
      const message = (aiInput?.value || "").trim();
      if (!message) return;

      aiInput.value = "";
      addMsg(message, "user");

      if (send) send.disabled = true;
      if (aiInput) aiInput.disabled = true;

      try {
        const res = await fetch("/api/pharmacie/ai", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ message }),
        });

        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
          addMsg((data && data.reply) ? data.reply : "Erreur IA. Réessaie.", "bot");
        } else {
          addMsg(data.reply, "bot");
        }
      } catch (_) {
        addMsg("Erreur réseau. Vérifie ta connexion.", "bot");
      } finally {
        if (send) send.disabled = false;
        if (aiInput) aiInput.disabled = false;
        aiInput && aiInput.focus();
      }
    };

    // Handled globally by pharmacieDelegates()

    // ==================================================
    // 8) TTS
    // ==================================================
    const canSpeak = ("speechSynthesis" in window) && ("SpeechSynthesisUtterance" in window);

    const stopSpeak = () => {
      if (!canSpeak) return;
      window.speechSynthesis.cancel();
      document.querySelectorAll(".btn-tts.is-speaking").forEach((b) => b.classList.remove("is-speaking"));
    };

    const speak = (btn) => {
      if (!canSpeak) {
        alert("Ton navigateur ne supporte pas la lecture audio (TTS). Essaie Chrome/Edge.");
        return;
      }

      if (btn.classList.contains("is-speaking")) {
        stopSpeak();
        return;
      }

      stopSpeak();
      btn.classList.add("is-speaking");

      const title = btn.dataset.ttsTitle || "";
      const desc = btn.dataset.ttsDesc || "";
      const price = btn.dataset.ttsPrice || "";
      const stock = btn.dataset.ttsStock || "";
      const status = btn.dataset.ttsStatus || "";

      const text =
        `Produit : ${title}. ` +
        (desc ? `Description : ${desc}. ` : "") +
        (price ? `Prix : ${price} dinars. ` : "") +
        (stock ? `Stock : ${stock}. ` : "") +
        (status ? `Statut : ${status}.` : "");

      const u = new SpeechSynthesisUtterance(text);
      u.lang = "fr-FR";
      u.rate = 1;
      u.pitch = 1;

      u.onend = () => btn.classList.remove("is-speaking");
      u.onerror = () => btn.classList.remove("is-speaking");

      window.speechSynthesis.speak(u);
    };

    document.querySelectorAll(".btn-tts").forEach((btn) => btn.addEventListener("click", () => speak(btn)));
    document.querySelectorAll(".modal").forEach((m) => m.addEventListener("hidden.bs.modal", stopSpeak));
    window.addEventListener("beforeunload", stopSpeak);
  });
}

// ==================================================
// UTILITAIRES (global)
// ==================================================
function updateCartBadge(newCount) {
  const badge = document.getElementById("cart-count");
  if (!badge) return;

  const count = parseInt(newCount || "0", 10);
  badge.textContent = count;

  if (count <= 0) badge.classList.add("d-none");
  else badge.classList.remove("d-none");
}

function showFlashMessage(message, type) {
  const container = document.getElementById("flash-messages-container");
  if (!container) return;

  const alert = document.createElement("div");
  alert.className = `alert-custom alert-${type}`;
  alert.innerHTML = `
    <i class="bi bi-${type === "success" ? "check" : "x"}-circle-fill"></i>
    <span>${message}</span>
    <button type="button" class="btn-close-custom">×</button>
  `;

  alert.querySelector(".btn-close-custom").addEventListener("click", () => alert.remove());

  container.appendChild(alert);
  setTimeout(() => alert.classList.add("show"), 10);

  setTimeout(() => {
    alert.style.animation = "slideOut 0.4s ease forwards";
    setTimeout(() => alert.remove(), 400);
  }, 3000);
}
// Theme toggle handled globally by pharmacieDelegates()