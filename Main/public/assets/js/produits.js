console.log("✅ produits.js chargé v4");

document.addEventListener('DOMContentLoaded', () => {

  // ============================================
  // 1) AUTO-SUPPRESSION DES MESSAGES FLASH
  // ============================================
  setTimeout(() => {
    document.querySelectorAll('.alert-custom').forEach(alert => {
      alert.style.animation = 'slideOut 0.4s ease forwards';
      setTimeout(() => alert.remove(), 400);
    });
  }, 3000);

  document.addEventListener('click', (e) => {
    if (e.target && e.target.classList.contains('btn-close-custom')) {
      const parent = e.target.closest('.alert-custom');
      if (parent) parent.remove();
    }
  });

  // ============================================
  // 2) AJOUTER AU PANIER SANS RELOAD
  // ============================================
  document.querySelectorAll('.btn-add-cart').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id;
      const stock = parseInt(btn.dataset.stock || '0', 10);

      btn.disabled = true;

      try {
        const checkRes = await fetch(`/panier/verifier/${id}`, {
          headers: { "X-Requested-With": "XMLHttpRequest" }
        });
        const checkData = await checkRes.json();
        const quantiteDansPanier = parseInt(checkData.quantite || '0', 10);

        if (stock > 0 && quantiteDansPanier >= stock) {
          showFlashMessage(`Stock insuffisant ! Seulement ${stock} disponible(s)`, 'error');
          btn.disabled = false;
          return;
        }

        const addRes = await fetch(`/panier/ajouter/${id}`, {
          method: 'POST',
          headers: { "X-Requested-With": "XMLHttpRequest" }
        });
        const addData = await addRes.json();

        if (!addRes.ok || !addData.success) {
          showFlashMessage(addData.message || "Erreur lors de l'ajout au panier", 'error');
          btn.disabled = false;
          return;
        }

        showFlashMessage(addData.message || "Ajouté au panier", 'success');
        updateCartBadge(addData.count);
        btn.disabled = false;

      } catch (e) {
        console.error(e);
        showFlashMessage("Erreur lors de l'ajout au panier", 'error');
        btn.disabled = false;
      }
    });
  });

  // ============================================
  // 3) RECHERCHE + FILTRES + TRI
  // ============================================
  const searchInput = document.getElementById('searchInput');
  const clearSearchBtn = document.getElementById('clearSearch');
  const sortPriceSelect = document.getElementById('sortPrice');
  const sortStockSelect = document.getElementById('sortStock');
  const filterCategorySelect = document.getElementById('filterCategory');
  const produitsContainer = document.getElementById('produitsContainer');
  const produitItems = document.querySelectorAll('.produit-item');
  const noResults = document.getElementById('noResults');
  const resultCount = document.getElementById('count');

  function filterAndSortProducts() {
    if (!searchInput || !filterCategorySelect || !produitsContainer) return;

    const searchTerm = (searchInput.value || '').toLowerCase().trim();
    const selectedCategory = (filterCategorySelect.value || '').toLowerCase();
    const sortOrder = sortPriceSelect ? sortPriceSelect.value : '';
    const stockOrder = sortStockSelect ? sortStockSelect.value : '';

    let visibleItems = [];
    let hiddenCount = 0;

    produitItems.forEach(item => {
      const nom = (item.dataset.nom || '');
      const categorie = (item.dataset.categorie || '');
      const description = (item.dataset.description || '');

      const matchesSearch =
        searchTerm === '' ||
        nom.includes(searchTerm) ||
        categorie.includes(searchTerm) ||
        description.includes(searchTerm);

      const matchesCategory =
        selectedCategory === '' || categorie === selectedCategory;

      if (matchesSearch && matchesCategory) {
        item.classList.remove('hidden', 'fade-out');
        item.style.display = '';
        visibleItems.push(item);
      } else {
        item.classList.add('fade-out');
        setTimeout(() => {
          item.classList.add('hidden');
          item.style.display = 'none';
        }, 250);
        hiddenCount++;
      }
    });

    if (visibleItems.length > 0) {
      if (stockOrder === 'stock_asc' || stockOrder === 'stock_desc') {
        visibleItems.sort((a, b) => {
          const sA = parseInt(a.dataset.stock || '0', 10);
          const sB = parseInt(b.dataset.stock || '0', 10);
          return stockOrder === 'stock_asc' ? sA - sB : sB - sA;
        });
        visibleItems.forEach(item => produitsContainer.appendChild(item));
      } else if (sortOrder === 'asc' || sortOrder === 'desc') {
        visibleItems.sort((a, b) => {
          const priceA = parseFloat(a.dataset.prix || '0');
          const priceB = parseFloat(b.dataset.prix || '0');
          return sortOrder === 'asc' ? priceA - priceB : priceB - priceA;
        });
        visibleItems.forEach(item => produitsContainer.appendChild(item));
      }
    }

    const visibleCount = produitItems.length - hiddenCount;
    if (resultCount) resultCount.textContent = visibleCount;

    if (noResults) {
      if (visibleCount === 0) {
        noResults.style.display = 'flex';
        produitsContainer.style.display = 'none';
      } else {
        noResults.style.display = 'none';
        produitsContainer.style.display = 'flex';
      }
    }

    if (clearSearchBtn) clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
  }

  if (searchInput) searchInput.addEventListener('input', filterAndSortProducts);
  if (sortPriceSelect) sortPriceSelect.addEventListener('change', filterAndSortProducts);
  if (sortStockSelect) sortStockSelect.addEventListener('change', filterAndSortProducts);
  if (filterCategorySelect) filterCategorySelect.addEventListener('change', filterAndSortProducts);

  if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', function () {
      if (searchInput) searchInput.value = '';
      if (filterCategorySelect) filterCategorySelect.value = '';
      if (sortPriceSelect) sortPriceSelect.value = '';
      if (sortStockSelect) sortStockSelect.value = '';
      if (searchInput) searchInput.focus();
      filterAndSortProducts();
    });
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (searchInput) searchInput.focus();
    }
  });

  // ============================================
  // 4) RAILS RECO
  // ============================================
  loadRail("bestTrack", "bestEmpty", "/produits/api/best-sellers");
  loadRail("aiTrack", "aiEmpty", "/produits/api/reco-ai");

  // ============================================
  // 5) ✅ QR CONTACT VCARD - VERSION CORRIGÉE
  // ============================================
  const btnContact = document.getElementById('btnContactQr');
  const modalEl = document.getElementById('contactQrModal');

  if (btnContact && modalEl) {
    console.log("✅ Bouton Contact et Modal trouvés");

    if (!window.bootstrap || !bootstrap.Modal) {
      console.error("❌ Bootstrap Modal non chargé");
      alert("Bootstrap n'est pas chargé. Vérifiez que bootstrap.bundle.min.js est inclus.");
      return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const loading = document.getElementById('contactQrLoading');
    const content = document.getElementById('contactQrContent');
    const errorBox = document.getElementById('contactQrError');
    const errorMsg = document.getElementById('contactQrErrorMsg');
    const img = document.getElementById('contactQrImg');

    const showLoading = () => {
      console.log("🔄 Affichage du loading");
      if (loading) loading.classList.remove('d-none');
      if (content) content.classList.add('d-none');
      if (errorBox) errorBox.classList.add('d-none');
    };

    const showError = (msg) => {
      console.error("❌ Erreur:", msg);
      if (loading) loading.classList.add('d-none');
      if (content) content.classList.add('d-none');
      if (errorBox) errorBox.classList.remove('d-none');
      if (errorMsg) errorMsg.textContent = msg || 'Erreur inconnue.';
    };

    const showContent = () => {
      console.log("✅ Affichage du QR");
      if (loading) loading.classList.add('d-none');
      if (errorBox) errorBox.classList.add('d-none');
      if (content) content.classList.remove('d-none');
    };

    btnContact.addEventListener('click', async (e) => {
      e.preventDefault();
      console.log("🔘 Clic sur btnContact");

      showLoading();
      modal.show();

      try {
        console.log("📡 Fetch /contact-pharmacie/qr.json");
        const res = await fetch('/contact-pharmacie/qr.json', {
          headers: { 'Accept': 'application/json' }
        });

        console.log("📨 Status HTTP:", res.status);

        if (!res.ok) {
          throw new Error(`Erreur HTTP ${res.status}`);
        }

        const raw = await res.text();
        console.log("📄 Réponse brute:", raw.slice(0, 200));

        let data;
        try {
          data = JSON.parse(raw);
        } catch {
          throw new Error(`Réponse non-JSON : ${raw.slice(0, 120)}`);
        }

        console.log("📦 Données JSON:", data);

        if (!data.ok) {
          throw new Error(data.error || 'Erreur serveur');
        }

        const fullUrl = window.location.origin + data.qrPath + '?v=' + Date.now();
        console.log("🖼️ URL QR:", fullUrl);

        if (img) {
          img.onload = () => {
            console.log("✅ Image chargée");
            showContent();
          };
          img.onerror = () => {
            console.error("❌ Erreur chargement image");
            showError("L'image QR n'a pas pu être chargée.");
          };
          img.src = fullUrl;
        }

        // Timeout de sécurité
        setTimeout(() => {
          if (loading && !loading.classList.contains('d-none')) {
            console.log("⏱️ Timeout - affichage forcé");
            showContent();
          }
        }, 2000);

      } catch (e) {
        console.error("💥 Exception:", e);
        showError(e.message);
      }
    });

  } else {
    console.error("❌ Bouton Contact OU Modal introuvable");
    console.log("btnContact:", btnContact);
    console.log("modalEl:", modalEl);
  }

});

