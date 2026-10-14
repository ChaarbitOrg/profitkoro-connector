(() => {
  const config = window.ProfitKoroConnector;

  document.querySelectorAll('[data-reveal]').forEach((toggle) => {
    const input = document.getElementById(toggle.dataset.reveal);
    toggle.addEventListener('click', () => {
      const hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      toggle.textContent = hidden ? config.hide : config.show;
    });
  });

  document.querySelectorAll('[data-dialog]').forEach((opener) => {
    const dialog = document.getElementById(opener.dataset.dialog);
    const confirm = dialog.querySelector('[data-confirm-word]');
    const submit = dialog.querySelector('[type="submit"]');

    opener.addEventListener('click', () => {
      confirm.value = '';
      submit.disabled = true;
      dialog.showModal();
    });
    confirm.addEventListener('input', () => {
      submit.disabled = confirm.value.trim() !== confirm.dataset.confirmWord;
    });
    dialog.querySelector('[data-dialog-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
  });

  const shell = document.getElementById('pk-onboarding');
  const form = document.getElementById('pk-connect-form');
  if (!shell || !form) return;

  const show = (screen) => {
    shell.dataset.screen = screen;
    shell.querySelectorAll('[data-step]').forEach((step) => {
      if (step.dataset.step === screen) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });
    document.getElementById(`pk-${screen}-title`)?.focus({ preventScroll: true });
  };

  form.addEventListener('submit', () => show('verify'));

  // The back button can restore the page from cache mid-submit; put the form back.
  window.addEventListener('pageshow', (event) => {
    if (event.persisted && shell.dataset.screen === 'verify') show('connect');
  });
})();
