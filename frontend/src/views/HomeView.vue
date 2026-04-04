<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

interface DashboardResponse {
  summary: {
    account_count: number
    transaction_count: number
    income: string
    expenses: string
    net: string
  }
  filters: {
    selected_view: 'month' | 'all'
    selected_month: string | null
    selected_account_id: number | null
    search_query: string
    available_months: string[]
  }
  accounts: Array<{
    id: number
    name: string
    account_type: string
    institution: string | null
    currency: string
    transaction_count: number
    booked_balance: string
  }>
  categories: Array<{
    id: number
    name: string
    category_type: string
    color: string | null
    is_system: boolean
  }>
  transactions: Array<{
    id: number
    booking_date: string | null
    value_date: string | null
    counterparty_name: string | null
    description: string | null
    amount: string
    currency: string
    direction: string
    source_system: string
    account_name: string | null
    category_id: number | null
    category_name: string | null
    category_color: string | null
  }>
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
const dashboard = ref<DashboardResponse | null>(null)
const loading = ref(false)
const error = ref('')
const viewMode = ref<'month' | 'all'>('month')
const selectedMonth = ref(new Date().toISOString().slice(0, 7))
const searchQuery = ref('')
const selectedAccountId = ref('')
const categoryDrafts = ref<Record<number, string>>({})
const savingTransactionId = ref<number | null>(null)

const welcomeName = computed(() => (authStore.user?.name ? `, ${authStore.user.name}` : ''))
const availableMonths = computed(() => dashboard.value?.filters.available_months ?? [])
const currentMonthIndex = computed(() => availableMonths.value.indexOf(selectedMonth.value))
const canGoToNewerMonth = computed(() => currentMonthIndex.value > 0)
const canGoToOlderMonth = computed(
  () => currentMonthIndex.value >= 0 && currentMonthIndex.value < availableMonths.value.length - 1,
)
const periodLabel = computed(() => {
  if (viewMode.value === 'all') {
    return 'Alle Umsätze'
  }

  const monthValue = selectedMonth.value

  if (!monthValue || !/^\d{4}-\d{2}$/.test(monthValue)) {
    return 'Monat'
  }

  const [yearPart, monthPart] = monthValue.split('-')
  const year = Number(yearPart)
  const month = Number(monthPart)

  return new Intl.DateTimeFormat('de-DE', {
    month: 'long',
    year: 'numeric',
  }).format(new Date(year, month - 1, 1))
})

function formatMoney(amount: string, currency = 'EUR') {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency,
  }).format(Number(amount))
}

function formatDate(value: string | null) {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('de-DE').format(new Date(value))
}

