<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

type AnalysisMode = 'calendar' | 'budget'

interface DashboardResponse {
  summary: {
    account_count: number
    transaction_count: number
    income: string
    expenses: string
    net: string
  }
  filters: {
    selected_view: 'month' | 'year' | 'range' | 'all'
    selected_mode: AnalysisMode
    selected_month: string | null
    selected_year: number | null
    selected_date_from: string | null
    selected_date_to: string | null
    selected_account_id: number | null
    search_query: string
    available_months: string[]
    available_years: number[]
  }
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
    amount: string
    cashflow_amount: string
    currency: string
    direction: string
    category_id: number | null
    category_name: string | null
    counterparty_name: string | null
    description: string | null
    account_name: string | null
  }>
}

type CategoryDirection = 'income' | 'expense'

type CategoryTotalEntry = {
  key: string
  label: string
  total: number
  count: number
  type: CategoryDirection
}

const authStore = useAuthStore()
const dashboard = ref<DashboardResponse | null>(null)
const loading = ref(false)
const error = ref('')
const viewMode = ref<'month' | 'year' | 'range' | 'all'>('month')
const analysisMode = ref<AnalysisMode>('calendar')
const selectedMonth = ref(new Date().toISOString().slice(0, 7))
const selectedYear = ref(new Date().getFullYear())
const selectedDateFrom = ref('')
const selectedDateTo = ref('')
const selectedCategory = ref<string | null>(null)

const availableMonths = computed(() => dashboard.value?.filters.available_months ?? [])
const availableYears = computed(() => dashboard.value?.filters.available_years ?? [])

function buildCategoryTotals(type: CategoryDirection) {
  const totals = new Map<string, CategoryTotalEntry>()

  for (const transaction of dashboard.value?.transactions ?? []) {
    const amount = Number(transaction.cashflow_amount ?? transaction.amount)

    if ((type === 'income' && amount <= 0) || (type === 'expense' && amount >= 0)) {
      continue
    }

    const label = transaction.category_name || 'Unkategorisiert'
    const key = `${type}:${label}`
    const entry = totals.get(key) ?? { key, label, total: 0, count: 0, type }
    entry.total += Math.abs(amount)
    entry.count += 1
    totals.set(key, entry)
  }

  return Array.from(totals.values()).sort((left, right) => right.total - left.total)
}

const incomeCategoryTotals = computed(() => buildCategoryTotals('income'))
const expenseCategoryTotals = computed(() => buildCategoryTotals('expense'))
const allCategoryTotals = computed(() => [
  ...incomeCategoryTotals.value,
  ...expenseCategoryTotals.value,
])
const maxIncomeCategoryTotal = computed(() => incomeCategoryTotals.value[0]?.total ?? 1)
const maxExpenseCategoryTotal = computed(() => expenseCategoryTotals.value[0]?.total ?? 1)
const selectedCategoryEntry = computed(
  () => allCategoryTotals.value.find((entry) => entry.key === selectedCategory.value) ?? null,
)
const selectedCategoryTotal = computed(() => selectedCategoryEntry.value?.total ?? 0)
const selectedCategoryTransactions = computed(() => {
  const entry = selectedCategoryEntry.value

  if (!entry) {
    return []
  }

  return (dashboard.value?.transactions ?? [])
    .filter((transaction) => {
      const amount = Number(transaction.cashflow_amount ?? transaction.amount)
      const label = transaction.category_name || 'Unkategorisiert'

      return (
        label === entry.label &&
        ((entry.type === 'income' && amount > 0) || (entry.type === 'expense' && amount < 0))
      )
    })
    .sort((left, right) =>
      `${right.booking_date ?? ''}-${right.id}`.localeCompare(
        `${left.booking_date ?? ''}-${left.id}`,
      ),
    )
})

function formatMoney(amount: number | string, currency = 'EUR') {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency,
  }).format(Number(amount))
}

function formatDate(date: string | null) {
  if (!date) {
    return 'Ohne Datum'
  }

  return new Intl.DateTimeFormat('de-DE').format(new Date(date))
}

