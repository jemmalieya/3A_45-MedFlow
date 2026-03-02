/* public/assets/js/produits.js */
console.log("✅ produits.js chargé (FINAL stable + no double add)");

(function PHARMACIE_APP() {
  // ✅ Anti double init (même si script chargé 2 fois)
  if (window.__PHARMACIE_APP_INIT__) {
    console.warn("⚠️ produits.js déjà initialisé -> skip");
    return;
  }
  window.__PHARMACIE_APP_INIT__ = true;

  // ==================================================
  // ✅ GLOBAL LOCKS (anti double click / double request)
  // ==================================================
  const cartLocks = new Set(); // lock par productId (grille)
  const railLocks = new Set(); // lock par productId (rail)
  let contactInFlight = false;
  let aiInFlight = false;

  const THEME_KEY = "theme";

  // ==================================================
  // ✅ THEME
  // ==================================================
  function applySavedTheme() {
    const theme = localStorage.getItem(THEME_KEY) === "dark" ? "dark" : "light";
    document.body.classList.toggle("dark", theme === "dark");
  }

  function toggleTheme() {
    const next = document.body.classList.contains("dark") ? "light" : "dark";
    localStorage.setItem(THEME_KEY, next);
    applySavedTheme();
  }

  // ==================================================
  // ✅ FLASH
  // ==================================================
  function showFlashMessage(message, type) {
    const container = document.getElementById("flash-messages-container");
    if (!container) return;

    const alert = document.createElement("div");
    alert.className = `alert-custom alert-${type}`;
    alert.innerHTML = `
      <i class="bi bi-${type === "success" ? "check" : "x"}-circle-fill"></i>
      <span>${escapeHtml(message)}</span>
      <button type="button" class="btn-close-custom">×</button>
    `;

    alert.querySelector(".btn-close-custom")?.addEventListener("click", () => alert.remove());

    container.appendChild(alert);
    setTimeout(() => alert.classList.add("show"), 10);

    setTimeout(() => {
      alert.style.animation = "slideOut 0.4s ease forwards";
      setTimeout(() => alert.remove(), 400);
    }, 3000);
  }

  function autoRemoveExistingFlash() {
    setTimeout(() => {
      document.querySelectorAll(".alert-custom").forEach((alert) => {
        alert.style.animation = "slideOut 0.4s ease forwards";
        setTimeout(() => alert.remove(), 400);
      });
    }, 3000);
  }

  // ==================================================
  // ✅ CART BADGE
  // ==================================================
  function updateCartBadge(newCount) {
    const badge = document.getElementById("cart-count");
    if (!badge) return;

    const count = parseInt(newCount || "0", 10);
    badge.textContent = String(count);

    if (count <= 0) badge.classList.add("d-none");
    else badge.classList.remove("d-none");
  }

  // ==================================================
  // ✅ QR CONTACT
  // ==================================================
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
        throw new Error("Réponse non-JSON (redirect / erreur serveur).");
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

  // ==================================================
  // ✅ AI PANEL
  // ==================================================
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

  // ==================================================
  // ✅ PANIER (GRILLE) — Event Delegation + Lock
  // ==================================================
  async function handleAddToCart(btn) {
    const id = btn?.dataset?.id;
    const stock = parseInt(btn?.dataset?.stock || "0", 10);
    if (!id) return;

    // 🔒 anti double request
    if (cartLocks.has(id)) return;
    cartLocks.add(id);

    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<i class="bi bi-hourglass-split"></i><span>Ajout...</span>`;

    try {
      // ✅ vérifier quantité dans panier
      const checkRes = await fetch(`/panier/verifier/${id}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        cache: "no-store",
      });

      const checkData = await checkRes.json().catch(() => ({}));
      const quantiteDansPanier = parseInt(checkData.quantite || "0", 10);

      if (stock > 0 && quantiteDansPanier >= stock) {
        showFlashMessage(`Stock insuffisant ! Max ${stock}`, "error");
        return;
      }

      // ✅ ajouter
      const addRes = await fetch(`/panier/ajouter/${id}`, {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        cache: "no-store",
      });

      const addData = await addRes.json().catch(() => ({}));
      if (!addRes.ok || !addData.success) {
        showFlashMessage(addData.message || "Erreur ajout", "error");
        return;
      }

      showFlashMessage(addData.message || "Ajouté au panier", "success");
      updateCartBadge(addData.count);
    } catch (err) {
      console.error(err);
      showFlashMessage("Erreur réseau", "error");
    } finally {
      btn.disabled = false;
      btn.innerHTML = oldHtml;
      cartLocks.delete(id);
    }
  }

  // ==================================================
  // ✅ PANIER (RAILS) — Lock aussi
  // ==================================================
  async function addToCartFromRail(productId) {
    if (!productId) return;

    if (railLocks.has(productId)) return;
    railLocks.add(productId);

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
    } finally {
      railLocks.delete(productId);
    }
  }

  // ==================================================
  // ✅ FILTRES + TRI
  // ==================================================
  function initFilters() {
    const searchInput = document.getElementById("searchInput");
    const clearSearchBtn = document.getElementById("clearSearch");
    const sortPriceSelect = document.getElementById("sortPrice");
    const sortStockSelect = document.getElementById("sortStock");
    const filterCategorySelect = document.getElementById("filterCategory");
    const produitsContainer = document.getElementById("produitsContainer");
    const noResults = document.getElementById("noResults");
    const resultCount = document.getElementById("count");

    if (!searchInput || !filterCategorySelect || !produitsContainer) return;

    const produitItems = Array.from(document.querySelectorAll(".produit-item"));

    function filterAndSortProducts() {
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

    searchInput.addEventListener("input", filterAndSortProducts);
    sortPriceSelect?.addEventListener("change", filterAndSortProducts);
    sortStockSelect?.addEventListener("change", filterAndSortProducts);
    filterCategorySelect.addEventListener("change", filterAndSortProducts);

    clearSearchBtn?.addEventListener("click", () => {
      searchInput.value = "";
      filterCategorySelect.value = "";
      if (sortPriceSelect) sortPriceSelect.value = "";
      if (sortStockSelect) sortStockSelect.value = "";
      searchInput.focus();
      filterAndSortProducts();
    });

    filterAndSortProducts();
  }

  // ==================================================
  // ✅ RECHERCHE VOCALE
  // ==================================================
  function initVoiceSearch() {
    const voiceBtn = document.getElementById("voiceSearchBtn");
    const searchInput = document.getElementById("searchInput");
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!voiceBtn) return;

    if (!SpeechRecognition) {
      voiceBtn.style.display = "none";
      return;
    }

    if (!searchInput) return;

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
      // déclenche input event pour filtres
      searchInput.dispatchEvent(new Event("input", { bubbles: true }));
    };
  }

  // ==================================================
  // ✅ RAILS (Best + AI)
  // ==================================================
  async function loadRail(trackId, emptyId, apiUrl, opts = {}) {
    const track = document.getElementById(trackId);
    const empty = document.getElementById(emptyId);
    if (!track) return;

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
    let list = items;

    // 1 par catégorie (option)
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

    // anti doublons par id
    const seenId = new Set();
    list = list.filter((p) => {
      const id = p?.id;
      if (!id) return true;
      if (seenId.has(id)) return false;
      seenId.add(id);
      return true;
    });

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

    trackEl.innerHTML = `<div class="reco-inner">${html}</div>`;

    // binds add (uniquement dans rail, safe car rail est recréé)
    trackEl.querySelectorAll("[data-add-rail]").forEach((btn) => {
      btn.addEventListener("click", () => addToCartFromRail(btn.getAttribute("data-add-rail")));
    });

    if (autoScroll) enableCssTrain(trackEl);
  }

  function enableCssTrain(trackEl) {
    const inner = trackEl.querySelector(".reco-inner");
    if (!inner) return;

    const cards = inner.querySelectorAll(".reco-card");
    if (cards.length < 4) return;

    // clone 1 fois
    const clone = inner.cloneNode(true);
    clone.classList.add("reco-clone");
    trackEl.appendChild(clone);

    waitImagesLoaded(trackEl).then(() => {
      const shift = inner.scrollWidth;
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

  // ==================================================
  // ✅ TTS
  // ==================================================
  function initTTS() {
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

    // delegation simple pour TTS
    document.addEventListener("click", (e) => {
      const btn = e.target?.closest?.(".btn-tts");
      if (!btn) return;
      e.preventDefault();
      speak(btn);
    });

    document.querySelectorAll(".modal").forEach((m) => m.addEventListener("hidden.bs.modal", stopSpeak));
    window.addEventListener("beforeunload", stopSpeak);
  }

  // ==================================================
  // ✅ HELPERS
  // ==================================================
  function escapeHtml(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  // ==================================================
  // ✅ GLOBAL EVENTS (ONE TIME)
  // ==================================================
  function bindGlobalDelegation() {
    // Click delegation (theme/contact/AI/add-to-cart grid)
    document.addEventListener("click", (e) => {
      const el = e.target?.closest?.("#themeToggle, #btnContactQr, #aiFab, #aiClose, #aiSend, .btn-add-cart");
      if (!el) return;

      // Theme
      if (el.id === "themeToggle") {
        e.preventDefault();
        toggleTheme();
        return;
      }

      // Contact QR
      if (el.id === "btnContactQr") {
        e.preventDefault();
        openContactQrModal();
        return;
      }

      // AI
      if (el.id === "aiFab") {
        e.preventDefault();
        toggleAiPanel();
        return;
      }
      if (el.id === "aiClose") {
        e.preventDefault();
        closeAiPanel();
        return;
      }
      if (el.id === "aiSend") {
        e.preventDefault();
        askAi();
        return;
      }

      // ✅ Add to cart (GRILLE)
      if (el.classList.contains("btn-add-cart")) {
        e.preventDefault();
        handleAddToCart(el);
      }
    });

    // Enter-to-send AI
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;
      const isAiInput = e.target && e.target.id === "aiInput";
      if (!isAiInput) return;
      e.preventDefault();
      askAi();
    });

    // close flash by x (si flash généré côté serveur)
    document.addEventListener("click", (e) => {
      if (e.target && e.target.classList.contains("btn-close-custom")) {
        const parent = e.target.closest(".alert-custom");
        if (parent) parent.remove();
      }
    });
  }

  // ==================================================
  // ✅ INIT (DOM ready + turbo safe)
  // ==================================================
  function initPage() {
    applySavedTheme();
    autoRemoveExistingFlash();
    initFilters();
    initVoiceSearch();
    initTTS();

    // Rails
    loadRail("bestTrack", "bestEmpty", "/produits/api/best-sellers", { uniqueByCategory: true, autoScroll: true });
    loadRail("aiTrack", "aiEmpty", "/produits/api/reco-ai", { uniqueByCategory: false, autoScroll: true });
  }

  // Bind global delegation once
  bindGlobalDelegation();

  // Apply theme ASAP
  applySavedTheme();

  // Standard DOM init
  document.addEventListener("DOMContentLoaded", initPage);

  // Turbo compatibility (si jamais tu utilises Turbo)
  document.addEventListener("turbo:load", initPage);
})();