function formatDateTime(value: string | null) {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
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

function formatPeriod(from: string | null, to: string | null) {
  if (!from && !to) {
    return '—'
  }

  if (from && to) {
    return `${from} bis ${to}`
  }

  return from ?? to ?? '—'
}

function syncCategoryDrafts(response: DashboardResponse) {
  categoryDrafts.value = Object.fromEntries(
    response.transactions.map((transaction) => [
      transaction.id,
      transaction.category_id ? String(transaction.category_id) : '',
    ]),
  )
}

async function loadDashboard() {
  if (!authStore.token) {
    dashboard.value = null
    return
  }

  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams({ view: viewMode.value })

    if (viewMode.value === 'month' && selectedMonth.value) {
      params.set('month', selectedMonth.value)
    }

    if (searchQuery.value.trim()) {
      params.set('query', searchQuery.value.trim())
    }

    if (selectedAccountId.value) {
      params.set('account_id', selectedAccountId.value)
    }

    const response = await apiFetch<DashboardResponse>(
      `/api/dashboard?${params.toString()}`,
      {},
      authStore.token,
    )
    dashboard.value = response
    syncCategoryDrafts(response)

    if (response.filters.selected_month) {
      selectedMonth.value = response.filters.selected_month
    }

    searchQuery.value = response.filters.search_query ?? ''
    selectedAccountId.value = response.filters.selected_account_id
      ? String(response.filters.selected_account_id)
      : ''
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Dashboard konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function showMonthlyView() {
  viewMode.value = 'month'

  if (!selectedMonth.value) {
    selectedMonth.value = availableMonths.value[0] ?? new Date().toISOString().slice(0, 7)
  }

  await loadDashboard()
}

async function showAllView() {
  viewMode.value = 'all'
  await loadDashboard()
}

async function applyTransactionFilters() {
  await loadDashboard()
}

async function resetTransactionFilters() {
  searchQuery.value = ''
  selectedAccountId.value = ''
  await loadDashboard()
}

async function saveCategory(transactionId: number) {
  if (!authStore.token) {
    return
  }

  savingTransactionId.value = transactionId
  error.value = ''

  try {
    const rawValue = categoryDrafts.value[transactionId] ?? ''

    await apiFetch(
      `/api/transactions/${transactionId}/category`,
      {
        method: 'PATCH',
        body: JSON.stringify({
          category_id: rawValue ? Number(rawValue) : null,
        }),
      },
      authStore.token,
    )

    await loadDashboard()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Kategorie konnte nicht gespeichert werden.'
  } finally {
    savingTransactionId.value = null
  }
}

async function goToNewerMonth() {
  if (!canGoToNewerMonth.value) {
    return
  }

  const nextMonth = availableMonths.value[currentMonthIndex.value - 1]

  if (!nextMonth) {
    return
  }

  selectedMonth.value = nextMonth
  await loadDashboard()
}

async function goToOlderMonth() {
  if (!canGoToOlderMonth.value) {
    return
  }

  const previousMonth = availableMonths.value[currentMonthIndex.value + 1]

  if (!previousMonth) {
    return
  }

  selectedMonth.value = previousMonth
  await loadDashboard()
}

watch(
  () => authStore.token,
  async (token) => {
    if (!token) {
      dashboard.value = null
      return
    }

    await loadDashboard()
  },
  { immediate: true },
)
</script>

<template>
  <section class="grid">
    <article class="hero card">
      <p class="label">{{ authStore.isAuthenticated ? 'Dein Überblick' : 'Aktueller Stand' }}</p>
      <h2 v-if="authStore.isAuthenticated">Willkommen zurück{{ welcomeName }}.</h2>
      <h2 v-else>Das Grundgerüst für die Finanz-App steht.</h2>
      <p v-if="authStore.isAuthenticated">
        Hier siehst du jetzt deine importierten Konten und die letzten Umsätze auf einen Blick.
      </p>
      <p v-else>
        Laravel und Vue sind eingerichtet. Melde dich an und importiere deine erste CSV-Datei, um
        echte Finanzdaten im Dashboard zu sehen.
      </p>

      <div class="hero-actions">
        <RouterLink
          class="link-button"
          :to="authStore.isAuthenticated ? '/imports/preview' : '/login'"
        >
          {{ authStore.isAuthenticated ? 'Neue CSV importieren' : 'Anmelden' }}
        </RouterLink>
      </div>
    </article>

    <template v-if="authStore.isAuthenticated">
      <article v-if="loading" class="card">
        <h3>Dashboard wird geladen…</h3>
      </article>

      <article v-else-if="error" class="card warning">
        <h3>Dashboard konnte nicht geladen werden</h3>
        <p>{{ error }}</p>
      </article>

      <template v-else-if="dashboard">
        <article class="card stat-card">
          <p class="label">Konten</p>
          <strong>{{ dashboard.summary.account_count }}</strong>
        </article>

        <article class="card stat-card">
          <p class="label">Buchungen</p>
          <strong>{{ dashboard.summary.transaction_count }}</strong>
        </article>

        <article class="card stat-card">
          <p class="label">Einnahmen</p>
          <strong class="positive">{{ formatMoney(dashboard.summary.income) }}</strong>
        </article>

        <article class="card stat-card">
          <p class="label">Ausgaben</p>
          <strong class="negative">{{ formatMoney(dashboard.summary.expenses) }}</strong>
        </article>

        <article class="card stat-card stat-card--wide">
          <p class="label">Netto</p>
          <strong :class="Number(dashboard.summary.net) >= 0 ? 'positive' : 'negative'">
            {{ formatMoney(dashboard.summary.net) }}
          </strong>
        </article>

        <article class="card">
          <h3>Konten</h3>
          <ul v-if="dashboard.accounts.length" class="account-list">
            <li v-for="account in dashboard.accounts" :key="account.id" class="account-row">
              <div>
                <strong>{{ account.name }}</strong>
                <p>
                  {{ account.institution || 'Ohne Institut' }} ·
                  {{ account.transaction_count }} Buchungen
                </p>
              </div>
              <span>{{ formatMoney(account.booked_balance, account.currency) }}</span>
            </li>
          </ul>
          <p v-else>Noch keine Konten vorhanden.</p>
        </article>

        <article class="card transactions-card">
          <div class="section-header">
            <div>
              <h3>Umsätze</h3>
              <p class="muted">Zeitraum: {{ periodLabel }}</p>
            </div>
            <div class="toolbar">
              <button
                type="button"
                class="filter-button"
                :class="{ 'is-active': viewMode === 'month' }"
                @click="showMonthlyView"
              >
                Monat
              </button>
              <button
                type="button"
                class="filter-button"
                :class="{ 'is-active': viewMode === 'all' }"
                @click="showAllView"
              >
                Alle
              </button>
              <RouterLink class="text-link" to="/imports/preview">Weiter importieren</RouterLink>
            </div>
          </div>

          <form class="filters-form" @submit.prevent="applyTransactionFilters">
            <label class="filter-field">
              <span>Suche</span>
              <input
                v-model="searchQuery"
                type="search"
                placeholder="Gegenstelle oder Beschreibung"
              />
            </label>

            <label class="filter-field">
              <span>Konto</span>
              <select v-model="selectedAccountId">
                <option value="">Alle Konten</option>
                <option
                  v-for="account in dashboard.accounts"
                  :key="account.id"
                  :value="String(account.id)"
                >
                  {{ account.name }}
                </option>
              </select>
            </label>

            <div class="filter-actions">
              <button type="submit" class="filter-button is-active">Filter anwenden</button>
              <button type="button" class="secondary-button" @click="resetTransactionFilters">
                Zurücksetzen
              </button>
            </div>
          </form>

          <div v-if="viewMode === 'month' && availableMonths.length" class="month-nav">
            <button
              type="button"
              class="secondary-button"
              :disabled="!canGoToNewerMonth"
              @click="goToNewerMonth"
            >
              ← Neuer
            </button>
            <strong>{{ periodLabel }}</strong>
            <button
              type="button"
              class="secondary-button"
              :disabled="!canGoToOlderMonth"
              @click="goToOlderMonth"
            >
              Älter →
            </button>
          </div>

          <div v-if="dashboard.transactions.length" class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Datum</th>
                  <th>Gegenstelle</th>
                  <th>Beschreibung</th>
                  <th>Konto</th>
                  <th>Kategorie</th>
                  <th>Betrag</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="transaction in dashboard.transactions" :key="transaction.id">
                  <td>{{ formatDate(transaction.booking_date) }}</td>
                  <td>{{ transaction.counterparty_name || '—' }}</td>
                  <td>{{ transaction.description || '—' }}</td>
                  <td>{{ transaction.account_name || '—' }}</td>
                  <td>
                    <div class="category-editor">
                      <select v-model="categoryDrafts[transaction.id]" class="category-select">
                        <option value="">Ohne Kategorie</option>
                        <option
                          v-for="category in dashboard.categories"
                          :key="category.id"
                          :value="String(category.id)"
                        >
                          {{ category.name }}
                        </option>
                      </select>
                      <button
                        type="button"
                        class="secondary-button compact-button"
                        :disabled="savingTransactionId === transaction.id"
                        @click="saveCategory(transaction.id)"
                      >
                        {{ savingTransactionId === transaction.id ? 'Speichert…' : 'Speichern' }}
                      </button>
                    </div>
                  </td>
                  <td :class="transaction.direction === 'credit' ? 'positive' : 'negative'">
                    {{ formatMoney(transaction.amount, transaction.currency) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else>Für diesen Zeitraum sind noch keine Umsätze vorhanden.</p>
        </article>

        <article class="card transactions-card">
          <div class="section-header">
            <div>
              <h3>CSV-Importe</h3>
              <p class="muted">Wann du welche Datei für welchen Zeitraum importiert hast.</p>
            </div>
            <RouterLink class="text-link" to="/imports/preview">Neuen Import starten</RouterLink>
          </div>

          <div v-if="dashboard.imports.length" class="table-wrapper">
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
                <tr v-for="entry in dashboard.imports" :key="entry.id">
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
          <p v-else>Noch keine CSV-Importe vorhanden.</p>
        </article>
      </template>
    </template>

    <template v-else>
      <article class="card">
        <h3>Datenquellen</h3>
        <ul>
          <li>Girokonto</li>
          <li>Visa 1 &amp; Visa 2</li>
          <li>PayPal</li>
          <li>Bargeld-Wallet</li>
        </ul>
      </article>

      <article class="card">
        <h3>MVP-Funktionen</h3>
        <ul>
          <li>CSV-Import mit Dubletten-Schutz</li>
          <li>Importierte Umsätze im Dashboard</li>
          <li>Visa-Abrechnung als Transfer statt Blackbox</li>
          <li>Splitbare Buchungen mit Kategorien</li>
        </ul>
      </article>
    </template>
  </section>
</template>

<style scoped>
.grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.card {
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
  backdrop-filter: blur(10px);
}

.hero {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.hero-actions {
  margin-top: 1rem;
}

.link-button,
.text-link {
  text-decoration: none;
}

.link-button {
  display: inline-flex;
  align-items: center;
  border-radius: 12px;
  background: var(--color-accent-strong);
  color: white;
  padding: 0.75rem 0.95rem;
  font-weight: 700;
}

.text-link {
  color: var(--color-accent-strong);
  font-weight: 700;
}

.toolbar {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.filters-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.filter-field {
  display: grid;
  gap: 0.35rem;
}

.filter-field span {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

.filter-field input,
.filter-field select {
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.7rem 0.85rem;
}

.filter-actions {
  display: flex;
  gap: 0.5rem;
  align-items: end;
  flex-wrap: wrap;
}

.category-editor {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.category-select {
  min-width: 180px;
}

.compact-button {
  padding: 0.45rem 0.75rem;
  font-size: 0.9rem;
}

.filter-button,
.secondary-button {
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.55rem 0.85rem;
  font-weight: 700;
  cursor: pointer;
}

.filter-button.is-active {
  background: var(--color-accent-strong);
  color: white;
  border-color: transparent;
}

.secondary-button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.label {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--color-accent-strong);
}

h2,
h3 {
  margin-top: 0;
}

p,
ul {
  color: var(--color-text);
}

ul {
  padding-left: 1.1rem;
  margin-bottom: 0;
}

li::marker {
  color: var(--color-accent-strong);
}

.stat-card strong {
  font-size: 1.8rem;
}

.stat-card--wide {
  grid-column: span 2;
}

.account-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.account-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
  padding: 0.8rem 0;
  border-bottom: 1px solid var(--color-border);
}

.account-row:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

.account-row p {
  margin: 0.2rem 0 0;
  color: var(--color-text-muted);
}

.transactions-card {
  grid-column: 1 / -1;
}

.section-header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
  flex-wrap: wrap;
}

.month-nav {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 1rem;
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
  padding: 0.7rem;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
  vertical-align: top;
}

.warning {
  border-color: var(--color-warning);
  background: var(--color-warning-soft);
}

.muted {
  color: var(--color-text-muted);
}

.positive {
  color: #059669;
}

.negative {
  color: var(--color-danger);
}

@media (max-width: 720px) {
  .stat-card--wide {
    grid-column: auto;
  }

  .account-row {
    align-items: start;
    flex-direction: column;
  }
}

@media (min-width: 900px) {
  .hero {
    grid-column: 1 / -1;
  }
}
</style>