function toggleCategory(key: string) {
  selectedCategory.value = selectedCategory.value === key ? null : key
}

async function setAnalysisMode(mode: AnalysisMode) {
  if (analysisMode.value === mode) {
    return
  }

  analysisMode.value = mode
  await loadAnalysis()
}

async function loadAnalysis() {
  if (!authStore.token) {
    dashboard.value = null
    return
  }

  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams({
      view: viewMode.value,
      mode: analysisMode.value,
    })

    if (viewMode.value === 'month' && selectedMonth.value) {
      params.set('month', selectedMonth.value)
    } else if (viewMode.value === 'year' && selectedYear.value) {
      params.set('year', selectedYear.value.toString())
    } else if (viewMode.value === 'range' && selectedDateFrom.value && selectedDateTo.value) {
      params.set('date_from', selectedDateFrom.value)
      params.set('date_to', selectedDateTo.value)
    }

    const response = await apiFetch<DashboardResponse>(
      `/api/dashboard?${params.toString()}`,
      {},
      authStore.token,
    )

    dashboard.value = response
    analysisMode.value = response.filters.selected_mode ?? analysisMode.value
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Auswertung konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function showMonthAnalysis() {
  viewMode.value = 'month'
  await loadAnalysis()
}

async function showYearAnalysis() {
  viewMode.value = 'year'
  await loadAnalysis()
}

async function showRangeAnalysis() {
  viewMode.value = 'range'
  await loadAnalysis()
}

async function showAllAnalysis() {
  viewMode.value = 'all'
  await loadAnalysis()
}

function setLast30Days() {
  const today = new Date()
  const thirtyDaysAgo = new Date(today)
  thirtyDaysAgo.setDate(today.getDate() - 30)
  selectedDateFrom.value = thirtyDaysAgo.toISOString().slice(0, 10)
  selectedDateTo.value = today.toISOString().slice(0, 10)
  showRangeAnalysis()
}

function setThisQuarter() {
  const today = new Date()
  const quarterStart = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1)
  selectedDateFrom.value = quarterStart.toISOString().slice(0, 10)
  selectedDateTo.value = today.toISOString().slice(0, 10)
  showRangeAnalysis()
}

function navigateMonth(direction: number) {
  const date = new Date(selectedMonth.value + '-01')
  date.setMonth(date.getMonth() + direction)
  selectedMonth.value = date.toISOString().slice(0, 7)
  loadAnalysis()
}

function navigateYear(direction: number) {
  selectedYear.value += direction
  loadAnalysis()
}

watch(allCategoryTotals, (entries) => {
  if (!selectedCategory.value) {
    return
  }

  if (!entries.some((entry) => entry.key === selectedCategory.value)) {
    selectedCategory.value = null
  }
})

watch(
  () => authStore.token,
  async (token) => {
    if (!token) {
      dashboard.value = null
      return
    }

    await loadAnalysis()
  },
  { immediate: true },
)
</script>

