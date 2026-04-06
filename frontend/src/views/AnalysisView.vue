<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

type AnalysisMode = 'calendar' | 'budget'
type MatchField = 'description' | 'counterparty' | 'both'

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

interface ApplyRulesResponse {
  summary: {
    matched_transactions: number
    updated_transactions: number
    skipped_manual_transactions: number
  }
}

type TransactionItem = DashboardResponse['transactions'][number]
type CategoryItem = DashboardResponse['categories'][number]
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
const successMessage = ref('')
const quickCategoryDrafts = ref<Record<number, string>>({})
const quickRulePatternDrafts = ref<Record<number, string>>({})
const quickRuleFieldDrafts = ref<Record<number, MatchField>>({})
const quickRulePreviewCounts = ref<Record<number, number>>({})
const savingCategoryTransactionId = ref<number | null>(null)
const previewingRuleTransactionId = ref<number | null>(null)
const savingRuleTransactionId = ref<number | null>(null)
const activeTransactionId = ref<number | null>(null)
const viewMode = ref<'month' | 'year' | 'range' | 'all'>('month')
const analysisMode = ref<AnalysisMode>('calendar')
const selectedMonth = ref(new Date().toISOString().slice(0, 7))
const selectedYear = ref(new Date().getFullYear())
const selectedDateFrom = ref('')
const selectedDateTo = ref('')
const selectedCategory = ref<string | null>(null)

const availableMonths = computed(() => dashboard.value?.filters.available_months ?? [])
const availableYears = computed(() => dashboard.value?.filters.available_years ?? [])
const sortedCategories = computed(() => {
  const getTypePriority = (category: CategoryItem) => {
    switch (category.category_type) {
      case 'expense':
        return 0
      case 'income':
        return 1
      default:
        return 2
    }
  }

  return [...(dashboard.value?.categories ?? [])].sort((left, right) => {
    return (
      getTypePriority(left) - getTypePriority(right) || left.name.localeCompare(right.name, 'de')
    )
  })
})

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
const isUncategorizedSelected = computed(
  () => selectedCategoryEntry.value?.label === 'Unkategorisiert',
)
const activeTransaction = computed<TransactionItem | null>(() => {
  if (activeTransactionId.value === null) {
    return null
  }

  return (
    (dashboard.value?.transactions ?? []).find(
      (transaction) => transaction.id === activeTransactionId.value,
    ) ?? null
  )
})
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

function formatCategoryTypeLabel(categoryType: string) {
  switch (categoryType) {
    case 'income':
      return 'Einnahme'
    case 'transfer':
      return 'Transfer'
    default:
      return 'Ausgabe'
  }
}

function getSuggestedRulePattern(transaction: TransactionItem, matchField?: MatchField) {
  const counterparty = (transaction.counterparty_name ?? '').replace(/\s+/g, ' ').trim()
  const description = (transaction.description ?? '').replace(/\s+/g, ' ').trim()

  if (matchField === 'description') {
    return description || counterparty
  }

  if (matchField === 'counterparty') {
    return counterparty || description
  }

  return counterparty || description
}

function clearRulePreview(transactionId: number) {
  delete quickRulePreviewCounts.value[transactionId]
}

function setSuggestedRuleField(transaction: TransactionItem, matchField: MatchField) {
  quickRuleFieldDrafts.value[transaction.id] = matchField
  quickRulePatternDrafts.value[transaction.id] = getSuggestedRulePattern(transaction, matchField)
  clearRulePreview(transaction.id)
}

function ensureQuickActionDrafts(transactions: TransactionItem[]) {
  for (const transaction of transactions) {
    if (!(transaction.id in quickCategoryDrafts.value)) {
      quickCategoryDrafts.value[transaction.id] = transaction.category_id
        ? String(transaction.category_id)
        : ''
    }

    if (!(transaction.id in quickRuleFieldDrafts.value)) {
      quickRuleFieldDrafts.value[transaction.id] = transaction.counterparty_name
        ? 'counterparty'
        : 'description'
    }

    if (!(transaction.id in quickRulePatternDrafts.value)) {
      quickRulePatternDrafts.value[transaction.id] = getSuggestedRulePattern(
        transaction,
        quickRuleFieldDrafts.value[transaction.id],
      )
    }
  }
}

function openTransactionModal(transaction: TransactionItem) {
  ensureQuickActionDrafts([transaction])
  error.value = ''
  activeTransactionId.value = transaction.id
}

