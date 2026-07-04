<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

const storageKey = 'finanzen.privacy.notice.v1'
const isVisible = ref(false)

function acceptNotice() {
  window.localStorage.setItem(storageKey, 'accepted')
  isVisible.value = false
}

onMounted(() => {
  isVisible.value = window.localStorage.getItem(storageKey) !== 'accepted'
})
</script>

<template>
  <transition name="banner-fade">
    <aside v-if="isVisible" class="privacy-banner" aria-label="Datenschutzhinweis">
      <div class="privacy-banner__content">
        <p class="privacy-banner__eyebrow">Datenschutz & lokale Speicherung</p>
        <p class="privacy-banner__text">
          Diese App verwendet technisch notwendige lokale Speicherung für Anmeldung, Darstellung und
          diesen Hinweis. Zusätzlich wird eine cookielose, datensparsame Reichweitenmessung über
          Umami eingesetzt. Details findest du im Datenschutzhinweis.
        </p>
      </div>

      <div class="privacy-banner__actions">
        <RouterLink to="/datenschutz" class="privacy-banner__link">Details ansehen</RouterLink>
        <button class="privacy-banner__button" type="button" @click="acceptNotice">
          Hinweis schließen
        </button>
      </div>
    </aside>
  </transition>
</template>

<style scoped>
.privacy-banner {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 40;
  width: min(32rem, calc(100vw - 2rem));
  display: grid;
  gap: 0.9rem;
  padding: 1rem 1.1rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: color-mix(in srgb, var(--color-surface) 92%, white 8%);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
}

.privacy-banner__eyebrow {
  margin: 0 0 0.25rem;
  font-size: 0.76rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--color-accent-strong);
}

.privacy-banner__text {
  margin: 0;
  line-height: 1.45;
  color: var(--color-text-muted);
}

.privacy-banner__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  justify-content: flex-end;
  align-items: center;
}

.privacy-banner__link {
  color: var(--color-accent-strong);
  text-decoration: none;
  font-weight: 600;
}

.privacy-banner__button {
  border: 0;
  border-radius: 12px;
  padding: 0.65rem 0.9rem;
  background: var(--color-accent-strong);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}

.banner-fade-enter-active,
.banner-fade-leave-active {
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}

.banner-fade-enter-from,
.banner-fade-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

@media (max-width: 720px) {
  .privacy-banner {
    right: 0.75rem;
    bottom: 0.75rem;
    width: calc(100vw - 1.5rem);
  }

  .privacy-banner__actions {
    justify-content: stretch;
  }

  .privacy-banner__link,
  .privacy-banner__button {
    width: 100%;
    text-align: center;
  }
}
</style>