<template>
  <section class="stack">
    <article class="card compact-header">
      <div class="header-toolbar">
        <div class="toolbar">
          <div class="primary-filters">
            <button
              class="filter-button"
              :class="{ active: viewMode === 'month' }"
              @click="showMonthAnalysis"
            >
              Monat
            </button>
            <button
              class="filter-button"
              :class="{ active: viewMode === 'year' }"
              @click="showYearAnalysis"
            >
              Jahr
            </button>
            <button
              class="filter-button"
              :class="{ active: viewMode === 'range' }"
              @click="showRangeAnalysis"
            >
              Bereich
            </button>
            <button
              class="filter-button"
              :class="{ active: viewMode === 'all' }"
              @click="showAllAnalysis"
            >
              Alle
            </button>
          </div>

          <div class="mode-toggle-group">
            <button
              class="filter-button"
              :class="{ active: analysisMode === 'calendar' }"
              @click="setAnalysisMode('calendar')"
            >
              Kalendermonat
            </button>
            <div class="mode-info-wrap">
              <button
                class="filter-button"
                :class="{ active: analysisMode === 'budget' }"
                @click="setAnalysisMode('budget')"
              >
                Budgetmonat
              </button>
              <span
                v-if="analysisMode === 'budget'"
                class="info-badge"
                tabindex="0"
                aria-label="Info zum Budgetmonat"
              >
                i
                <span class="info-tooltip">
                  Budgetmonat ordnet wiederkehrende Einnahmen und Wohnen-Buchungen ab dem 25. dem
                  Folgemonat zu.
                </span>
              </span>
            </div>
          </div>

          <div v-if="viewMode === 'month'" class="date-controls">
            <button class="nav-button" @click="navigateMonth(-1)">‹</button>
            <select v-model="selectedMonth" @change="loadAnalysis">
              <option v-for="month in availableMonths" :key="month" :value="month">
                {{ month }}
              </option>
            </select>
            <button class="nav-button" @click="navigateMonth(1)">›</button>
          </div>

          <div v-if="viewMode === 'year'" class="date-controls">
            <button class="nav-button" @click="navigateYear(-1)">‹</button>
            <select v-model.number="selectedYear" @change="loadAnalysis">
              <option v-for="year in availableYears" :key="year" :value="year">{{ year }}</option>
            </select>
            <button class="nav-button" @click="navigateYear(1)">›</button>
          </div>

          <div v-if="viewMode === 'range'" class="date-controls">
            <button class="preset-button" @click="setLast30Days">30 Tage</button>
            <button class="preset-button" @click="setThisQuarter">Quartal</button>
            <div class="date-range-inputs">
              <input
                type="date"
                v-model="selectedDateFrom"
                @change="loadAnalysis"
                placeholder="Von"
              />
              <span>bis</span>
              <input
                type="date"
                v-model="selectedDateTo"
                @change="loadAnalysis"
                placeholder="Bis"
              />
            </div>
          </div>
        </div>

        <div class="stats">
          <div class="stat-item">
            <span class="stat-label">Einnahmen</span>
            <strong class="positive">{{ formatMoney(dashboard?.summary.income || 0) }}</strong>
          </div>
          <div class="stat-item">
            <span class="stat-label">Ausgaben</span>
            <strong class="negative">{{ formatMoney(dashboard?.summary.expenses || 0) }}</strong>
          </div>
          <div class="stat-item">
            <span class="stat-label">Netto</span>
            <strong :class="Number(dashboard?.summary.net || 0) >= 0 ? 'positive' : 'negative'">
              {{ formatMoney(dashboard?.summary.net || 0) }}
            </strong>
          </div>
        </div>
      </div>
    </article>

    <article v-if="loading" class="card">
      <p>Lade Auswertung…</p>
    </article>

    <article v-else-if="error" class="card warning">
      <p>{{ error }}</p>
    </article>

    <template v-else-if="dashboard">
      <article class="card">
        <div class="analysis-layout">
          <section class="analysis-categories analysis-panel">
            <div class="section-header section-header--compact">
              <div>
                <p class="eyebrow">Kategorien</p>
                <h3>Einnahmen und Ausgaben nach Kategorie</h3>
              </div>
            </div>

            <div class="category-grid">
              <div class="category-group">
                <h4 class="category-group__title positive">Einnahmen</h4>
                <div v-if="incomeCategoryTotals.length" class="bar-list scroll-list">
                  <button
                    v-for="entry in incomeCategoryTotals"
                    :key="entry.key"
                    type="button"
                    class="bar-row bar-row--button"
                    :class="{ active: entry.key === selectedCategory }"
                    @click="toggleCategory(entry.key)"
                  >
                    <div class="bar-row__header">
                      <span>{{ entry.label }}</span>
                      <strong>{{ formatMoney(entry.total) }}</strong>
                    </div>
                    <div class="bar-track">
                      <div
                        class="bar-fill bar-fill--income"
                        :style="{ width: `${(entry.total / maxIncomeCategoryTotal) * 100}%` }"
                      />
                    </div>
                    <small class="bar-row__meta">{{ entry.count }} Buchungen</small>
                  </button>
                </div>
                <p v-else class="muted">
                  Für den gewählten Zeitraum liegen noch keine kategorisierten Einnahmen vor.
                </p>
              </div>

              <div class="category-group">
                <h4 class="category-group__title negative">Ausgaben</h4>
                <div v-if="expenseCategoryTotals.length" class="bar-list scroll-list">
                  <button
                    v-for="entry in expenseCategoryTotals"
                    :key="entry.key"
                    type="button"
                    class="bar-row bar-row--button"
                    :class="{ active: entry.key === selectedCategory }"
                    @click="toggleCategory(entry.key)"
                  >
                    <div class="bar-row__header">
                      <span>{{ entry.label }}</span>
                      <strong>{{ formatMoney(entry.total) }}</strong>
                    </div>
                    <div class="bar-track">
                      <div
                        class="bar-fill bar-fill--expense"
                        :style="{ width: `${(entry.total / maxExpenseCategoryTotal) * 100}%` }"
                      />
                    </div>
                    <small class="bar-row__meta">{{ entry.count }} Buchungen</small>
                  </button>
                </div>
                <p v-else class="muted">
                  Für den gewählten Zeitraum liegen noch keine kategorisierten Ausgaben vor.
                </p>
              </div>
            </div>
          </section>

          <section class="analysis-detail analysis-panel">
            <div class="section-header section-header--compact">
              <div>
                <p class="eyebrow">
                  {{
                    selectedCategoryEntry?.type === 'income'
                      ? 'Einnahmen'
                      : selectedCategoryEntry?.type === 'expense'
                        ? 'Ausgaben'
                        : 'Buchungen'
                  }}
                </p>
                <h3>{{ selectedCategoryEntry?.label || 'Kategorie auswählen' }}</h3>
              </div>
              <strong
                v-if="selectedCategoryEntry"
                :class="selectedCategoryEntry.type === 'income' ? 'positive' : 'negative'"
              >
                {{ formatMoney(selectedCategoryTotal) }}
              </strong>
            </div>

            <p v-if="!incomeCategoryTotals.length && !expenseCategoryTotals.length">
              Für den gewählten Zeitraum liegen noch keine kategorisierten Buchungen vor.
            </p>
            <p v-else-if="!selectedCategoryEntry" class="muted">
              Klicke links auf eine Kategorie wie `Gehalt`, `Wohnen` oder `Lebensmittel`, um die
              passenden Buchungen zu sehen.
            </p>
            <div v-else class="transaction-list scroll-list">
              <p class="muted">
                {{ selectedCategoryTransactions.length }} Buchungen im gewählten Zeitraum
              </p>

              <article
                v-for="transaction in selectedCategoryTransactions"
                :key="transaction.id"
                class="transaction-item"
              >
                <div>
                  <strong>
                    {{ transaction.counterparty_name || transaction.description || 'Buchung' }}
                  </strong>
                  <p class="muted">
                    {{ formatDate(transaction.booking_date) }}
                    <span v-if="transaction.account_name"> · {{ transaction.account_name }}</span>
                  </p>
                  <p
                    v-if="
                      transaction.description &&
                      transaction.description !== transaction.counterparty_name
                    "
                    class="muted"
                  >
                    {{ transaction.description }}
                  </p>
                </div>
                <strong
                  :class="
                    Number(transaction.cashflow_amount ?? transaction.amount) >= 0
                      ? 'positive'
                      : 'negative'
                  "
                >
                  {{
                    formatMoney(Math.abs(Number(transaction.cashflow_amount)), transaction.currency)
                  }}
                </strong>
              </article>
            </div>
          </section>
        </div>
      </article>
    </template>
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