function closeTransactionModal() {
  activeTransactionId.value = null
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

async function saveTransactionCategory(transaction: TransactionItem) {
  if (!authStore.token) {
    return
  }

  savingCategoryTransactionId.value = transaction.id
  error.value = ''
  successMessage.value = ''

  try {
    await apiFetch(
      `/api/transactions/${transaction.id}/category`,
      {
        method: 'PATCH',
        body: JSON.stringify({
          category_id: quickCategoryDrafts.value[transaction.id]
            ? Number(quickCategoryDrafts.value[transaction.id])
            : null,
        }),
      },
      authStore.token,
    )

    successMessage.value = 'Kategorie gespeichert.'
    await loadAnalysis()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Kategorie konnte nicht gespeichert werden.'
  } finally {
    savingCategoryTransactionId.value = null
  }
}

async function previewTransactionRule(transaction: TransactionItem) {
  if (!authStore.token) {
    return
  }

  const categoryId = quickCategoryDrafts.value[transaction.id]
  const pattern = quickRulePatternDrafts.value[transaction.id]?.trim() ?? ''

  if (!categoryId) {
    error.value = 'Bitte zuerst eine Kategorie auswählen.'
    return
  }

  if (!pattern) {
    error.value = 'Bitte zuerst einen Regeltext angeben.'
    return
  }

  previewingRuleTransactionId.value = transaction.id
  error.value = ''
  successMessage.value = ''

  try {
    const response = await apiFetch<{ summary: { matched_transactions: number } }>(
      '/api/category-rules/preview',
      {
        method: 'POST',
        body: JSON.stringify({
          category_id: Number(categoryId),
          pattern,
          match_field: quickRuleFieldDrafts.value[transaction.id] ?? 'both',
          match_type: 'contains',
        }),
      },
      authStore.token,
    )

    quickRulePreviewCounts.value[transaction.id] = response.summary.matched_transactions
    successMessage.value = `Regeltest: ${response.summary.matched_transactions} Treffer.`
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regel konnte nicht getestet werden.'
  } finally {
    previewingRuleTransactionId.value = null
  }
}

async function saveTransactionRule(transaction: TransactionItem) {
  if (!authStore.token) {
    return
  }

  const categoryId = quickCategoryDrafts.value[transaction.id]
  const pattern = quickRulePatternDrafts.value[transaction.id]?.trim() ?? ''

  if (!categoryId) {
    error.value = 'Bitte zuerst eine Kategorie auswählen.'
    return
  }

  if (!pattern) {
    error.value = 'Bitte zuerst einen Regeltext angeben.'
    return
  }

  savingRuleTransactionId.value = transaction.id
  error.value = ''
  successMessage.value = ''

  try {
    await apiFetch(
      '/api/category-rules',
      {
        method: 'POST',
        body: JSON.stringify({
          category_id: Number(categoryId),
          name: null,
          pattern,
          match_field: quickRuleFieldDrafts.value[transaction.id] ?? 'both',
          match_type: 'contains',
          priority: 100,
          is_active: true,
        }),
      },
      authStore.token,
    )

    const response = await apiFetch<ApplyRulesResponse>(
      '/api/category-rules/apply',
      { method: 'POST' },
      authStore.token,
    )

    successMessage.value = `Regel gespeichert. ${response.summary.updated_transactions} Buchungen wurden aktualisiert.`
    await loadAnalysis()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regel konnte nicht gespeichert werden.'
  } finally {
    savingRuleTransactionId.value = null
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
  selectedCategoryTransactions,
  (transactions) => {
    ensureQuickActionDrafts(transactions)
  },
  { immediate: true },
)

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

    <article v-else-if="error && !dashboard" class="card warning">
      <p>{{ error }}</p>
    </article>

    <template v-else-if="dashboard">
      <article v-if="error" class="card warning">
        <p>{{ error }}</p>
      </article>

      <article v-if="successMessage" class="card success-banner">
        <p>{{ successMessage }}</p>
      </article>

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

              <div v-if="isUncategorizedSelected" class="quick-tip">
                <strong>Unkategorisierte Buchungen kompakt bereinigen</strong>
                <p>
                  Öffne pro Buchung den kleinen Bearbeiten-Button, um die Kategorie zu ändern oder
                  direkt eine Regel für ähnliche Fälle anzulegen.
                </p>
              </div>

              <article
                v-for="transaction in selectedCategoryTransactions"
                :key="transaction.id"
                class="transaction-item"
              >
                <div class="transaction-item__content">
                  <div class="transaction-item__header">
                    <strong>
                      {{ transaction.counterparty_name || transaction.description || 'Buchung' }}
                    </strong>
                    <button
                      type="button"
                      class="transaction-edit-button"
                      :aria-label="`Transaktion ${transaction.id} bearbeiten`"
                      title="Kategorie ändern oder Regel erstellen"
                      @click="openTransactionModal(transaction)"
                    >
                      ✎
                    </button>
                  </div>

                  <p class="muted">
                    {{ formatDate(transaction.booking_date) }}
                    <span v-if="transaction.account_name"> · {{ transaction.account_name }}</span>
                    <span class="transaction-category-chip">
                      {{ transaction.category_name || 'Unkategorisiert' }}
                    </span>
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

      <div v-if="activeTransaction" class="modal-overlay" @click.self="closeTransactionModal">
        <div class="modal-card modal-card--transaction">
          <div class="modal-header">
            <div>
              <p class="eyebrow">Transaktion bearbeiten</p>
              <h3>
                {{
                  activeTransaction.counterparty_name || activeTransaction.description || 'Buchung'
                }}
              </h3>
            </div>
            <button
              type="button"
              class="modal-close-button"
              aria-label="Bearbeitungsdialog schließen"
              @click="closeTransactionModal"
            >
              ✕
            </button>
          </div>

          <div class="modal-summary-grid">
            <div class="modal-summary-item">
              <span>Aktuelle Kategorie</span>
              <strong>{{ activeTransaction.category_name || 'Unkategorisiert' }}</strong>
            </div>
            <div class="modal-summary-item">
              <span>Betrag</span>
              <strong
                :class="
                  Number(activeTransaction.cashflow_amount ?? activeTransaction.amount) >= 0
                    ? 'positive'
                    : 'negative'
                "
              >
                {{
                  formatMoney(
                    Math.abs(Number(activeTransaction.cashflow_amount ?? activeTransaction.amount)),
                    activeTransaction.currency,
                  )
                }}
              </strong>
            </div>
            <div class="modal-summary-item">
              <span>Datum</span>
              <strong>{{ formatDate(activeTransaction.booking_date) }}</strong>
            </div>
          </div>

          <div class="modal-section">
            <div class="modal-section__header">
              <div>
                <h4>Kategorie ändern</h4>
                <p>Du kannst die Buchung direkt in eine andere Kategorie verschieben.</p>
              </div>
            </div>

            <div class="modal-form-row">
              <label class="modal-field">
                <span>Zielkategorie</span>
                <select
                  v-model="quickCategoryDrafts[activeTransaction.id]"
                  @change="clearRulePreview(activeTransaction.id)"
                >
                  <option value="">Ohne Kategorie</option>
                  <option
                    v-for="category in sortedCategories"
                    :key="category.id"
                    :value="String(category.id)"
                  >
                    {{ category.name }} · {{ formatCategoryTypeLabel(category.category_type) }}
                  </option>
                </select>
              </label>

              <button
                type="button"
                class="action-button action-button--primary"
                :disabled="savingCategoryTransactionId === activeTransaction.id"
                @click="saveTransactionCategory(activeTransaction)"
              >
                {{
                  savingCategoryTransactionId === activeTransaction.id
                    ? 'Speichert…'
                    : 'Kategorie speichern'
                }}
              </button>
            </div>
          </div>

          <div class="modal-section">
            <div class="modal-section__header">
              <div>
                <h4>Regel für ähnliche Buchungen</h4>
                <p>
                  Optional: Erstelle daraus eine Regel für künftige oder ähnliche Transaktionen.
                </p>
              </div>
              <RouterLink to="/categories" class="link-inline">Erweitert</RouterLink>
            </div>

            <div class="modal-form-grid">
              <label class="modal-field modal-field--wide">
                <span>Regeltext</span>
                <input
                  v-model="quickRulePatternDrafts[activeTransaction.id]"
                  type="text"
                  maxlength="120"
                  placeholder="z. B. REWE oder Netflix"
                  @input="clearRulePreview(activeTransaction.id)"
                />
              </label>

              <label class="modal-field">
                <span>Feld</span>
                <select
                  v-model="quickRuleFieldDrafts[activeTransaction.id]"
                  @change="clearRulePreview(activeTransaction.id)"
                >
                  <option value="counterparty">Gegenstelle</option>
                  <option value="description">Beschreibung</option>
                  <option value="both">Beides</option>
                </select>
              </label>
            </div>

            <div class="modal-actions-inline">
              <button
                v-if="activeTransaction.counterparty_name"
                type="button"
                class="action-button"
                @click="setSuggestedRuleField(activeTransaction, 'counterparty')"
              >
                Name übernehmen
              </button>
              <button
                v-if="activeTransaction.description"
                type="button"
                class="action-button"
                @click="setSuggestedRuleField(activeTransaction, 'description')"
              >
                Text übernehmen
              </button>
              <button
                type="button"
                class="action-button"
                :disabled="previewingRuleTransactionId === activeTransaction.id"
                @click="previewTransactionRule(activeTransaction)"
              >
                {{
                  previewingRuleTransactionId === activeTransaction.id ? 'Prüft…' : 'Treffer prüfen'
                }}
              </button>
              <button
                type="button"
                class="action-button action-button--primary"
                :disabled="savingRuleTransactionId === activeTransaction.id"
                @click="saveTransactionRule(activeTransaction)"
              >
                {{
                  savingRuleTransactionId === activeTransaction.id
                    ? 'Speichert…'
                    : 'Regel speichern'
                }}
              </button>
            </div>

            <p v-if="activeTransaction.id in quickRulePreviewCounts" class="muted quick-preview">
              Regeltest: {{ quickRulePreviewCounts[activeTransaction.id] }} passende Buchungen.
            </p>
          </div>
        </div>
      </div>
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

.success-banner {
  border-color: rgba(5, 150, 105, 0.25);
  background: rgba(5, 150, 105, 0.08);
}

.success-banner p {
  margin: 0;
}

.quick-tip {
  display: grid;
  gap: 0.35rem;
  padding: 0.8rem 0.95rem;
  border: 1px dashed var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

.quick-tip p {
  margin: 0;
}

.transaction-item__content {
  flex: 1;
  min-width: 0;
}

.transaction-item__header {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  justify-content: space-between;
}

.transaction-item__header strong {
  min-width: 0;
}

.transaction-category-chip {
  display: inline-flex;
  align-items: center;
  margin-left: 0.45rem;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: var(--color-background-mute);
  font-size: 0.74rem;
  font-weight: 700;
}

.transaction-edit-button {
  flex: 0 0 auto;
  width: 2rem;
  height: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-surface);
  color: var(--color-accent-strong);
  cursor: pointer;
}

.transaction-edit-button:hover {
  background: var(--color-accent-soft);
}

.modal-overlay {
  position: fixed;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.5);
  z-index: 40;
}

.modal-card--transaction {
  width: min(100%, 760px);
  max-height: min(90vh, 52rem);
  overflow-y: auto;
  padding: 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface-strong);
  box-shadow: var(--shadow-elevated);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
}

