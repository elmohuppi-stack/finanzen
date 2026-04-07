<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const router = useRouter()
const mode = ref<'login' | 'register'>('login')
const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

const form = reactive({
  name: '',
  email: '',
  password: '',
  passwordConfirmation: '',
})

const title = computed(() => (mode.value === 'login' ? 'Anmelden' : 'Registrieren'))
const buttonLabel = computed(() => {
  if (authStore.loading) {
    return mode.value === 'login' ? 'Anmeldung läuft…' : 'Registrierung läuft…'
  }

  return mode.value === 'login' ? 'Anmelden' : 'Konto erstellen'
})

function switchMode(nextMode: 'login' | 'register') {
  mode.value = nextMode
  authStore.clearError()

  form.name = ''
  form.email = ''
  form.password = ''
  form.passwordConfirmation = ''
}

async function submit() {
  try {
    if (mode.value === 'login') {
      await authStore.login(form.email, form.password)
    } else {
      await authStore.register(form.name, form.email, form.password, form.passwordConfirmation)
    }

    await router.push('/imports/preview')
  } catch {
    // store already exposes the error message
  }
}
</script>

<template>
  <section class="card login-card">
    <div>
      <p class="label">API-Zugang</p>
      <div class="mode-switch" role="tablist" aria-label="Authentifizierung auswählen">
        <button
          class="mode-switch__button"
          :class="{ active: mode === 'login' }"
          type="button"
          @click="switchMode('login')"
        >
          Login
        </button>
        <button
          class="mode-switch__button"
          :class="{ active: mode === 'register' }"
          type="button"
          @click="switchMode('register')"
        >
          Registrieren
        </button>
      </div>

      <h2>{{ title }}</h2>
      <p v-if="mode === 'login'" class="muted">
        Für den aktuellen lokalen Stand kannst du den Seed-User verwenden:
        <code>test@example.com</code> / <code>password</code>
      </p>
      <p v-else class="muted">
        Lege hier direkt ein neues lokales Benutzerkonto an. Nach erfolgreicher Registrierung wirst
        du automatisch eingeloggt.
      </p>
    </div>

    <form class="form" @submit.prevent="submit">
      <label v-if="mode === 'register'">
        <span>Name</span>
        <input v-model="form.name" type="text" autocomplete="name" required />
      </label>

      <label>
        <span>E-Mail</span>
        <input
          v-model="form.email"
          type="email"
          autocomplete="email"
          placeholder="test@example.com"
          required
        />
      </label>

      <label>
        <span>Passwort</span>
        <div class="password-field">
          <input
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            placeholder="password"
            required
          />
          <button
            class="password-toggle"
            type="button"
            :aria-label="showPassword ? 'Passwort verbergen' : 'Passwort anzeigen'"
            @click="showPassword = !showPassword"
          >
            {{ showPassword ? '🙈' : '👁️' }}
          </button>
        </div>
      </label>

      <label v-if="mode === 'register'">
        <span>Passwort wiederholen</span>
        <div class="password-field">
          <input
            v-model="form.passwordConfirmation"
            :type="showPasswordConfirmation ? 'text' : 'password'"
            autocomplete="new-password"
            placeholder="password"
            required
          />
          <button
            class="password-toggle"
            type="button"
            :aria-label="showPasswordConfirmation ? 'Passwort verbergen' : 'Passwort anzeigen'"
            @click="showPasswordConfirmation = !showPasswordConfirmation"
          >
            {{ showPasswordConfirmation ? '🙈' : '👁️' }}
          </button>
        </div>
      </label>

      <button type="submit" :disabled="authStore.loading">
        {{ buttonLabel }}
      </button>
    </form>

    <p v-if="authStore.error" class="error">{{ authStore.error }}</p>

    <p class="legal-note">
      Rechtliche Hinweise:
      <RouterLink to="/impressum">Impressum</RouterLink>
      ·
      <RouterLink to="/datenschutz">Datenschutz</RouterLink>
    </p>
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

.login-card {
  max-width: 620px;
}

.label {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.mode-switch {
  display: inline-flex;
  gap: 0.35rem;
  padding: 0.25rem;
  margin-bottom: 1rem;
  border-radius: 999px;
  background: var(--color-background-mute);
}

.mode-switch__button {
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-muted);
  padding: 0.55rem 0.9rem;
  font-weight: 700;
}

.mode-switch__button.active {
  background: var(--color-surface-strong);
  color: var(--color-heading);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}

.form {
  display: grid;
  gap: 1rem;
  margin-top: 1rem;
}

label {
  display: grid;
  gap: 0.35rem;
}

.password-field {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.5rem;
  align-items: center;
}

input {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 0.75rem 0.85rem;
  background: var(--color-surface-strong);
}

.password-toggle {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface-strong);
  min-width: 2.9rem;
  height: 2.9rem;
  font-size: 1rem;
  cursor: pointer;
}

button[type='submit'] {
  border: 0;
  border-radius: 12px;
  background: var(--color-accent-strong);
  color: white;
  padding: 0.8rem 1rem;
  font-weight: 700;
  cursor: pointer;
}

button[type='submit']:disabled {
  opacity: 0.7;
  cursor: progress;
}

.muted {
  color: var(--color-text-muted);
}

.error {
  color: var(--color-danger);
  font-weight: 600;
}

.legal-note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.92rem;
}

.legal-note a {
  color: var(--color-accent-strong);
  text-decoration: none;
}
</style>
