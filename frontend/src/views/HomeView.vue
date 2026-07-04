<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

interface DashboardResponse {
  summary: {
    account_count: number
    transaction_count: number
    income: string
    expenses: string
    net: string
    total_balance: string
    balance_as_of: string | null
    balance_year: number
  }
  filters: {
    selected_year: number
    available_years: number[]
  }
  accounts: Array<{
    id: number
    name: string
    account_type: string
    institution: string | null
    currency: string
    transaction_count: number
    booked_balance: string
    current_balance: string
    balance_as_of: string | null
    statement_period_from: string | null
    statement_period_to: string | null
  }>
  transactions: Array<{
    id: number
    booking_date: string | null
    amount: string
    currency: string
    direction: string
    counterparty_name: string | null
    description: string | null
    category_id: number | null
    category_name: string | null
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
  }>
  monthly_balances: Array<{
    month: string
    label: string
    income: string
    expenses: string
    net: string
    opening_balance: string | null
    closing_balance: string | null
    min_balance: string | null
    max_balance: string | null
  }>
  balance_history: Array<{
    date: string
    before: string
    after: string
  }>
}

interface BalanceHistoryEntry {
  date: string
  before: string
  after: string
}

const authStore = useAuthStore()
const dashboard = ref<DashboardResponse | null>(null)
const loading = ref(false)
const chartLoading = ref(false)
const chartError = ref('')
const error = ref('')
const fallbackYear = new Date().getFullYear()
const selectedYear = ref<number | null>(null)
const balanceHistory = ref<BalanceHistoryEntry[]>([])

const welcomeName = computed(() => (authStore.user?.name ? `, ${authStore.user.name}` : ''))
const totalBalance = computed(() => {
  return (dashboard.value?.accounts ?? [])
    .filter((account) => showsPrimaryBalance(account.account_type))
    .reduce((sum, account) => sum + Number(account.current_balance), 0)
})
const totalBalanceDate = computed(() => {
  const dates = (dashboard.value?.accounts ?? [])
    .filter((account) => showsPrimaryBalance(account.account_type) && account.balance_as_of)
    .map((account) => account.balance_as_of as string)

  return dates.sort().at(-1) ?? null
})
const availableYears = computed(() => {
  return dashboard.value?.filters.available_years?.length
    ? dashboard.value.filters.available_years
    : [fallbackYear]
})
const balanceYear = computed(() => {
  return selectedYear.value ?? dashboard.value?.filters.selected_year ?? fallbackYear
})
const uncategorizedCount = computed(() => {
  return (dashboard.value?.transactions ?? []).filter((transaction) => !transaction.category_id)
    .length
})
const primaryAccountsCount = computed(() => {
  return (dashboard.value?.accounts ?? []).filter((account) =>
    showsPrimaryBalance(account.account_type),
  ).length
})
const monthlyRows = computed(() => dashboard.value?.monthly_balances ?? [])
const hasMonthlyBalances = computed(() => monthlyRows.value.some((r) => r.opening_balance !== null))
const chartMonths = computed(() =>
  monthlyRows.value.filter((r) => r.min_balance !== null && r.max_balance !== null),
)
const chartLayout = {
  width: 1000,
  height: 240,
  left: 84,
  right: 968,
  top: 16,
  bottom: 186,
  barWidth: 30,
}
const allChartValues = computed(() =>
  chartMonths.value.flatMap((m) => [Number(m.min_balance), Number(m.max_balance)]),
)
const chartMinBalance = computed(() => {
  return allChartValues.value.length ? Math.min(...allChartValues.value) : 0
})
const chartMaxBalance = computed(() => {
  return allChartValues.value.length ? Math.max(...allChartValues.value) : 1
})
const chartRange = computed(() => Math.max(1, chartMaxBalance.value - chartMinBalance.value))
const chartPadding = computed(() => Math.max(250, chartRange.value * 0.12))
const plottedMinBalance = computed(() => chartMinBalance.value - chartPadding.value)
const plottedMaxBalance = computed(() => chartMaxBalance.value + chartPadding.value)
const plottedRange = computed(() => Math.max(1, plottedMaxBalance.value - plottedMinBalance.value))
const chartTicks = computed(() =>
  Array.from({ length: 5 }, (_, index) => {
    const ratio = index / 4
    const value = plottedMaxBalance.value - ratio * plottedRange.value
    const y = chartLayout.top + ratio * (chartLayout.bottom - chartLayout.top)
    return {
      label: formatAxisMoney(value),
      y: Number(y.toFixed(2)),
    }
  }),
)
const barPositions = computed(() =>
  chartMonths.value.map((m, index) => {
    const count = chartMonths.value.length
    const x =
      count === 1
        ? (chartLayout.left + chartLayout.right) / 2
        : chartLayout.left + (index / (count - 1)) * (chartLayout.right - chartLayout.left)
    const minVal = Number(m.min_balance)
    const maxVal = Number(m.max_balance)
    const openVal = Number(m.opening_balance)
    const minY =
      chartLayout.bottom -
      ((minVal - plottedMinBalance.value) / plottedRange.value) *
        (chartLayout.bottom - chartLayout.top)
    const maxY =
      chartLayout.bottom -
      ((maxVal - plottedMinBalance.value) / plottedRange.value) *
        (chartLayout.bottom - chartLayout.top)
    const openY =
      chartLayout.bottom -
      ((openVal - plottedMinBalance.value) / plottedRange.value) *
        (chartLayout.bottom - chartLayout.top)
    return {
      key: m.month,
      label: m.label.slice(0, 3),
      x: Number(x.toFixed(2)),
      minY: Number(minY.toFixed(2)),
      maxY: Number(maxY.toFixed(2)),
      openY: Number(openY.toFixed(2)),
    }
  }),
)

