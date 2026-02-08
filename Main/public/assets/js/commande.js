
function confirmerCommande() {
    document.getElementById('confirmModal').classList.add('active');
}

function fermerModal() {
    document.getElementById('confirmModal').classList.remove('active');
}

// Fermer avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fermerModal();
    }
});



