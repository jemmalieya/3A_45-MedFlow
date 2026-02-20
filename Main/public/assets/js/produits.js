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

  // (close btn)
  document.addEventListener('click', (e) => {
    if (e.target && e.target.classList.contains('btn-close-custom')) {
      const parent = e.target.closest('.alert-custom');
      if (parent) parent.remove();
    }
  });

  // ============================================
  // 2) AJOUTER AU PANIER SANS RELOAD (grille produits)
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
  // 3) RECHERCHE + FILTRES + TRI PRIX + ✅ TRI STOCK
  // ============================================
  const searchInput = document.getElementById('searchInput');
  const clearSearchBtn = document.getElementById('clearSearch');
  const sortPriceSelect = document.getElementById('sortPrice');
  const sortStockSelect = document.getElementById('sortStock'); // ✅ ajouté
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
    const stockOrder = sortStockSelect ? sortStockSelect.value : ''; // ✅ ajouté

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

    // ✅ TRI : priorité STOCK si sélectionné, sinon PRIX
    if (visibleItems.length > 0) {

      // 1) Stock
      if (stockOrder === 'stock_asc' || stockOrder === 'stock_desc') {
        visibleItems.sort((a, b) => {
          const sA = parseInt(a.dataset.stock || '0', 10);
          const sB = parseInt(b.dataset.stock || '0', 10);
          return stockOrder === 'stock_asc' ? sA - sB : sB - sA;
        });
        visibleItems.forEach(item => produitsContainer.appendChild(item));
      }

      // 2) Prix
      else if (sortOrder === 'asc' || sortOrder === 'desc') {
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
  if (sortStockSelect) sortStockSelect.addEventListener('change', filterAndSortProducts); // ✅ ajouté
  if (filterCategorySelect) filterCategorySelect.addEventListener('change', filterAndSortProducts);

  if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', function () {
      if (searchInput) searchInput.value = '';
      if (filterCategorySelect) filterCategorySelect.value = '';
      if (sortPriceSelect) sortPriceSelect.value = '';
      if (sortStockSelect) sortStockSelect.value = ''; // ✅ ajouté
      if (searchInput) searchInput.focus();
      filterAndSortProducts();
    });
  }

  // Ctrl + K focus recherche
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (searchInput) searchInput.focus();
    }
  });

  // ============================================
  // 4) RAILS RECO (Best sellers + AI explainable)
  // ============================================
  loadRail("bestTrack", "bestEmpty", "/produits/api/best-sellers");
  loadRail("aiTrack", "aiEmpty", "/produits/api/reco-ai");
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


// ============================================
// AI RECO RAILS (MedFlow)
// + support explainText / badges
// ============================================
async function loadRail(trackId, emptyId, apiUrl) {
  try {
    const track = document.getElementById(trackId);
    const empty = document.getElementById(emptyId);
    if (!track) return;

    const res = await fetch(apiUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } });
    const data = await res.json();

    // ✅ Afficher explainText si présent (reco-ai)
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

  // Duplication pour effet infini
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