// ============================================
// UTILITAIRES
// ============================================
function updateCartBadge(newCount) {
  const badge = document.getElementById('cart-count');
  if (!badge) return;

  const count = parseInt(newCount || '0', 10);
  badge.textContent = count;

  if (count <= 0) badge.classList.add('d-none');
  else badge.classList.remove('d-none');
}

function showFlashMessage(message, type) {
  const container = document.getElementById('flash-messages-container');
  if (!container) return;

  const alert = document.createElement('div');
  alert.className = `alert-custom alert-${type}`;
  alert.innerHTML = `
    <i class="bi bi-${type === 'success' ? 'check' : 'x'}-circle-fill"></i>
    <span>${message}</span>
    <button type="button" class="btn-close-custom">×</button>
  `;

  container.appendChild(alert);
  setTimeout(() => alert.classList.add('show'), 10);
  setTimeout(() => {
    alert.style.animation = 'slideOut 0.4s ease forwards';
    setTimeout(() => alert.remove(), 400);
  }, 3000);
}

async function loadRail(trackId, emptyId, apiUrl) {
  try {
    const track = document.getElementById(trackId);
    const empty = document.getElementById(emptyId);
    if (!track) return;

    const res = await fetch(apiUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } });
    const data = await res.json();

    if (apiUrl.includes("/produits/api/reco-ai")) {
      const txt = document.getElementById('aiBasedOnText');
      if (txt && data && data.explainText) txt.textContent = data.explainText;
    }

    if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
      if (empty) empty.style.display = "flex";
      track.innerHTML = "";
      return;
    }

    if (empty) empty.style.display = "none";
    renderRail(track, data.items);

  } catch (e) {
    console.error("Reco rail error:", e);
    const empty = document.getElementById(emptyId);
    if (empty) {
      empty.style.display = "flex";
      empty.textContent = "Erreur de chargement";
    }
  }
}

