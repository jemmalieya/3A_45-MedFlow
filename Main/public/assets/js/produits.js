
    document.addEventListener('DOMContentLoaded', () => {

      // Auto-suppression des messages flash
      setTimeout(() => {
        document.querySelectorAll('.alert-custom').forEach(alert => {
          alert.style.animation = 'slideOut 0.4s ease forwards';
          setTimeout(() => alert.remove(), 400);
        });
      }, 3000);

      // Ajouter au panier sans reload
      document.querySelectorAll('.btn-add-cart').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.dataset.id;
          const nom = btn.dataset.nom;
          const stock = parseInt(btn.dataset.stock || '0', 10);

          btn.disabled = true;

          try {
            // 1) Vérifier quantité actuelle dans panier
            const checkRes = await fetch(`/panier/verifier/${id}`);
            const checkData = await checkRes.json();
            const quantiteDansPanier = parseInt(checkData.quantite || '0', 10);

            if (stock > 0 && quantiteDansPanier >= stock) {
              showFlashMessage(`Stock insuffisant ! Seulement ${stock} disponible(s)`, 'error');
              btn.disabled = false;
              return;
            }

            // 2) Ajouter au panier
            const addRes = await fetch(`/panier/ajouter/${id}`, { method: 'POST' });
            const addData = await addRes.json();

            if (!addRes.ok || !addData.success) {
              showFlashMessage(addData.message || "Erreur lors de l'ajout au panier", 'error');
              btn.disabled = false;
              return;
            }

            // ✅ Flash + mise à jour badge sans reload
            showFlashMessage(addData.message, 'success');
            updateCartBadge(addData.count);

            btn.disabled = false;
          } catch (e) {
            showFlashMessage("Erreur lors de l'ajout au panier", 'error');
            btn.disabled = false;
          }
        });
      });
    });

    // ✅ Met à jour le badge panier (sans reload)
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
      const alert = document.createElement('div');
      alert.className = `alert-custom alert-${type}`;
      alert.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check' : 'x'}-circle-fill"></i>
        <span>${message}</span>
        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">×</button>
      `;

      container.appendChild(alert);
      setTimeout(() => alert.classList.add('show'), 10);
      setTimeout(() => {
        alert.style.animation = 'slideOut 0.4s ease forwards';
        setTimeout(() => alert.remove(), 400);
      }, 3000);
    }
  