.modal-header h3 {
  margin: 0;
}

.modal-close-button {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-surface);
  color: var(--color-text);
  width: 2rem;
  height: 2rem;
  cursor: pointer;
}

.modal-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.modal-summary-item {
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface);
}

.modal-summary-item span {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

.modal-section {
  display: grid;
  gap: 0.75rem;
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--color-border);
}

.modal-section__header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: start;
}

.modal-section__header h4 {
  margin: 0;
}

.modal-section__header p {
  margin: 0.2rem 0 0;
  color: var(--color-text-muted);
}

.modal-form-row,
.modal-form-grid {
  display: grid;
  gap: 0.75rem;
}

.modal-form-row {
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: end;
}

.modal-form-grid {
  grid-template-columns: minmax(0, 1.5fr) minmax(12rem, 0.8fr);
}

.modal-field {
  display: grid;
  gap: 0.35rem;
  min-width: 0;
}

.modal-field span {
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

.modal-field input,
.modal-field select {
  width: 100%;
  min-width: 0;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface);
  color: var(--color-text);
  padding: 0.7rem 0.8rem;
}

.modal-actions-inline {
  display: flex;
  gap: 0.45rem;
  flex-wrap: wrap;
}

.action-button {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-surface);
  color: var(--color-text);
  padding: 0.6rem 0.8rem;
  cursor: pointer;
}

.action-button:hover {
  background: var(--color-accent-soft);
}

.action-button--primary {
  border-color: var(--color-accent-strong);
  background: var(--color-accent-strong);
  color: white;
}

.action-button--primary:hover {
  background: var(--color-accent-strong);
  opacity: 0.92;
}

.action-button:disabled {
  cursor: wait;
  opacity: 0.7;
}

.quick-preview {
  font-size: 0.85rem;
}

.link-inline {
  color: var(--color-accent-strong);
  font-weight: 700;
  text-decoration: none;
}

.link-inline:hover {
  text-decoration: underline;
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

  .modal-summary-grid,
  .modal-form-row,
  .modal-form-grid {
    grid-template-columns: 1fr;
  }

  .modal-section__header {
    flex-direction: column;
  }
}
</style>
