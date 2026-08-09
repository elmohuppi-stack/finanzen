<script setup lang="ts">
import { legalContact } from '@/lib/legal'
</script>

<template>
  <section class="stack legal-page">
    <article class="card hero">
      <p class="eyebrow">Impressum</p>
      <h2>Anbieterkennzeichnung nach deutschem Recht</h2>
      <p>
        Pflichtangaben nach <strong>§ 5 DDG</strong>. Dieselben Angaben gelten für alle
        Anwendungen unter elmarhepp.de und stehen dort gesammelt im
        <a href="https://elmarhepp.de/impressum" rel="noopener">gemeinsamen Impressum</a>.
      </p>
    </article>

    <article class="card">
      <h3>Angaben gemäß § 5 DDG</h3>
      <p>
        <strong :class="{ placeholder: !legalContact.ownerNameConfigured }">
          {{ legalContact.ownerName }} </strong
        ><br />
        <span :class="{ placeholder: !legalContact.addressLine1Configured }">
          {{ legalContact.addressLine1 }} </span
        ><br />
        <span :class="{ placeholder: !legalContact.addressLine2Configured }">
          {{ legalContact.addressLine2 }} </span
        ><br />
        <span :class="{ placeholder: !legalContact.countryConfigured }">
          {{ legalContact.country }}
        </span>
      </p>

      <p>
        <strong>Kontakt</strong><br />
        E-Mail:
        <a :href="legalContact.emailHref" :class="{ placeholder: !legalContact.emailConfigured }">
          {{ legalContact.email }}
        </a>
      </p>
    </article>

    <article class="card">
      <h3>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h3>
      <p>
        <strong :class="{ placeholder: !legalContact.contentResponsibleConfigured }">
          {{ legalContact.contentResponsible }} </strong
        ><br />
        <span
          :class="{
            placeholder:
              !legalContact.addressLine1Configured || !legalContact.addressLine2Configured,
          }"
        >
          {{ legalContact.fullAddress }} </span
        ><br />
      </p>
    </article>

    <article class="card">
      <h3>Hinweis zur Plattform</h3>
      <p>
        <strong>Finanzcockpit</strong> ist eine Webanwendung zur Verwaltung, Kategorisierung und
        Auswertung persönlicher Finanzdaten sowie zum CSV-Import von Kontoumsätzen.
      </p>
      <p>
        Öffentliche Inhalte dienen der Beschreibung der Software. Die eigentliche Nutzung erfolgt
        nur nach Anmeldung im geschützten Bereich.
      </p>
    </article>

    <article class="card">
      <h3>Verbraucherstreitbeilegung</h3>
      <p>
        Die EU-Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit:
        <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noreferrer">
          https://ec.europa.eu/consumers/odr/
        </a>
      </p>
      <p>
        Es besteht keine Verpflichtung und keine Bereitschaft, an einem Streitbeilegungsverfahren
        vor einer Verbraucherschlichtungsstelle teilzunehmen, sofern dies für dieses Angebot nicht
        gesetzlich erforderlich ist.
      </p>
    </article>
  </section>
</template>

<style scoped>
.stack {
  display: grid;
  gap: 1rem;
}

.legal-page {
  max-width: 900px;
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

.placeholder {
  color: var(--color-text-muted);
  font-style: italic;
}

a {
  color: var(--color-accent-strong);
}
</style>
