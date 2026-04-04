<script setup lang="ts">
import { reactive } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const router = useRouter()

const form = reactive({
  email: 'test@example.com',
  password: 'password',
})

async function submit() {
  try {
    await authStore.login(form.email, form.password)
    await router.push('/imports/preview')
  } catch {
    // store already exposes the error message
  }
}
</script>

<template>
  <section class="card login-card">
    <div>
      <p class="label">API-Login</p>
      <h2>Anmelden</h2>
      <p class="muted">
        Für den aktuellen lokalen Stand kannst du den Seed-User verwenden:
        <code>test@example.com</code> / <code>password</code>
      </p>
    </div>

    <form class="form" @submit.prevent="submit">
      <label>
        <span>E-Mail</span>
        <input v-model="form.email" type="email" autocomplete="email" required />
      </label>

      <label>
        <span>Passwort</span>
        <input v-model="form.password" type="password" autocomplete="current-password" required />
      </label>

      <button type="submit" :disabled="authStore.loading">
        {{ authStore.loading ? 'Anmeldung läuft…' : 'Anmelden' }}
      </button>
    </form>

    <p v-if="authStore.error" class="error">{{ authStore.error }}</p>
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

.form {
  display: grid;
  gap: 1rem;
  margin-top: 1rem;
}

label {
  display: grid;
  gap: 0.35rem;
}

input {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 0.75rem 0.85rem;
  background: var(--color-surface-strong);
}

button {
  border: 0;
  border-radius: 12px;
  background: var(--color-accent-strong);
  color: white;
  padding: 0.8rem 1rem;
  font-weight: 700;
  cursor: pointer;
}

button:disabled {
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
</style>
