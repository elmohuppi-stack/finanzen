<template>
  <section class="about card">
    <h2>Projektstatus</h2>
    <ul>
      <li>✅ Laravel-Backend angelegt</li>
      <li>✅ Vue-Frontend angelegt</li>
      <li>✅ Finanzdatenmodell für Konten, Umsätze, Splits und Verknüpfungen vorbereitet</li>
      <li>✅ Standardkatalog für private und berufliche Kategorien vorbereitet</li>
      <li>🔜 Nächster Schritt: Auth und erste Importservices</li>
    </ul>
  </section>
</template>

<style scoped>
.card {
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
}

ul {
  padding-left: 1.1rem;
}

li::marker {
  color: var(--color-accent-strong);
}
</style>
