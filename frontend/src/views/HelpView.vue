<template>
  <section class="stack">
    <article class="card hero">
      <p class="eyebrow">Hilfe</p>
      <h2>So ist die App aufgebaut</h2>
      <p>
        Diese Finanz-App trennt bewusst zwischen Übersicht, Buchungen, Auswertung und Import, damit
        du schneller arbeiten und deine Daten besser verstehen kannst.
      </p>
    </article>

    <article class="card">
      <h3>Navigation</h3>
      <ul>
        <li>
          <strong>Dashboard:</strong> Gesamtüberblick über Konten, Monatsentwicklung und letzte
          Importe
        </li>
        <li><strong>Buchungen:</strong> alle Umsätze durchsuchen, filtern und kategorisieren</li>
        <li><strong>Auswertung:</strong> Kategorien und Zeiträume analysieren</li>
        <li><strong>Import:</strong> CSV hochladen, prüfen und Import-Verlauf ansehen</li>
        <li><strong>Hilfe:</strong> Funktionsbeschreibung, Hinweise und Projektstatus</li>
      </ul>
    </article>

    <article class="card">
      <h3>Projektstatus</h3>
      <ul>
        <li>✅ Authentifizierung mit Registrierung und Login</li>
        <li>✅ CSV-Import für DKB Giro, DKB Visa und PayPal</li>
        <li>✅ Import-Verlauf und duplikatsicheres Einlesen</li>
        <li>✅ Dashboard, Buchungsliste und erste Kategorisierung</li>
        <li>🔜 Nächste Schritte: Split-Buchungen, bessere Charts, Visa-/PayPal-Verknüpfungen</li>
      </ul>
    </article>
  </section>
</template>

<style scoped>
.stack {
  display: grid;
  gap: 1rem;
}

.card {
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
}

.hero {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.eyebrow {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

ul {
  padding-left: 1.1rem;
}

li {
  margin-bottom: 0.45rem;
}

li::marker {
  color: var(--color-accent-strong);
}
</style>
