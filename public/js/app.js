// Kleine verbeteringen zonder build-stap.
document.addEventListener('DOMContentLoaded', () => {
  // De verplichting volgt het gekozen interventietype; de server controleert dit ook.
  document.querySelectorAll('select[data-interventietype]').forEach((type) => {
    const nummer = type.form?.querySelector('[data-interventienummer]');
    if (!nummer) return;
    const updateInterventienummer = () => {
      nummer.required = type.selectedOptions[0]?.dataset.interventienummerVerplicht === '1';
      nummer.labels?.forEach((label) => label.classList.toggle('required', nummer.required));
    };
    type.addEventListener('change', updateInterventienummer);
    updateInterventienummer();
  });

  // Bij een speciale lijst is geen bevelvoerder nodig.
  const speciaal = document.querySelector('input[data-speciale-lijst]');
  if (speciaal) {
    const updateBevelvoerder = () => document.querySelectorAll('[data-bevelvoerder]').forEach((groep) => {
      groep.hidden = speciaal.checked;
      groep.querySelectorAll('select').forEach((veld) => {
        veld.disabled = speciaal.checked;
        veld.required = !speciaal.checked;
        if (veld.tomselect) {
          veld.tomselect.isRequired = !speciaal.checked;
          speciaal.checked ? veld.tomselect.disable() : veld.tomselect.enable();
          veld.tomselect.refreshState();
        }
      });
    });
    speciaal.addEventListener('change', updateBevelvoerder);
    updateBevelvoerder();
  }

  // Zoekbare keuzelijsten (vervangt de oude jQuery-plugin).
  if (window.TomSelect) {
    document.querySelectorAll('select[data-zoekbaar]').forEach((el) => new TomSelect(el, { maxOptions: 500, allowEmptyOption: true }));
  }

  // Bevestiging voor gevaarlijke acties: <form data-bevestig="...">
  document.querySelectorAll('form[data-bevestig]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!confirm(form.dataset.bevestig)) e.preventDefault();
    });
  });

  // Foutmelding: enkel de velden tonen die bij het gekozen type horen.
  const type = document.querySelector('[data-fout-type]');
  if (type) {
    const rijen = document.querySelectorAll('[data-voor-type]');
    const toon = () => rijen.forEach((rij) => {
      rij.hidden = !rij.dataset.voorType.split(' ').includes(type.value);
    });
    type.addEventListener('change', toon);
    toon();
  }

  // Afdrukpagina: automatisch printen en terugkeren.
  const afdruk = document.querySelector('[data-auto-print]');
  if (afdruk) {
    window.addEventListener('afterprint', () => { if (afdruk.dataset.terug) window.location.href = afdruk.dataset.terug; });
    setTimeout(() => window.print(), 300);
  }

  // Kleur-thema volgen van het besturingssysteem.
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  const zet = () => document.documentElement.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light');
  zet();
  mq.addEventListener('change', zet);
});
