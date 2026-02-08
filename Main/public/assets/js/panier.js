document.addEventListener('DOMContentLoaded', () => {
    const toastContainer = document.getElementById('toast-container');
    const confirmOverlay = document.getElementById('confirmOverlay');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmYes = document.getElementById('confirmYes');
    const confirmNo = document.getElementById('confirmNo');
  
    let currentAction = null; // { url, type, id }
  
    // ================== TOAST ==================
    window.showToast = function(message, color = 'success') {
      if (!toastContainer) return;
  
      if (!message || message.trim() === '') {
        message = (color === 'success') ? 'Succès ✅' : 'Une erreur est survenue ❌';
      }
  
      const icon = (color === 'success') ? 'check-circle' : 'x-circle';
  
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
    };
  
    // ================== BADGE ==================
    function updateCartBadge(count) {
      const badge = document.getElementById('cart-count');
      if (!badge) return;
  
      badge.textContent = count;
      if (count <= 0) badge.classList.add('d-none');
      else badge.classList.remove('d-none');
    }
  
    // ================== TOTAL ==================
    function recalcTotals() {
      let subtotal = 0;
  
      document.querySelectorAll('.cart-item.produit-row').forEach(row => {
        const price = parseFloat(row.dataset.price || '0');
        const qty = parseInt(row.dataset.qty || '0', 10);
        subtotal += price * qty;
      });
  
      subtotal = Math.round(subtotal * 100) / 100;
  
      const subtotalEl = document.getElementById('cart-subtotal');
      const totalEl = document.getElementById('cart-total');
      if (subtotalEl) subtotalEl.textContent = subtotal;
      if (totalEl) totalEl.textContent = subtotal;
    }
  
    // ================== HEADER ==================
    function refreshHeaderCounts() {
      const rows = document.querySelectorAll('.cart-item.produit-row');
      const countProducts = rows.length;
  
      const countProductsEl = document.getElementById('cart-count-products');
      if (countProductsEl) countProductsEl.textContent = countProducts;
  
      const textEl = document.getElementById('cart-items-text');
      if (textEl) {
        textEl.textContent = (countProducts > 0)
          ? `${countProducts} article(s) dans votre panier`
          : `Votre panier est vide`;
      }
    }
  
    // ================== HELPERS ==================
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
        setTimeout(() => location.reload(), 400);
      }
    }
  
    async function postJson(url) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });
  
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || 'Erreur');
      return data;
    }
  
    function updateQtyUI(id, newQty) {
      const qtyInput = document.getElementById(`qty-${id}`);
      if (qtyInput) qtyInput.value = newQty;
  
      const row = document.getElementById(`cart-row-${id}`);
      if (row) row.dataset.qty = newQty;
  
      const price = row ? parseFloat(row.dataset.price || '0') : 0;
      const lineTotal = document.getElementById(`line-${id}`);
      if (lineTotal) lineTotal.textContent = (price * newQty).toFixed(0);
  
      recalcTotals();
      refreshHeaderCounts();
    }
  
    // ================== SUPPRIMER (OVERLAY) ==================
    document.querySelectorAll('.btn-supprimer').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const nom = btn.dataset.nom || 'Produit';
  
        currentAction = { url: `/panier/supprimer/${id}`, type: 'delete', id: id };
        confirmMessage.textContent = `Voulez-vous vraiment supprimer "${nom}" de votre panier ?`;
        confirmOverlay.classList.remove('d-none');
      });
    });
  
    const btnVider = document.getElementById('btnViderPanier');
    if (btnVider) {
      btnVider.addEventListener('click', () => {
        currentAction = { url: `/panier/vider`, type: 'clear', id: null };
        confirmMessage.textContent = 'Êtes-vous sûr de vouloir vider votre panier ?';
        confirmOverlay.classList.remove('d-none');
      });
    }
  
    // ================== PLUS ==================
    document.querySelectorAll('.btn-plus').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        const nom = btn.dataset.nom || 'Produit';
  
        try {
          const data = await postJson(`/panier/augmenter/${id}`);
          const newQty = (typeof data.quantite !== 'undefined')
            ? parseInt(data.quantite, 10)
            : getQtyFromUI(id) + 1;
  
          updateQtyUI(id, newQty);
          showToast(`Quantité de "${nom}" augmentée ✅`, 'success');
          updateCartBadge(data.count);
        } catch (e) {
          showToast(e.message || 'Erreur', 'danger');
        }
      });
    });
  
    // ================== MINUS ==================
    document.querySelectorAll('.btn-minus').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        const nom = btn.dataset.nom || 'Produit';
  
        try {
          const data = await postJson(`/panier/diminuer/${id}`);
          let newQty = (typeof data.quantite !== 'undefined')
            ? parseInt(data.quantite, 10)
            : getQtyFromUI(id) - 1;
  
          if (isNaN(newQty)) newQty = 0;
  
          if (newQty <= 0) {
            removeRow(id);
            showToast(`"${nom}" supprimé du panier ✅`, 'success');
            updateCartBadge(data.count);
            return;
          }
  
          updateQtyUI(id, newQty);
          showToast(`Quantité de "${nom}" diminuée ✅`, 'success');
          updateCartBadge(data.count);
        } catch (e) {
          showToast(e.message || 'Erreur', 'danger');
        }
      });
    });
  
    // ================== CONFIRMATION ==================
    confirmYes?.addEventListener('click', async () => {
      confirmOverlay.classList.add('d-none');
      if (!currentAction) return;
  
      try {
        const data = await postJson(currentAction.url);
  
        if (currentAction.type === 'delete' && currentAction.id) {
          removeRow(currentAction.id);
          showToast('Produit supprimé avec succès ✅', 'success');
        }
  
        if (currentAction.type === 'clear') {
          location.reload();
        }
  
        updateCartBadge(data.count);
      } catch (e) {
        showToast(e.message || 'Erreur réseau', 'danger');
      }
  
      currentAction = null;
    });
  
    confirmNo?.addEventListener('click', () => {
      confirmOverlay.classList.add('d-none');
      currentAction = null;
    });
  });
  