<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const theme = ref<'light' | 'dark'>('light')
const isDark = computed(() => theme.value === 'dark')
const isUserMenuOpen = ref(false)
const showLogoutConfirm = ref(false)

function applyTheme(value: 'light' | 'dark') {
  document.documentElement.setAttribute('data-theme', value)
  window.localStorage.setItem('finanzen-theme', value)
}

function toggleTheme() {
  theme.value = isDark.value ? 'light' : 'dark'
}

function toggleUserMenu() {
  isUserMenuOpen.value = !isUserMenuOpen.value
}

function requestLogout() {
  isUserMenuOpen.value = false
  showLogoutConfirm.value = true
}

function closeLogoutConfirm() {
  showLogoutConfirm.value = false
}

async function confirmLogout() {
  await authStore.logout()
  showLogoutConfirm.value = false
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
          <RouterLink v-if="!authStore.isAuthenticated" to="/login">Login</RouterLink>
          <div v-else class="user-menu">
            <button class="nav-action user-menu__trigger" type="button" @click="toggleUserMenu">
              {{ authStore.user?.name || 'Kunde' }}
              <span class="user-menu__chevron">▾</span>
            </button>

            <div v-if="isUserMenuOpen" class="user-menu__dropdown">
              <p class="user-menu__label">Angemeldet als</p>
              <strong>{{ authStore.user?.name }}</strong>
              <p class="user-menu__email">{{ authStore.user?.email }}</p>
              <button class="user-menu__logout" type="button" @click="requestLogout">Logout</button>
            </div>
          </div>
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
      </div>
    </header>

    <main class="content">
      <RouterView />
    </main>

    <div v-if="showLogoutConfirm" class="modal-overlay" @click.self="closeLogoutConfirm">
      <div class="modal-card">
        <h2>Wirklich abmelden?</h2>
        <p>Du wirst aus der aktuellen Sitzung ausgeloggt.</p>
        <div class="modal-actions">
          <button class="secondary-button" type="button" @click="closeLogoutConfirm">
            Abbrechen
          </button>
          <button class="danger-button" type="button" @click="confirmLogout">Logout</button>
        </div>
      </div>
    </div>
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

nav a,
.nav-action {
  padding: 0.6rem 0.95rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  border: 1px solid transparent;
  font-weight: 600;
}

.nav-action {
  cursor: pointer;
}

.user-menu {
  position: relative;
}

.user-menu__trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.user-menu__chevron {
  font-size: 0.8rem;
}

.user-menu__dropdown {
  position: absolute;
  top: calc(100% + 0.5rem);
  right: 0;
  min-width: 220px;
  padding: 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-surface-strong);
  box-shadow: var(--shadow-elevated);
  z-index: 10;
}

.user-menu__label {
  margin-bottom: 0.15rem;
  font-size: 0.78rem;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.user-menu__email {
  margin-top: 0.1rem;
  margin-bottom: 0.75rem;
  color: var(--color-text-muted);
  font-size: 0.92rem;
}

.user-menu__logout {
  width: 100%;
  border: 0;
  border-radius: 12px;
  background: var(--color-accent-strong);
  color: white;
  padding: 0.7rem 0.85rem;
  font-weight: 700;
  cursor: pointer;
}

nav a.router-link-exact-active {
  background: var(--color-accent-strong);
  color: #fff;
  box-shadow: 0 10px 24px rgba(79, 70, 229, 0.24);
}

.theme-toggle {
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

.content {
  display: grid;
  gap: 1rem;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.5);
  z-index: 30;
}

.modal-card {
  width: min(100%, 420px);
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface-strong);
  box-shadow: var(--shadow-elevated);
}

.modal-card p {
  margin-top: 0.5rem;
  color: var(--color-text-muted);
}

.modal-actions {
  display: flex;
  justify-content: end;
  gap: 0.75rem;
  margin-top: 1rem;
}

.secondary-button,
.danger-button {
  border: 0;
  border-radius: 12px;
  padding: 0.7rem 0.95rem;
  font-weight: 700;
  cursor: pointer;
}

.secondary-button {
  background: var(--color-background-mute);
  color: var(--color-text);
}

.danger-button {
  background: var(--color-danger);
  color: white;
}

@media (min-width: 900px) {
  .topbar {
    align-items: end;
    justify-content: space-between;
    flex-direction: row;
  }
}
</style>
