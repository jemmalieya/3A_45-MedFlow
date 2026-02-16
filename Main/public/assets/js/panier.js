document.addEventListener('DOMContentLoaded', () => {
  const toastContainer = document.getElementById('toast-container');
  const confirmOverlay = document.getElementById('confirmOverlay');
  const confirmMessage = document.getElementById('confirmMessage');
  const confirmYes = document.getElementById('confirmYes');
  const confirmNo = document.getElementById('confirmNo');

  // ✅ IMPORTANT : dans ton Twig c’est id="btn-valider"
  const checkoutBtn = document.getElementById('btn-valider');

  let currentAction = null;
  let lastSeverity = null;

  // ================== TOAST ==================
  window.showToast = function (message, color = 'success') {
    if (!toastContainer) return;

    if (!message || message.trim() === '') {
      message = color === 'success' ? 'Succès ✅' : 'Une erreur est survenue ❌';
    }

    const icon = color === 'success' ? 'check-circle' : 'x-circle';

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-white bg-${color} border-0 shadow-lg`;
    toastEl.role = 'alert';
    toastEl.innerHTML = `
      <div class="d-flex">
        <div class="toast-body">
          <i class="bi bi-${icon} me-2"></i>${message}
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    `;

    toastContainer.appendChild(toastEl);
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();

    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
  };

  // ================== BADGE NAVBAR ==================
  function updateCartBadge(count) {
    const badge = document.getElementById('cart-count');
    if (!badge) return;

    badge.textContent = count;
    if (count <= 0) badge.classList.add('d-none');
    else badge.classList.remove('d-none');
  }

  // ================== TOTALS ==================
  function recalcTotals() {
    let subtotal = 0;

    document.querySelectorAll('.cart-item.produit-row').forEach((row) => {
      const price = parseFloat(row.dataset.price || '0');
      const qty = parseInt(row.dataset.qty || '0', 10);
      subtotal += price * qty;
    });

    subtotal = Math.round(subtotal * 100) / 100;

    const subtotalEl = document.getElementById('cart-subtotal');
    const totalEl = document.getElementById('cart-total');

    if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);
    if (totalEl) totalEl.textContent = subtotal.toFixed(2);
  }

  // ================== HEADER COUNTS ==================
  function refreshHeaderCounts() {
    const rows = document.querySelectorAll('.cart-item.produit-row');
    const countProducts = rows.length;

    const countProductsEl = document.getElementById('cart-count-products');
    if (countProductsEl) countProductsEl.textContent = countProducts;

    const textEl = document.getElementById('cart-items-text');
    if (textEl) {
      textEl.textContent =
        countProducts > 0
          ? `${countProducts} article(s) dans votre panier`
          : `Votre panier est vide`;
    }
  }

  function getQtyFromUI(id) {
    const input = document.getElementById(`qty-${id}`);
    return input ? parseInt(input.value || '0', 10) : 0;
  }

  function removeRow(id) {
    const row = document.getElementById(`cart-row-${id}`);
    if (row) row.remove();

    recalcTotals();
    refreshHeaderCounts();

    const remaining = document.querySelectorAll('.cart-item.produit-row').length;
    if (remaining === 0) {
      setTimeout(() => location.reload(), 350);
    }
  }

  function updateQtyUI(id, newQty) {
    const qtyInput = document.getElementById(`qty-${id}`);
    if (qtyInput) qtyInput.value = newQty;

    const row = document.getElementById(`cart-row-${id}`);
    if (row) row.dataset.qty = newQty;

    const price = row ? parseFloat(row.dataset.price || '0') : 0;
    const lineTotal = document.getElementById(`line-${id}`);
    if (lineTotal) lineTotal.textContent = (price * newQty).toFixed(2);

    recalcTotals();
    refreshHeaderCounts();
  }

  // ================== POST JSON ==================
  async function postJson(url) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Erreur');
    return data;
  }

  // ================== CHECKOUT BLOCK ==================
  function setCheckoutBlocked(blocked, msg) {
    if (!checkoutBtn) return;

    checkoutBtn.classList.toggle('btn-validate-blocked', blocked);

    if (blocked) {
      checkoutBtn.setAttribute('aria-disabled', 'true');
      checkoutBtn.setAttribute('title', msg || '🚨 Interaction dangereuse');
      checkoutBtn.addEventListener('click', preventCheckoutOnce, { once: true });
    } else {
      checkoutBtn.removeAttribute('aria-disabled');
      checkoutBtn.removeAttribute('title');
    }
  }

  function preventCheckoutOnce(e) {
    e.preventDefault();
    showToast('🚨 Validation bloquée : interaction dangereuse détectée.', 'danger');
  }

  // ================== REFRESH OpenFDA ==================
  async function refreshOpenFdaUI(showToastOnDanger = false) {
    try {
      const res = await fetch('/panier/check-interactions', {
        headers: { Accept: 'application/json' },
      });
      const data = await res.json().catch(() => null);

      if (!data || !data.checked) return;

      const sev = data.severity || 'safe';

      // éviter spam toast si danger déjà affiché
      const severityChanged = lastSeverity !== sev;
      lastSeverity = sev;

      if (sev === 'danger') {
        setCheckoutBlocked(true, data.message);

        if (showToastOnDanger && severityChanged) {
          showToast(data.message || '🚨 Interaction dangereuse détectée !', 'danger');
        }
      } else {
        setCheckoutBlocked(false);
      }
    } catch (e) {
      console.warn('OpenFDA refresh failed', e);
    }
  }

  // ================== SUPPRIMER (overlay) ==================
  document.querySelectorAll('.btn-supprimer').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const nom = btn.dataset.nom || 'Produit';
      currentAction = { url: `/panier/supprimer/${id}`, type: 'delete', id, nom };

      if (confirmMessage) confirmMessage.textContent = `Voulez-vous vraiment supprimer "${nom}" de votre panier ?`;
      confirmOverlay?.classList.remove('d-none');
    });
  });

  // ================== VIDER ==================
  const btnVider = document.getElementById('btnViderPanier');
  if (btnVider) {
    btnVider.addEventListener('click', () => {
      currentAction = { url: `/panier/vider`, type: 'clear', id: null };
      if (confirmMessage) confirmMessage.textContent = 'Êtes-vous sûr de vouloir vider votre panier ?';
      confirmOverlay?.classList.remove('d-none');
    });
  }

  // ================== PLUS ==================
  document.querySelectorAll('.btn-plus').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id;
      const nom = btn.dataset.nom || 'Produit';

      try {
        const data = await postJson(`/panier/augmenter/${id}`);

        const newQty = typeof data.quantite !== 'undefined'
          ? parseInt(data.quantite, 10)
          : getQtyFromUI(id) + 1;

        updateQtyUI(id, newQty);
        showToast(`Quantité de "${nom}" augmentée ✅`, 'success');

        if (typeof data.count !== 'undefined') updateCartBadge(parseInt(data.count, 10));

        await refreshOpenFdaUI(true);
      } catch (e) {
        showToast(e.message || 'Erreur', 'danger');
      }
    });
  });

  // ================== MINUS ==================
  document.querySelectorAll('.btn-minus').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id;
      const nom = btn.dataset.nom || 'Produit';

      try {
        const data = await postJson(`/panier/diminuer/${id}`);

        let newQty = typeof data.quantite !== 'undefined'
          ? parseInt(data.quantite, 10)
          : getQtyFromUI(id) - 1;

        if (isNaN(newQty)) newQty = 0;

        if (newQty <= 0) {
          removeRow(id);
          showToast(`"${nom}" supprimé du panier ✅`, 'success');
        } else {
          updateQtyUI(id, newQty);
          showToast(`Quantité de "${nom}" diminuée ✅`, 'success');
        }

        if (typeof data.count !== 'undefined') updateCartBadge(parseInt(data.count, 10));

        await refreshOpenFdaUI(false);
      } catch (e) {
        showToast(e.message || 'Erreur', 'danger');
      }
    });
  });

  // ================== CONFIRM YES ==================
  confirmYes?.addEventListener('click', async () => {
    confirmOverlay?.classList.add('d-none');
    if (!currentAction) return;

    try {
      const data = await postJson(currentAction.url);

      if (currentAction.type === 'delete' && currentAction.id) {
        removeRow(currentAction.id);
        showToast('Produit supprimé avec succès ✅', 'success');
      }

      if (currentAction.type === 'clear') {
        location.reload();
        return;
      }

      if (typeof data.count !== 'undefined') updateCartBadge(parseInt(data.count, 10));

      await refreshOpenFdaUI(false);
    } catch (e) {
      showToast(e.message || 'Erreur réseau', 'danger');
    }

    currentAction = null;
  });

  // ================== CONFIRM NO ==================
  confirmNo?.addEventListener('click', () => {
    confirmOverlay?.classList.add('d-none');
    currentAction = null;
  });

  // ================== INIT ==================
  recalcTotals();
  refreshHeaderCounts();
  refreshOpenFdaUI(false);
});
