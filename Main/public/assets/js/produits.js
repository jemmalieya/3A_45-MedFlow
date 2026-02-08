document.addEventListener('DOMContentLoaded', () => {

    // ============================================
    // 1. AUTO-SUPPRESSION DES MESSAGES FLASH
    // ============================================
    setTimeout(() => {
      document.querySelectorAll('.alert-custom').forEach(alert => {
        alert.style.animation = 'slideOut 0.4s ease forwards';
        setTimeout(() => alert.remove(), 400);
      });
    }, 3000);
  
    // ============================================
    // 2. AJOUTER AU PANIER SANS RELOAD
    // ============================================
    document.querySelectorAll('.btn-add-cart').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        const nom = btn.dataset.nom;
        const stock = parseInt(btn.dataset.stock || '0', 10);
  
        btn.disabled = true;
  
        try {
          const checkRes = await fetch(`/panier/verifier/${id}`);
          const checkData = await checkRes.json();
          const quantiteDansPanier = parseInt(checkData.quantite || '0', 10);
  
          if (stock > 0 && quantiteDansPanier >= stock) {
            showFlashMessage(`Stock insuffisant ! Seulement ${stock} disponible(s)`, 'error');
            btn.disabled = false;
            return;
          }
  
          const addRes = await fetch(`/panier/ajouter/${id}`, { method: 'POST' });
          const addData = await addRes.json();
  
          if (!addRes.ok || !addData.success) {
            showFlashMessage(addData.message || "Erreur lors de l'ajout au panier", 'error');
            btn.disabled = false;
            return;
          }
  
          showFlashMessage(addData.message, 'success');
          updateCartBadge(addData.count);
          btn.disabled = false;
        } catch (e) {
          showFlashMessage("Erreur lors de l'ajout au panier", 'error');
          btn.disabled = false;
        }
      });
    });
  
    // ============================================
    // 3. RECHERCHE ET FILTRAGE EN TEMPS RÉEL
    // ============================================
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearch');
    const sortPriceSelect = document.getElementById('sortPrice');
    const filterCategorySelect = document.getElementById('filterCategory');
    const produitsContainer = document.getElementById('produitsContainer');
    const produitItems = document.querySelectorAll('.produit-item');
    const noResults = document.getElementById('noResults');
    const resultCount = document.getElementById('count');
  
    // Fonction principale de filtrage et tri
    function filterAndSortProducts() {
      const searchTerm = searchInput.value.toLowerCase().trim();
      const selectedCategory = filterCategorySelect.value.toLowerCase();
      const sortOrder = sortPriceSelect.value;
  
      let visibleItems = [];
      let hiddenCount = 0;
  
      // Filtrer les produits
      produitItems.forEach(item => {
        const nom = item.dataset.nom;
        const categorie = item.dataset.categorie;
        const description = item.dataset.description;
  
        // ✅ CORRECTION : Si searchTerm est vide, on affiche tout
        const matchesSearch = searchTerm === '' || 
          nom.includes(searchTerm) || 
          categorie.includes(searchTerm) ||
          description.includes(searchTerm);
        
        // ✅ CORRECTION : Si selectedCategory est vide, on affiche tout
        const matchesCategory = selectedCategory === '' || categorie === selectedCategory;
  
        if (matchesSearch && matchesCategory) {
          // ✅ Afficher l'élément
          item.classList.remove('hidden', 'fade-out');
          item.style.display = '';
          visibleItems.push(item);
        } else {
          // ✅ Masquer l'élément avec animation
          item.classList.add('fade-out');
          setTimeout(() => {
            item.classList.add('hidden');
            item.style.display = 'none';
          }, 300);
          hiddenCount++;
        }
      });
  
      // Trier les produits visibles par prix
      if (sortOrder && visibleItems.length > 0) {
        visibleItems.sort((a, b) => {
          const priceA = parseFloat(a.dataset.prix);
          const priceB = parseFloat(b.dataset.prix);
          return sortOrder === 'asc' ? priceA - priceB : priceB - priceA;
        });
  
        // Réorganiser le DOM
        visibleItems.forEach(item => {
          produitsContainer.appendChild(item);
        });
      }
  
      // Mettre à jour le compteur
      const visibleCount = produitItems.length - hiddenCount;
      resultCount.textContent = visibleCount;
  
      // Afficher/masquer le message "aucun résultat"
      if (visibleCount === 0) {
        noResults.style.display = 'flex';
        produitsContainer.style.display = 'none';
      } else {
        noResults.style.display = 'none';
        produitsContainer.style.display = 'flex';
      }
  
      // Afficher/masquer le bouton clear
      clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
    }
  
    // Événements en temps réel
    if (searchInput) {
      searchInput.addEventListener('input', filterAndSortProducts);
    }
  
    if (sortPriceSelect) {
      sortPriceSelect.addEventListener('change', filterAndSortProducts);
    }
  
    if (filterCategorySelect) {
      filterCategorySelect.addEventListener('change', filterAndSortProducts);
    }
  
    // Bouton clear
    if (clearSearchBtn) {
      clearSearchBtn.addEventListener('click', function() {
        searchInput.value = '';
        filterCategorySelect.value = '';
        sortPriceSelect.value = '';
        searchInput.focus();
        filterAndSortProducts();
      });
    }
  
    // Raccourci clavier Ctrl+K
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        searchInput.focus();
      }
    });
  });
  
  // ============================================
  // FONCTIONS UTILITAIRES
  // ============================================
  
  function updateCartBadge(newCount) {
    const badge = document.getElementById('cart-count');
    if (!badge) return;
  
    const count = parseInt(newCount || '0', 10);
    badge.textContent = count;
  
    if (count <= 0) {
      badge.classList.add('d-none');
    } else {
      badge.classList.remove('d-none');
    }
  }
  
  function showFlashMessage(message, type) {
    const container = document.getElementById('flash-messages-container');
    if (!container) return;
  
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