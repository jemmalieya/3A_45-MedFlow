/**
 * Script pour la gestion des formulaires produit (new/edit)
 */

document.addEventListener('DOMContentLoaded', () => {
    // ✅ Synchronisation du statut avec le select caché
    const statusRadios = document.querySelectorAll('.status-option input[type="radio"]');
    const hiddenStatusSelect = document.querySelector('select[name="produit[status_produit]"]');

    if (statusRadios.length > 0 && hiddenStatusSelect) {
        statusRadios.forEach(radio => {
            radio.addEventListener('change', () => {
                hiddenStatusSelect.value = radio.value;
            });
        });
    }

    // ✅ Aperçu de l'image en temps réel (pour edit.html.twig)
    const imageUrlInput = document.getElementById('imageUrl');
    const previewImg = document.querySelector('#imagePreview img');

    if (imageUrlInput && previewImg) {
        imageUrlInput.addEventListener('input', () => {
            const url = imageUrlInput.value.trim();
            if (url) {
                previewImg.src = url;
                previewImg.onerror = () => {
                    console.log('Erreur de chargement de l\'image');
                };
            }
        });
    }
});