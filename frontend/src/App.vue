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
    <aside class="sidebar">
      <div class="sidebar-header">
        <h1>Finanz<br />Cockpit</h1>
      </div>

      <nav class="sidebar-nav">
        <RouterLink to="/">Dashboard</RouterLink>
        <RouterLink to="/transactions">Buchungen</RouterLink>
        <RouterLink to="/categories">Kategorien</RouterLink>
        <RouterLink to="/analysis">Auswertung</RouterLink>
        <RouterLink to="/imports">Import</RouterLink>
        <RouterLink to="/help">Hilfe</RouterLink>
      </nav>

      <div class="sidebar-footer">
        <div class="theme-section">
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

        <div v-if="!authStore.isAuthenticated" class="sidebar-auth">
          <RouterLink to="/login" class="auth-link">Login</RouterLink>
        </div>
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
      </div>
    </aside>

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
  display: flex;
  min-height: 100vh;
  width: min(100%, 1440px);
  margin: 0 auto;
  padding: 0;
}

.sidebar {
  display: flex;
  flex-direction: column;
  width: 180px;
  min-height: 100vh;
  padding: 1.5rem 1rem;
  border-right: 1px solid var(--color-border);
  background: var(--color-surface);
}

.sidebar-header {
  margin-bottom: 2rem;
}

.sidebar-header h1 {
  margin: 0;
  font-size: 1.2rem;
  color: var(--color-heading);
  text-align: center;
  line-height: 1.2;
}

.sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.sidebar-nav a {
  display: block;
  padding: 0.6rem 0.75rem;
  border-radius: 10px;
  background: transparent;
  color: var(--color-text);
  text-decoration: none;
  font-weight: 500;
  font-size: 0.9rem;
  transition: all 0.2s ease;
  text-align: center;
}

.sidebar-nav a:hover {
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
}

.sidebar-nav a.router-link-exact-active {
  background: var(--color-accent-strong);
  color: #fff;
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.24);
}

.theme-section {
  display: flex;
  justify-content: center;
}

.theme-toggle {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  border-radius: 10px;
  padding: 0.5rem 0.75rem;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  transition: all 0.2s ease;
  width: 100%;
}

.theme-toggle:hover {
  background: var(--color-accent-soft);
}

.theme-toggle__track {
  position: relative;
  width: 2.4rem;
  height: 1.4rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
}

.theme-toggle__thumb {
  position: absolute;
  top: 1px;
  left: 1px;
  width: 1.1rem;
  height: 1.1rem;
  border-radius: 50%;
  background: var(--color-accent-strong);
  transition: transform 0.2s ease;
}

.theme-toggle.is-dark .theme-toggle__thumb {
  transform: translateX(1.2rem);
}

.theme-toggle__label {
  min-width: 3rem;
  text-align: left;
  font-size: 0.8rem;
}

.sidebar-footer {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-top: 1rem;
}

.sidebar-auth {
  text-align: center;
}

.auth-link {
  display: inline-block;
  padding: 0.6rem 1rem;
  border-radius: 12px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  text-decoration: none;
  font-weight: 600;
  transition: all 0.2s ease;
}

.auth-link:hover {
  background: var(--color-accent-strong);
  color: #fff;
}

.content {
  flex: 1;
  padding: 1.5rem clamp(1rem, 2vw, 2rem);
  overflow-y: auto;
}

.user-menu {
  position: relative;
}

.nav-action {
  display: block;
  width: 100%;
  padding: 0.6rem 1rem;
  border-radius: 12px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  border: 1px solid transparent;
  font-weight: 600;
  cursor: pointer;
  text-align: center;
  transition: all 0.2s ease;
}

.nav-action:hover {
  background: var(--color-accent-strong);
  color: #fff;
}

.user-menu__trigger {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
}

.user-menu__chevron {
  font-size: 0.8rem;
}

.user-menu__dropdown {
  position: absolute;
  bottom: calc(100% + 0.5rem);
  left: 0;
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

.theme-toggle {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  border-radius: 12px;
  padding: 0.55rem 0.85rem;
  font-weight: 700;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  transition: all 0.2s ease;
}

.theme-toggle:hover {
  background: var(--color-accent-soft);
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
