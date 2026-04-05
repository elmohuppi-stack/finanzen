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
  }
  filters: {
    selected_view: 'month' | 'all'
    selected_month: string | null
    selected_account_id: number | null
    search_query: string
    available_months: string[]
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
  }>
}

const authStore = useAuthStore()
const dashboard = ref<DashboardResponse | null>(null)
const loading = ref(false)
const error = ref('')
const viewMode = ref<'month' | 'all'>('month')
const selectedMonth = ref(new Date().toISOString().slice(0, 7))

const availableMonths = computed(() => dashboard.value?.filters.available_months ?? [])
const categoryTotals = computed(() => {
  const totals = new Map<string, { label: string; total: number }>()

  for (const transaction of dashboard.value?.transactions ?? []) {
    const amount = Number(transaction.cashflow_amount ?? transaction.amount)

    if (amount >= 0) {
      continue
    }

    const key = transaction.category_name || 'Unkategorisiert'
    const entry = totals.get(key) ?? { label: key, total: 0 }
    entry.total += Math.abs(amount)
    totals.set(key, entry)
  }

  return Array.from(totals.values()).sort((left, right) => right.total - left.total)
})

const maxCategoryTotal = computed(() => categoryTotals.value[0]?.total ?? 1)

function formatMoney(amount: number | string, currency = 'EUR') {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency,
  }).format(Number(amount))
}

async function loadAnalysis() {
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

    dashboard.value = await apiFetch<DashboardResponse>(
      `/api/dashboard?${params.toString()}`,
      {},
      authStore.token,
    )
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

async function showAllAnalysis() {
  viewMode.value = 'all'
  await loadAnalysis()
}

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
    <article class="card hero">
      <p class="eyebrow">Auswertung</p>
      <h2>Kategorien und Zeiträume analysieren</h2>
      <p>
        Hier bündeln wir Ausgaben nach Kategorien und machen Trends über Monat und Gesamtzeitraum
        sichtbar.
      </p>

      <div class="toolbar">
        <button
          class="filter-button"
          :class="{ active: viewMode === 'month' }"
          @click="showMonthAnalysis"
        >
          Monat
        </button>
        <button
          class="filter-button"
          :class="{ active: viewMode === 'all' }"
          @click="showAllAnalysis"
        >
          Alle
        </button>
        <select v-if="viewMode === 'month'" v-model="selectedMonth" @change="loadAnalysis">
          <option v-for="month in availableMonths" :key="month" :value="month">{{ month }}</option>
        </select>
      </div>
    </article>

    <article v-if="loading" class="card">
      <p>Lade Auswertung…</p>
    </article>

    <article v-else-if="error" class="card warning">
      <p>{{ error }}</p>
    </article>

    <template v-else-if="dashboard">
      <section class="stats">
        <article class="card stat-card">
          <p class="eyebrow">Einnahmen</p>
          <strong class="positive">{{ formatMoney(dashboard.summary.income) }}</strong>
        </article>
        <article class="card stat-card">
          <p class="eyebrow">Ausgaben</p>
          <strong class="negative">{{ formatMoney(dashboard.summary.expenses) }}</strong>
        </article>
        <article class="card stat-card">
          <p class="eyebrow">Netto</p>
          <strong :class="Number(dashboard.summary.net) >= 0 ? 'positive' : 'negative'">
            {{ formatMoney(dashboard.summary.net) }}
          </strong>
        </article>
      </section>

      <article class="card">
        <h3>Ausgaben nach Kategorie</h3>
        <div v-if="categoryTotals.length" class="bar-list">
          <div v-for="entry in categoryTotals" :key="entry.label" class="bar-row">
            <div class="bar-row__header">
              <span>{{ entry.label }}</span>
              <strong>{{ formatMoney(entry.total) }}</strong>
            </div>
            <div class="bar-track">
              <div
                class="bar-fill"
                :style="{ width: `${(entry.total / maxCategoryTotal) * 100}%` }"
              />
            </div>
          </div>
        </div>
        <p v-else>Für den gewählten Zeitraum liegen noch keine kategorisierten Ausgaben vor.</p>
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

.hero {
  background: linear-gradient(135deg, var(--color-accent-soft), var(--color-surface));
}

.eyebrow {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.toolbar,
.stats {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
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

.stat-card {
  flex: 1 1 220px;
}

.stat-card strong {
  font-size: 1.6rem;
}

.bar-list {
  display: grid;
  gap: 0.85rem;
}

.bar-row__header {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.35rem;
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
  background: linear-gradient(90deg, var(--color-accent-strong), #22c55e);
}

.warning,
.negative {
  color: var(--color-danger);
}

.positive {
  color: #059669;
}
</style>
