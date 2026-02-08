
document.addEventListener('DOMContentLoaded', function() {

  // Recherche dans le tableau
  const searchInput = document.getElementById('searchInput');
  const table = document.getElementById('produitsTable');
  const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

  if (searchInput) {
    searchInput.addEventListener('keyup', function() {
      const filter = this.value.toLowerCase();

      for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent.toLowerCase();

        row.style.display = text.includes(filter) ? '' : 'none';
      }
    });
  }

  // Tooltips bootstrap
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // ✅ Overlay suppression admin (sans confirm() navigateur)
  const overlay = document.getElementById('adminConfirmOverlay');
  const msg = document.getElementById('adminConfirmMessage');
  const btnNo = document.getElementById('adminConfirmNo');
  const btnYes = document.getElementById('adminConfirmYes');

  let formToSubmit = null;

  document.querySelectorAll('.btn-open-delete').forEach(btn => {
    btn.addEventListener('click', () => {
      const nom = btn.dataset.nom || 'ce produit';
      formToSubmit = btn.closest('form');

      msg.textContent = `Voulez-vous vraiment supprimer "${nom}" ? Cette action est irréversible.`;
      overlay.classList.remove('d-none');
    });
  });

  btnNo.addEventListener('click', () => {
    overlay.classList.add('d-none');
    formToSubmit = null;
  });

  btnYes.addEventListener('click', () => {
    overlay.classList.add('d-none');
    if (formToSubmit) formToSubmit.submit();
  });
});



document.addEventListener('DOMContentLoaded', () => {
    const statusRadios = document.querySelectorAll('.status-option input[type="radio"]');
    const hiddenStatusSelect = document.querySelector('select[name="produit[status_produit]"]');

    statusRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            if (hiddenStatusSelect) {
                hiddenStatusSelect.value = radio.value;
            }
        });
    });
});
