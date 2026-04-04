<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

interface ImportPreviewResponse {
  detected_type: string
  file_name: string
  file_hash: string
  line_count: number
  delimiter: string
  header_row_index: number | null
  headers: string[]
  sample_rows: Array<Record<string, string>>
}

const authStore = useAuthStore()
const selectedFile = ref<File | null>(null)
const preview = ref<ImportPreviewResponse | null>(null)
const loading = ref(false)
const error = ref('')

const detectedLabel = computed(() => {
  switch (preview.value?.detected_type) {
    case 'dkb_giro':
      return 'DKB Girokonto'
    case 'dkb_visa':
      return 'DKB Visa'
    case 'paypal':
      return 'PayPal'
    default:
      return 'Unbekannt'
  }
})

function onFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  selectedFile.value = target.files?.[0] ?? null
  preview.value = null
  error.value = ''
}

async function submit() {
  if (!selectedFile.value || !authStore.token) {
    error.value = 'Bitte zuerst anmelden und eine CSV-Datei auswählen.'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const formData = new FormData()
    formData.append('file', selectedFile.value)

    preview.value = await apiFetch<ImportPreviewResponse>(
      '/api/imports/detect',
      {
        method: 'POST',
        body: formData,
      },
      authStore.token,
    )
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Die Vorschau konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="stack">
    <article class="card">
      <p class="label">CSV Import</p>
      <h2>Vorschau vor dem eigentlichen Import</h2>
      <p>
        Hier wird zuerst nur erkannt, welches Format vorliegt und welche Kopfzeilen und
        Beispielzeilen daraus gelesen werden.
      </p>
    </article>

    <article v-if="!authStore.isAuthenticated" class="card warning">
      <h3>Anmeldung erforderlich</h3>
      <p>Für die API-Vorschau brauchst du ein gültiges Login.</p>
      <RouterLink class="link-button" to="/login">Zum Login</RouterLink>
    </article>

    <article v-else class="card">
      <h3>Datei auswählen</h3>

      <div class="form-row">
        <input type="file" accept=".csv,.CSV,.txt" @change="onFileChange" />
        <button type="button" :disabled="loading || !selectedFile" @click="submit">
          {{ loading ? 'Lade Vorschau…' : 'Vorschau laden' }}
        </button>
      </div>

      <p v-if="authStore.user" class="muted">
        Angemeldet als <strong>{{ authStore.user.name }}</strong>
      </p>
      <p v-if="error" class="error">{{ error }}</p>
    </article>

    <article v-if="preview" class="card">
      <h3>Erkanntes Format: {{ detectedLabel }}</h3>
      <ul class="meta-list">
        <li><strong>Datei:</strong> {{ preview.file_name }}</li>
        <li><strong>Zeilen:</strong> {{ preview.line_count }}</li>
        <li>
          <strong>Trennzeichen:</strong> <code>{{ preview.delimiter }}</code>
        </li>
        <li><strong>Header-Zeile:</strong> {{ preview.header_row_index ?? 'nicht gefunden' }}</li>
      </ul>

      <h4>Kopfzeilen</h4>
      <div class="chips">
        <span v-for="header in preview.headers" :key="header" class="chip">{{ header }}</span>
      </div>

      <h4>Beispielzeilen</h4>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th v-for="header in preview.headers" :key="header">{{ header }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in preview.sample_rows" :key="index">
              <td v-for="header in preview.headers" :key="`${index}-${header}`">
                {{ row[header] }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
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

.label {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.warning {
  border-color: var(--color-warning);
  background: var(--color-warning-soft);
}

.form-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: center;
}

input[type='file'] {
  max-width: 100%;
}

button,
.link-button {
  border: 0;
  border-radius: 12px;
  background: var(--color-accent-strong);
  color: white;
  padding: 0.75rem 0.95rem;
  font-weight: 700;
  cursor: pointer;
  text-decoration: none;
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

.meta-list {
  padding-left: 1.1rem;
}

.chips {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.chip {
  padding: 0.3rem 0.55rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  font-size: 0.9rem;
}

.table-wrapper {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.65rem;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
  vertical-align: top;
}

th {
  color: var(--color-heading);
}
</style>
