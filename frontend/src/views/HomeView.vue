<template>
  <section class="grid">
    <article class="hero card">
      <p class="label">Aktueller Stand</p>
      <h2>Das Grundgerüst für die Finanz-App steht.</h2>
      <p>
        Laravel und Vue sind eingerichtet. Als Nächstes folgen Auth, CSV-Import und die
        konsolidierte Umsatzliste mit Visa-, PayPal- und Bargeld-Logik.
      </p>
    </article>

    <article class="card">
      <h3>Datenquellen</h3>
      <ul>
        <li>Girokonto</li>
        <li>Visa 1 &amp; Visa 2</li>
        <li>PayPal</li>
        <li>Bargeld-Wallet</li>
      </ul>
    </article>

    <article class="card">
      <h3>MVP-Funktionen</h3>
      <ul>
        <li>CSV-Import mit Dubletten-Schutz</li>
        <li>Visa-Abrechnung als Transfer statt Blackbox</li>
        <li>Splitbare Buchungen mit Kategorien</li>
        <li>Charts und Monatsauswertungen</li>
      </ul>
    </article>
  </section>
</template>

<style scoped>
.grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.card {
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
  backdrop-filter: blur(10px);
}

.hero {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.label {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--color-accent-strong);
}

h2,
h3 {
  margin-top: 0;
}

p,
ul {
  color: var(--color-text);
}

ul {
  padding-left: 1.1rem;
  margin-bottom: 0;
}

li::marker {
  color: var(--color-accent-strong);
}

@media (min-width: 900px) {
  .hero {
    grid-column: 1 / -1;
  }
}
</style>
