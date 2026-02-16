import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
//import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
// Global submit handler for staff request modal (works with dynamically loaded content)
if (!window.submitStaffRequest) {
	window.submitStaffRequest = async function submitStaffRequest() {
		const formEl = document.getElementById('staffRequestForm');
		if (!formEl) {
			console.error('[StaffRequest] Form element not found');
			if (window.Swal) {
				window.Swal.fire('Erreur', 'Formulaire introuvable.', 'error');
			} else {
				alert('Formulaire introuvable.');
			}
			return;
		}
		const url = formEl.dataset.action || formEl.getAttribute('action');
		if (!url) {
			console.error('[StaffRequest] Submit URL missing');
			if (window.Swal) {
				window.Swal.fire('Erreur', 'URL de soumission manquante.', 'error');
			} else {
				alert('URL de soumission manquante.');
			}
			return;
		}
		const fd = new FormData(formEl);

		// SweetAlert loading
		if (window.Swal) {
			window.Swal.fire({
				title: 'Envoi en cours…',
				allowOutsideClick: false,
				didOpen: () => { window.Swal.showLoading(); }
			});
		}

		try {
				const r = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
				console.log('[StaffRequest] HTTP status:', r.status);
				console.log('[StaffRequest] Content-Type:', r.headers.get('content-type'));
				let data = null;
				const ct = (r.headers.get('content-type') || '').toLowerCase();
				if (ct.includes('application/json')) {
					data = await r.json();
				} else {
					const text = await r.text();
					if (window.Swal) {
						window.Swal.close();
						window.Swal.fire('Erreur', 'Réponse inattendue (pas JSON).', 'error');
					} else {
						alert('Réponse inattendue (pas JSON).');
					}
					console.error('Non-JSON response:', text);
					const alertEl = document.getElementById('staffRequestAlert');
					if (alertEl) {
						alertEl.className = 'alert alert-danger';
						alertEl.textContent = 'Réponse inattendue du serveur. Merci de réessayer.';
						alertEl.classList.remove('d-none');
					}
					return;
				}

				if (window.Swal) window.Swal.close();

				if (!r.ok || !data.success) {
					const msg = (data && (data.error || data.message)) || ('Erreur HTTP ' + r.status);
					if (window.Swal) {
						window.Swal.fire('Erreur', msg, 'error');
					} else {
						alert(msg);
					}
					const alertEl = document.getElementById('staffRequestAlert');
					if (alertEl) {
						alertEl.className = 'alert alert-danger';
						alertEl.textContent = msg;
						alertEl.classList.remove('d-none');
					}
					return;
				}

			if (window.Swal) {
				window.Swal.fire('Demande envoyée', data.message || "Votre demande est en cours d'analyse.", 'success');
			} else if (window.toastSuccess) {
				window.toastSuccess('Succès', data.message || 'Demande envoyée.');
			} else {
				alert(data.message || 'Demande envoyée.');
			}

			// Close global modal if present
			const modalEl = document.getElementById('globalModal');
			if (modalEl && window.bootstrap) {
				const instance = window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
					instance.hide();
					// Clean any lingering backdrop/body classes to avoid layout glitches
					document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
					document.body.classList.remove('modal-open');
					document.body.style.removeProperty('padding-right');

				// Show success in inline alert as well
				const alertEl = document.getElementById('staffRequestAlert');
				if (alertEl) {
					alertEl.className = 'alert alert-success';
					alertEl.textContent = (data && (data.message || 'Demande envoyée.'));
					alertEl.classList.remove('d-none');
				}
			}
		} catch (e) {
			if (window.Swal) {
				window.Swal.close();
				window.Swal.fire('Erreur', e.message || 'Une erreur est survenue.', 'error');
			} else {
				alert(e.message || 'Une erreur est survenue.');
			}
		}
	};
}

// Delegated click support for the submit button (in case onclick isn’t bound)
document.addEventListener('click', (e) => {
	const btn = e.target.closest('[data-action-submit="staff-request"]');
	if (btn) {
		e.preventDefault();
		console.log('[StaffRequest] Click detected');
		window.submitStaffRequest();
	}
});

// Intercept form submit for dynamically injected modal form
document.addEventListener('submit', (e) => {
	if (e.target && e.target.id === 'staffRequestForm') {
		e.preventDefault();
		console.log('[StaffRequest] Form submit intercepted');
		window.submitStaffRequest();
	}
});