function formatMoney(amount: number | string, currency = 'EUR') {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency,
  }).format(Number(amount))
}

function formatAxisMoney(amount: number | string) {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatDate(value: string | null) {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
  }).format(new Date(value))
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

function formatPeriod(from: string | null, to: string | null) {
  if (!from && !to) {
    return '—'
  }

  if (from && to) {
    return `${from} bis ${to}`
  }

  return from ?? to ?? '—'
}

function formatAccountType(accountType: string) {
  switch (accountType) {
    case 'checking_account':
      return 'Girokonto'
    case 'credit_card':
      return 'Kreditkarte'
    case 'paypal_account':
      return 'PayPal'
    case 'cash_wallet':
      return 'Bargeld'
    case 'savings_account':
      return 'Tagesgeld'
    default:
      return accountType
  }
}

function showsPrimaryBalance(accountType: string) {
  return accountType === 'checking_account' || accountType === 'cash_wallet'
}

async function changeBalanceYear() {
  await loadDashboardOverview()
}

function navigateYear(direction: number) {
  const currentYear = selectedYear.value ?? balanceYear.value
  selectedYear.value = currentYear + direction
  loadDashboardOverview()
}

async function loadBalanceHistory() {
  if (!authStore.token) {
    balanceHistory.value = []
    chartError.value = ''
    return
  }

  chartLoading.value = true
  chartError.value = ''

  try {
    const params = new URLSearchParams()
    if (selectedYear.value) {
      params.set('year', String(selectedYear.value))
    }

    const data = await apiFetch<BalanceHistoryEntry[]>(
      `/api/dashboard/balance-history?${params.toString()}`,
      {},
      authStore.token,
    )

    balanceHistory.value = data
  } catch (err) {
    console.error('Balance history load failed:', err)
    chartError.value = err instanceof Error ? err.message : 'Fehler beim Laden'
    balanceHistory.value = []
  } finally {
    chartLoading.value = false
  }
}

