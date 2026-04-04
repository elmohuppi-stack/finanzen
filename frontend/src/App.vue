<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const theme = ref<'light' | 'dark'>('light')
const isDark = computed(() => theme.value === 'dark')

function applyTheme(value: 'light' | 'dark') {
  document.documentElement.setAttribute('data-theme', value)
  window.localStorage.setItem('finanzen-theme', value)
}

function toggleTheme() {
  theme.value = isDark.value ? 'light' : 'dark'
}

onMounted(() => {
  const storedTheme = window.localStorage.getItem('finanzen-theme')

  if (storedTheme === 'light' || storedTheme === 'dark') {
    theme.value = storedTheme
  } else {
    theme.value = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }

  applyTheme(theme.value)
})

watch(theme, (value) => {
  applyTheme(value)
})
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <div>
        <p class="eyebrow">Finanzen MVP</p>
        <h1>Persönliche Finanzübersicht</h1>
      </div>

      <div class="nav-block">
        <nav>
          <RouterLink to="/">Dashboard</RouterLink>
          <RouterLink to="/imports/preview">Import-Vorschau</RouterLink>
          <RouterLink to="/about">Projektstatus</RouterLink>
          <RouterLink to="/login">Login</RouterLink>
        </nav>

        <button
          class="theme-toggle"
          :class="{ 'is-dark': isDark }"
          type="button"
          aria-label="Farbschema wechseln"
          @click="toggleTheme"
        >
          <span class="theme-toggle__track">
            <span class="theme-toggle__thumb" />
          </span>
          <span class="theme-toggle__label">{{ isDark ? 'Dunkel' : 'Hell' }}</span>
        </button>

        <button
          v-if="authStore.isAuthenticated"
          class="logout-button"
          type="button"
          @click="authStore.logout()"
        >
          Logout
        </button>
      </div>
    </header>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  width: min(100%, 1440px);
  margin: 0 auto;
  padding: 1.25rem clamp(1rem, 2vw, 2rem) 2rem;
}

.topbar {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-bottom: 2rem;
}

.eyebrow {
  margin: 0;
  color: var(--color-accent-strong);
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

h1 {
  margin: 0.25rem 0 0;
  font-size: clamp(1.8rem, 3vw, 2.4rem);
  color: var(--color-heading);
}

.nav-block {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
  justify-content: end;
}

nav {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

nav a {
  padding: 0.6rem 0.95rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  border: 1px solid transparent;
  font-weight: 600;
}

nav a.router-link-exact-active {
  background: var(--color-accent-strong);
  color: #fff;
  box-shadow: 0 10px 24px rgba(79, 70, 229, 0.24);
}

.theme-toggle,
.logout-button {
  display: inline-flex;
  align-items: center;
  gap: 0.6rem;
  border-radius: 999px;
  padding: 0.55rem 0.85rem;
  font-weight: 700;
  cursor: pointer;
}

.theme-toggle {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
}

.theme-toggle__track {
  position: relative;
  width: 2.8rem;
  height: 1.55rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  border: 1px solid var(--color-border);
}

.theme-toggle__thumb {
  position: absolute;
  top: 1px;
  left: 1px;
  width: 1.2rem;
  height: 1.2rem;
  border-radius: 50%;
  background: var(--color-accent-strong);
  transition: transform 0.2s ease;
}

.theme-toggle.is-dark .theme-toggle__thumb {
  transform: translateX(1.2rem);
}

.theme-toggle__label {
  min-width: 3.5rem;
  text-align: left;
}

.logout-button {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
}

.content {
  display: grid;
  gap: 1rem;
}

@media (min-width: 900px) {
  .topbar {
    align-items: end;
    justify-content: space-between;
    flex-direction: row;
  }
}
</style>
