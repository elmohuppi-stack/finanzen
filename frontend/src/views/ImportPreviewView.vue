<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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

interface ImportRunResponse {
  message: string
  import: {
    id: number
    source_type: string
    file_name: string
    status: string
    imported_rows: number
    skipped_rows: number
    error_rows: number
    started_at: string | null
    finished_at: string | null
    account: {
      id: number | null
      name: string | null
      account_type: string | null
    }
  }
}

interface ImportHistoryResponse {
  imports: Array<{
    id: number
    source_type: string
    file_name: string
    status: string
    imported_rows: number
    skipped_rows: number
    error_rows: number
    imported_at: string | null
    period_from: string | null
    period_to: string | null
    account_name: string | null
    account_type: string | null
  }>
}

const authStore = useAuthStore()
const selectedFile = ref<File | null>(null)
const fileInputKey = ref(0)
const preview = ref<ImportPreviewResponse | null>(null)
const importResult = ref<ImportRunResponse['import'] | null>(null)
const importHistory = ref<ImportHistoryResponse['imports']>([])
const loading = ref(false)
const importLoading = ref(false)
const historyLoading = ref(false)
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

const importResultMessage = computed(() => {
  if (!importResult.value) {
    return ''
  }

  if (importResult.value.imported_rows === 0 && importResult.value.skipped_rows > 0) {
    return 'Es wurden keine neuen Umsätze gefunden – die Datei war bereits importiert.'
  }

  return 'Der Import wurde gespeichert und im Verlauf protokolliert.'
})

function formatDateTime(value: string | null) {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function formatPeriod(from: string | null, to: string | null) {
  if (!from && !to) {
    return '—'
  }

  if (from && to) {
    return `${from} bis ${to}`
  }

  return from ?? to ?? '—'
}

function formatSourceType(sourceType: string) {
  switch (sourceType) {
    case 'dkb_giro':
      return 'DKB Giro'
    case 'dkb_visa':
      return 'DKB Visa'
    case 'paypal':
      return 'PayPal'
    default:
      return sourceType
  }
}

function resetFlow() {
  selectedFile.value = null
  preview.value = null
  importResult.value = null
  error.value = ''
  fileInputKey.value += 1
}

function onFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  selectedFile.value = target.files?.[0] ?? null
  preview.value = null
  importResult.value = null
  error.value = ''
}

async function loadImportHistory() {
  if (!authStore.token) {
    importHistory.value = []
    return
  }

  historyLoading.value = true

  try {
    const response = await apiFetch<ImportHistoryResponse>('/api/imports', {}, authStore.token)
    importHistory.value = response.imports
  } catch (err) {
    error.value =
      err instanceof Error ? err.message : 'Der Import-Verlauf konnte nicht geladen werden.'
  } finally {
    historyLoading.value = false
  }
}

async function submit() {
  if (!selectedFile.value || !authStore.token) {
    error.value = 'Bitte zuerst anmelden und eine CSV-Datei auswählen.'
    return
  }

  loading.value = true
  importResult.value = null
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

async function startImport() {
  if (!selectedFile.value || !authStore.token) {
    error.value = 'Bitte zuerst anmelden und eine CSV-Datei auswählen.'
    return
  }

  if (!preview.value) {
    error.value = 'Bitte zuerst die Vorschau laden.'
    return
  }

  importLoading.value = true
  error.value = ''

  try {
    const formData = new FormData()
    formData.append('file', selectedFile.value)

    const response = await apiFetch<ImportRunResponse>(
      '/api/imports',
      {
        method: 'POST',
        body: formData,
      },
      authStore.token,
    )

    importResult.value = response.import
    preview.value = null
    selectedFile.value = null
    fileInputKey.value += 1
    await loadImportHistory()
  } catch (err) {
    error.value =
      err instanceof Error ? err.message : 'Der Import konnte nicht abgeschlossen werden.'
  } finally {
    importLoading.value = false
  }
}

watch(
  () => authStore.token,
  async (token) => {
    if (!token) {
      importHistory.value = []
      return
    }

    await loadImportHistory()
  },
  { immediate: true },
)
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
        <input :key="fileInputKey" type="file" accept=".csv,.CSV,.txt" @change="onFileChange" />
        <button type="button" :disabled="loading || importLoading || !selectedFile" @click="submit">
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

    <article v-if="preview" class="card next-step">
      <h3>Nächster Schritt: importieren</h3>
      <p>
        Wenn die Vorschau passt, kannst du die Datei jetzt wirklich in die Datenbank übernehmen.
        Bereits bekannte Umsätze werden dabei duplicate-safe übersprungen.
      </p>
      <button type="button" :disabled="importLoading || !selectedFile" @click="startImport">
        {{ importLoading ? 'Importiere…' : 'Import jetzt speichern' }}
      </button>
    </article>

    <article v-if="importResult" class="card success">
      <h3>Import abgeschlossen</h3>
      <p>{{ importResultMessage }}</p>
      <ul class="meta-list">
        <li><strong>Konto:</strong> {{ importResult.account.name ?? 'Automatisch erkannt' }}</li>
        <li><strong>Status:</strong> {{ importResult.status }}</li>
        <li><strong>Neu importiert:</strong> {{ importResult.imported_rows }}</li>
        <li><strong>Übersprungen:</strong> {{ importResult.skipped_rows }}</li>
        <li><strong>Fehler:</strong> {{ importResult.error_rows }}</li>
      </ul>

      <div class="action-row">
        <button type="button" class="secondary-button" @click="resetFlow">
          Weitere Datei importieren
        </button>
        <RouterLink class="link-button" to="/">Zum Dashboard</RouterLink>
      </div>
    </article>

    <article v-if="authStore.isAuthenticated" class="card">
      <div class="section-header">
        <div>
          <h3>Import-Verlauf</h3>
          <p class="muted">Welche CSV wann importiert wurde und welchen Zeitraum sie abdeckt.</p>
        </div>
      </div>

      <p v-if="historyLoading" class="muted">Lade Import-Verlauf…</p>

      <div v-else-if="importHistory.length" class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Importiert am</th>
              <th>Quelle</th>
              <th>Datei</th>
              <th>Konto</th>
              <th>Zeitraum</th>
              <th>Ergebnis</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in importHistory" :key="entry.id">
              <td>{{ formatDateTime(entry.imported_at) }}</td>
              <td>{{ formatSourceType(entry.source_type) }}</td>
              <td>{{ entry.file_name }}</td>
              <td>{{ entry.account_name || '—' }}</td>
              <td>{{ formatPeriod(entry.period_from, entry.period_to) }}</td>
              <td>
                {{ entry.imported_rows }} neu · {{ entry.skipped_rows }} übersprungen ·
                {{ entry.error_rows }} Fehler
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="muted">Noch keine CSV-Importe vorhanden.</p>
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

.next-step {
  border-style: dashed;
}

.success {
  border-color: rgba(5, 150, 105, 0.35);
  background: rgba(5, 150, 105, 0.08);
}

.section-header h3 {
  margin-bottom: 0.25rem;
}

.action-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 1rem;
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

.secondary-button {
  background: var(--color-background-mute);
  color: var(--color-text);
}

button:disabled {
  opacity: 0.7;
  cursor: not-allowed;
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