function renderRail(trackEl, items) {
  const cards = items.map(p => {
    const badges = Array.isArray(p.badges) ? p.badges : [];
    const badgeHtml = badges.length
      ? `<div class="reco-badges">${badges.map(b => `<span class="reco-badge">${b}</span>`).join('')}</div>`
      : '';

    return `
      <div class="reco-card">
        ${badgeHtml}
        <img class="reco-img"
             src="${p.image || ''}"
             alt="${p.nom || ''}"
             onerror="this.src='https://via.placeholder.com/260x160/e5e7eb/6b7280?text=Image'">
        <div class="reco-body">
          <div class="reco-cat">${p.categorie || ''}</div>
          <div class="reco-name">${p.nom || ''}</div>
          <div class="reco-price">${Number(p.prix ?? 0).toFixed(2)} DT</div>
          <button class="btn btn-sm btn-primary reco-btn" onclick="addToCartFromRail(${p.id})">
            <i class="bi bi-cart-plus me-1"></i> Ajouter
          </button>
        </div>
      </div>
    `;
  }).join("");

  if (items.length >= 4) trackEl.innerHTML = cards + cards;
  else trackEl.innerHTML = cards + cards + cards + cards;
}

async function addToCartFromRail(productId) {
  try {
    const addRes = await fetch(`/panier/ajouter/${productId}`, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" }
    });
    const addData = await addRes.json();

    if (!addRes.ok || !addData.success) {
      showFlashMessage(addData.message || "Erreur lors de l'ajout au panier", "error");
      return;
    }

    showFlashMessage(addData.message || "Ajouté au panier", "success");
    updateCartBadge(addData.count);

  } catch (e) {
    console.error(e);
    showFlashMessage("Erreur lors de l'ajout au panier", "error");
  }
}