async function loadDashboardOverview() {
  if (!authStore.token) {
    dashboard.value = null
    return
  }

  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams({ view: 'all' })

    if (selectedYear.value) {
      params.set('year', String(selectedYear.value))
    }

    const response = await apiFetch<DashboardResponse>(
      `/api/dashboard?${params.toString()}`,
      {},
      authStore.token,
    )

    dashboard.value = response
    selectedYear.value = response.filters.selected_year

    // Chart-Daten parallel (oder kurz nach dem Dashboard) laden
    loadBalanceHistory()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Dashboard konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

watch(
  () => authStore.token,
  async (token) => {
    if (!token) {
      dashboard.value = null
      return
    }

    await loadDashboardOverview()
  },
  { immediate: true },
)
</script>

<template>
  <section class="dashboard-stack">
    <article class="hero card">
      <div class="hero-copy">
        <p class="label">Dashboard</p>
        <h2 v-if="authStore.isAuthenticated">Guter Überblick{{ welcomeName }}.</h2>
        <h2 v-else>Dein Finanzcockpit für Konten, Buchungen und Auswertung.</h2>
        <p v-if="authStore.isAuthenticated">
          Hier siehst du die wichtigsten Kennzahlen, Kontostände und die Entwicklung im laufenden
          Jahr auf einen Blick.
        </p>
        <p v-else>
          Melde dich an und importiere deine ersten CSV-Dateien, um dein persönliches Dashboard zu
          füllen.
        </p>
      </div>

      <div v-if="authStore.isAuthenticated && dashboard" class="hero-highlight">
        <span class="hero-highlight__label">Primärer Kontostand</span>
        <strong :class="totalBalance >= 0 ? 'positive' : 'negative'">{{
          formatMoney(totalBalance)
        }}</strong>
        <span>
          {{
            totalBalanceDate
              ? `Stand ${formatDate(totalBalanceDate)}`
              : 'Wird aus importierten Buchungen abgeleitet'
          }}
        </span>
      </div>
    </article>

    <article v-if="loading && !dashboard" class="card">
      <p>Dashboard wird geladen…</p>
    </article>

    <article v-else-if="error && !dashboard" class="card warning">
      <p>{{ error }}</p>
    </article>

    <template v-else-if="authStore.isAuthenticated && dashboard">
      <article class="card section-card">
        <div class="section-header">
          <div>
            <h3>Saldoentwicklung {{ balanceYear }}</h3>
            <p class="muted">mit Minimum und Maximum</p>
          </div>
          <div class="section-actions">
            <label class="year-picker">
              <span>Jahr</span>
              <button class="nav-button" :disabled="loading" @click="navigateYear(-1)">‹</button>
              <select v-model.number="selectedYear" :disabled="loading" @change="changeBalanceYear">
                <option v-for="year in availableYears" :key="year" :value="year">
                  {{ year }}
                </option>
              </select>
              <button class="nav-button" :disabled="loading" @click="navigateYear(1)">›</button>
            </label>
          </div>
        </div>

        <p v-if="chartLoading" class="muted inline-status">Lade Saldoverlauf…</p>

        <p v-else-if="chartError" class="warning inline-status">Saldo-Chart: {{ chartError }}</p>

        <div v-else-if="chartMonths.length" class="balance-visual">
          <svg
            class="range-chart"
            :viewBox="`0 0 ${chartLayout.width} ${chartLayout.height}`"
            preserveAspectRatio="none"
            aria-hidden="true"
          >
            <rect
              :x="chartLayout.left"
              :y="chartLayout.top"
              :width="chartLayout.right - chartLayout.left"
              :height="chartLayout.bottom - chartLayout.top"
              class="chart-surface"
            />

            <g v-for="tick in chartTicks" :key="tick.label">
              <line
                :x1="chartLayout.left"
                :y1="tick.y"
                :x2="chartLayout.right"
                :y2="tick.y"
                class="chart-grid"
              />
              <text :x="chartLayout.left - 10" :y="tick.y + 4" class="chart-tick" text-anchor="end">
                {{ tick.label }}
              </text>
            </g>

            <line
              :x1="chartLayout.left"
              :y1="chartLayout.top"
              :x2="chartLayout.left"
              :y2="chartLayout.bottom"
              class="chart-axis"
            />
            <line
              :x1="chartLayout.left"
              :y1="chartLayout.bottom"
              :x2="chartLayout.right"
              :y2="chartLayout.bottom"
              class="chart-axis"
            />

            <!-- Range-Balken (min → max) pro Monat -->
            <rect
              v-for="bar in barPositions"
              :key="bar.key"
              :x="bar.x - chartLayout.barWidth / 2"
              :y="bar.maxY"
              :width="chartLayout.barWidth"
              :height="Math.max(1, bar.minY - bar.maxY)"
              class="chart-bar"
              rx="3"
              ry="3"
            />

            <!-- Eröffnungspunkt -->
            <circle
              v-for="bar in barPositions"
              :key="`${bar.key}-open`"
              :cx="bar.x"
              :cy="bar.openY"
              r="4.5"
              class="chart-open"
            />

            <!-- Monats-Labels -->
            <text
              v-for="bar in barPositions"
              :key="`${bar.key}-label`"
              :x="bar.x"
              :y="chartLayout.bottom + 24"
              class="chart-x-label"
              text-anchor="middle"
            >
              {{ bar.label }}
            </text>
          </svg>
        </div>

        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Monat</th>
                <th>Anfang</th>
                <th>Einnahmen</th>
                <th>Ausgaben</th>
                <th>Netto</th>
                <th>Minimum</th>
                <th>Maximum</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in monthlyRows" :key="row.month">
                <td>{{ row.label }}</td>
                <td>{{ row.opening_balance ? formatMoney(row.opening_balance) : '—' }}</td>
                <td class="positive">{{ formatMoney(row.income) }}</td>
                <td class="negative">{{ formatMoney(row.expenses) }}</td>
                <td :class="Number(row.net) >= 0 ? 'positive' : 'negative'">
                  {{ formatMoney(row.net) }}
                </td>
                <td>{{ row.min_balance ? formatMoney(row.min_balance) : '—' }}</td>
                <td>{{ row.max_balance ? formatMoney(row.max_balance) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>
  </section>
</template>

<style scoped>
.dashboard-stack {
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

.hero {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(220px, 0.8fr);
  gap: 1rem;
  align-items: center;
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.hero-copy {
  display: grid;
  gap: 0.5rem;
}

.hero-highlight {
  display: grid;
  gap: 0.35rem;
  padding: 1rem 1.1rem;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-surface-strong);
}

.hero-highlight__label {
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-accent-strong);
}

.hero-highlight strong {
  font-size: 2rem;
  line-height: 1.1;
}

.hero-highlight span:last-child {
  color: var(--color-text-muted);
}

.label {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.stats-grid,
.content-grid {
  display: grid;
  gap: 1rem;
}

.stats-grid {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.content-grid {
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.content-grid--focus {
  grid-template-columns: minmax(0, 1fr);
}

.section-card {
  border-color: var(--color-border-hover);
}

.compact-card {
  padding-top: 1rem;
}

.mini-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
}

.mini-stat {
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

.mini-stat span {
  display: block;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
}

.mini-stat strong {
  display: block;
  margin-top: 0.2rem;
  font-size: 1.35rem;
}

.stat-card {
  position: relative;
  overflow: hidden;
  background: linear-gradient(180deg, var(--color-surface-strong), var(--color-surface));
}

.stat-card::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 3px;
  background: var(--color-accent-strong);
  opacity: 0.85;
}

.stat-card--featured {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface-strong));
}

.accounts-table td:first-child strong {
  display: inline-block;
  min-width: 10rem;
}

.account-name-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.account-badge,
.type-pill {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 0.2rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 700;
}

.account-badge {
  border: 1px solid var(--color-border);
  background: var(--color-surface-strong);
  color: var(--color-text-muted);
}

.account-badge.is-primary,
.type-pill {
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
}

.accounts-table .is-primary-row td {
  background: var(--color-background-soft);
}

.table-note,
.stat-note {
  margin: 0.35rem 0 0;
  font-size: 0.9rem;
}

.balance-visual {
  display: grid;
  gap: 0.65rem;
  margin-bottom: 1rem;
}

.range-chart {
  width: 100%;
  height: 230px;
  display: block;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

.chart-surface {
  fill: color-mix(in srgb, var(--color-background-soft) 72%, transparent);
}

.chart-grid {
  stroke: var(--color-border);
  stroke-width: 1;
}

.chart-axis {
  stroke: var(--color-border-hover);
  stroke-width: 1.3;
}

.chart-tick,
.chart-x-label,
.muted {
  fill: var(--color-text-muted);
  color: var(--color-text-muted);
}

.chart-tick {
  font-size: 10px;
}

.chart-x-label {
  font-size: 11px;
}

.chart-bar {
  fill: var(--color-accent-soft);
  stroke: var(--color-accent-strong);
  stroke-width: 1.5;
}

.chart-open {
  fill: var(--color-accent-strong);
  stroke: var(--color-surface-strong);
  stroke-width: 2;
}

.inline-status {
  margin-bottom: 0.75rem;
  font-weight: 600;
}

.muted {
  color: var(--color-text-muted);
}

.section-header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: start;
  flex-wrap: wrap;
  margin-bottom: 0.75rem;
}

.section-actions {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}

.year-picker {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.year-picker span {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-right: 0.15rem;
}

.year-picker select {
  min-width: 6rem;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-surface-strong);
  padding: 0.45rem 0.5rem;
  text-align: center;
}

.year-picker .nav-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  font-size: 1.1rem;
  cursor: pointer;
  transition: background 0.15s;
}

.year-picker .nav-button:hover:not(:disabled) {
  background: var(--color-accent-soft);
}

.year-picker .nav-button:disabled {
  opacity: 0.4;
  cursor: default;
}

.table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.78rem 0.9rem;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
}

th {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
  background: var(--color-background-soft);
}

tbody tr:hover td {
  background: var(--color-background-soft);
}

.warning,
.negative {
  color: var(--color-danger);
}

.positive {
  color: #059669;
}

.stat-card strong {
  font-size: 1.8rem;
}

@media (max-width: 820px) {
  .hero {
    grid-template-columns: 1fr;
  }

  .hero-highlight strong {
    font-size: 1.6rem;
  }

  .range-chart {
    height: 180px;
  }
}
</style>
