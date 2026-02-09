document.addEventListener('DOMContentLoaded', () => {
  const wrapper = document.getElementById('participationFormWrapper');
  const radios = document.querySelectorAll('.js-participate-choice');
  const btnCancel = document.getElementById('btnCancelParticipation');

  function refresh() {
    const checked = document.querySelector('.js-participate-choice:checked');
    if (!checked) return;

    if (checked.value === 'oui') {
      wrapper.classList.remove('d-none');
    } else {
      wrapper.classList.add('d-none');
    }
  }

  radios.forEach(r => r.addEventListener('change', refresh));

  if (btnCancel) {
    btnCancel.addEventListener('click', () => {
      const non = document.getElementById('participerNon');
      if (non) non.checked = true;
      refresh();
    });
  }

  refresh();
});