.compact-header {
  padding: 1rem;
}

.hero {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.header-toolbar {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: space-between;
  align-items: flex-start;
}

.toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.75rem 1rem;
  align-items: start;
  flex: 1 1 640px;
  min-width: 0;
}

.primary-filters {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: center;
  min-width: 0;
}

.stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(102px, 1fr));
  gap: 0.55rem;
  align-items: stretch;
  flex: 0 0 auto;
}

.stat-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.2rem;
  min-width: 102px;
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

.stat-label {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.stat-item strong {
  font-size: 1.2rem;
}

.eyebrow {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.date-controls,
.mode-info-wrap,
.primary-filters {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.mode-toggle-group {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-wrap: nowrap;
}

.date-controls {
  grid-column: 1 / -1;
}

.mode-info-wrap {
  position: relative;
}

.date-range-inputs {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.date-range-inputs input {
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.5rem;
}

.nav-button,
.preset-button {
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.5rem 0.75rem;
  font-size: 0.9rem;
  cursor: pointer;
}

.nav-button:hover,
.preset-button:hover {
  background: var(--color-accent-soft);
}

.filter-button,
select {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.65rem 0.85rem;
}

.filter-button.active {
  background: var(--color-accent-strong);
  color: white;
}

.analysis-layout {
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(0, 1.05fr) minmax(0, 1.2fr);
  align-items: start;
}

.analysis-panel,
.bar-list,
.transaction-list {
  display: grid;
  gap: 0.85rem;
}

.analysis-panel {
  min-height: 0;
}

.category-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  align-items: start;
}

.category-group {
  display: grid;
  gap: 0.65rem;
  min-width: 0;
}

.category-group__title {
  margin: 0;
  font-size: 0.95rem;
}

.scroll-list {
  max-height: 40rem;
  overflow-y: auto;
  padding-right: 0.25rem;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
}

.section-header--compact {
  margin-bottom: 0.15rem;
}

.section-header--compact .eyebrow {
  margin-bottom: 0.1rem;
}

.section-header h3 {
  margin: 0;
  line-height: 1.15;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
}

.transaction-item {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

.transaction-item p {
  margin-top: 0.2rem;
}

.bar-list {
  display: grid;
  gap: 0.85rem;
}

.bar-row {
  display: grid;
  gap: 0.35rem;
}

.bar-row--button {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.bar-row--button:hover {
  background: var(--color-accent-soft);
}

.bar-row--button.active {
  border-color: var(--color-accent-strong);
  background: var(--color-accent-soft);
}

.bar-row__header {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.35rem;
}

.bar-row__meta {
  color: var(--color-text-muted);
}

.bar-track {
  height: 12px;
  border-radius: 999px;
  background: var(--color-background-mute);
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: inherit;
}

.bar-fill--income {
  background: linear-gradient(90deg, #059669, #22c55e);
}

.bar-fill--expense {
  background: linear-gradient(90deg, var(--color-accent-strong), #f97316);
}

.info-badge {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: var(--color-surface-strong);
  color: var(--color-accent-strong);
  font-size: 0.82rem;
  font-weight: 800;
  cursor: help;
}

.info-tooltip {
  position: absolute;
  top: calc(100% + 0.45rem);
  left: 50%;
  transform: translateX(-50%);
  width: min(18rem, 70vw);
  padding: 0.6rem 0.75rem;
  border-radius: 12px;
  background: var(--color-surface-strong);
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-elevated);
  color: var(--color-text);
  font-size: 0.8rem;
  line-height: 1.4;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity 0.15s ease;
  z-index: 10;
}

.info-badge:hover .info-tooltip,
.info-badge:focus-visible .info-tooltip {
  opacity: 1;
  visibility: visible;
}

.warning,
.negative {
  color: var(--color-danger);
}

.positive {
  color: #059669;
}

@media (max-width: 900px) {
  .header-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .toolbar {
    grid-template-columns: 1fr;
  }

  .toolbar,
  .stats {
    width: 100%;
  }

  .mode-toggle-group {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .stats {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .analysis-layout {
    grid-template-columns: 1fr;
  }

  .category-grid {
    grid-template-columns: 1fr;
  }

  .transaction-item {
    flex-direction: column;
  }
}
</style>
