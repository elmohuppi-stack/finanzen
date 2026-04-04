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
    current_balance: string
    balance_as_of: string | null
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

type TransactionItem = DashboardResponse['transactions'][number]

const authStore = useAuthStore()
const dashboard = ref<DashboardResponse | null>(null)
const loading = ref(false)
const error = ref('')
const viewMode = ref<'month' | 'all'>('month')
const selectedMonth = ref(new Date().toISOString().slice(0, 7))
const searchQuery = ref('')
const selectedAccountId = ref('')
const selectedTransactionId = ref<number | null>(null)
const categoryDraft = ref('')
const savingCategory = ref(false)
const defaultAccountInitialized = ref(false)

const availableMonths = computed(() => dashboard.value?.filters.available_months ?? [])
const currentMonthIndex = computed(() => availableMonths.value.indexOf(selectedMonth.value))
const canGoToNewerMonth = computed(() => currentMonthIndex.value > 0)
const canGoToOlderMonth = computed(
  () => currentMonthIndex.value >= 0 && currentMonthIndex.value < availableMonths.value.length - 1,
)
const accounts = computed(() => {
  return [...(dashboard.value?.accounts ?? [])].sort((left, right) => {
    const priorityDifference =
      getAccountPriority(left.account_type) - getAccountPriority(right.account_type)

    if (priorityDifference !== 0) {
      return priorityDifference
    }

    return left.name.localeCompare(right.name, 'de')
  })
})
const transactions = computed(() => dashboard.value?.transactions ?? [])
const categories = computed(() => dashboard.value?.categories ?? [])
const displayedBalanceTotal = computed(() => {
  const relevantAccounts = selectedAccountId.value
    ? accounts.value.filter((account) => String(account.id) === selectedAccountId.value)
    : accounts.value

  return relevantAccounts.reduce((sum, account) => {
    return sum + Number(account.current_balance || account.booked_balance || 0)
  }, 0)
})

const selectedTransaction = computed<TransactionItem | null>(() => {
  if (selectedTransactionId.value === null) {
    return transactions.value[0] ?? null
  }

  return (
    transactions.value.find((transaction) => transaction.id === selectedTransactionId.value) ?? null
  )
})

const groupedTransactions = computed(() => {
  const groups = new Map<
    string,
    {
      dateKey: string
      dateLabel: string
      total: number
      closingBalance: number
      items: TransactionItem[]
    }
  >()
  const sortedTransactions = [...transactions.value].sort((left, right) => {
    const leftDate = left.booking_date ?? ''
    const rightDate = right.booking_date ?? ''

    return rightDate.localeCompare(leftDate) || right.id - left.id
  })

  for (const transaction of sortedTransactions) {
    const key = transaction.booking_date ?? 'ohne-datum'

    if (!groups.has(key)) {
      groups.set(key, {
        dateKey: key,
        dateLabel: formatDate(transaction.booking_date),
        total: 0,
        closingBalance: 0,
        items: [],
      })
    }

    const group = groups.get(key)

    if (!group) {
      continue
    }

    group.total += Number(transaction.amount)
    group.items.push(transaction)
  }

  let runningBalance = displayedBalanceTotal.value

  return Array.from(groups.values()).map((group) => {
    const closingBalance = runningBalance
    runningBalance -= group.total

    return {
      ...group,
      closingBalance,
    }
  })
})

const periodLabel = computed(() => {
  if (viewMode.value === 'all') {
    return 'Alle Buchungen'
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

function formatMoney(amount: number | string, currency = 'EUR') {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency,
  }).format(Number(amount))
}

function formatSignedMoney(amount: number | string, currency = 'EUR') {
  const numericAmount = Number(amount)
  const formattedAmount = formatMoney(numericAmount, currency)

  return numericAmount > 0 ? `+${formattedAmount}` : formattedAmount
}

function formatDate(value: string | null) {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
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

function getAccountPriority(accountType: string) {
  switch (accountType) {
    case 'checking_account':
      return 0
    case 'cash_wallet':
      return 1
    case 'savings_account':
      return 2
    case 'paypal_account':
      return 3
    case 'credit_card':
      return 4
    default:
      return 5
  }
}

function getDefaultAccountId(accountList: DashboardResponse['accounts']) {
  const sortedAccounts = [...accountList].sort((left, right) => {
    const priorityDifference =
      getAccountPriority(left.account_type) - getAccountPriority(right.account_type)

    if (priorityDifference !== 0) {
      return priorityDifference
    }

    return left.name.localeCompare(right.name, 'de')
  })

  return sortedAccounts[0] ? String(sortedAccounts[0].id) : ''
}

async function loadTransactions() {
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

    if (selectedAccountId.value) {
      params.set('account_id', selectedAccountId.value)
    }

    if (searchQuery.value.trim()) {
      params.set('query', searchQuery.value.trim())
    }

    const response = await apiFetch<DashboardResponse>(
      `/api/dashboard?${params.toString()}`,
      {},
      authStore.token,
    )

    const responseSelectedAccountId = response.filters.selected_account_id
      ? String(response.filters.selected_account_id)
      : ''

    if (
      !defaultAccountInitialized.value &&
      !selectedAccountId.value &&
      !responseSelectedAccountId
    ) {
      defaultAccountInitialized.value = true

      const defaultAccountId = getDefaultAccountId(response.accounts)

      if (defaultAccountId) {
        selectedAccountId.value = defaultAccountId
        await loadTransactions()
        return
      }
    }

    defaultAccountInitialized.value = true
    dashboard.value = response
    selectedAccountId.value = responseSelectedAccountId || selectedAccountId.value
    searchQuery.value = response.filters.search_query ?? ''

    if (response.filters.selected_month) {
      selectedMonth.value = response.filters.selected_month
    }

    const currentIds = response.transactions.map((transaction) => transaction.id)

    if (!selectedTransactionId.value || !currentIds.includes(selectedTransactionId.value)) {
      selectedTransactionId.value = response.transactions[0]?.id ?? null
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Buchungen konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  await loadTransactions()
}

async function resetFilters() {
  searchQuery.value = ''
  selectedAccountId.value = ''
  await loadTransactions()
}

async function showMonthlyView() {
  viewMode.value = 'month'
  await loadTransactions()
}

async function showAllView() {
  viewMode.value = 'all'
  await loadTransactions()
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
  await loadTransactions()
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
  await loadTransactions()
}

async function selectAccount(accountId: number | null) {
  selectedAccountId.value = accountId ? String(accountId) : ''
  await loadTransactions()
}

function selectTransaction(transactionId: number) {
  selectedTransactionId.value = transactionId
}

async function saveCategory() {
  if (!authStore.token || !selectedTransaction.value) {
    return
  }

  savingCategory.value = true
  error.value = ''

  try {
    await apiFetch(
      `/api/transactions/${selectedTransaction.value.id}/category`,
      {
        method: 'PATCH',
        body: JSON.stringify({
          category_id: categoryDraft.value ? Number(categoryDraft.value) : null,
        }),
      },
      authStore.token,
    )

    await loadTransactions()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Kategorie konnte nicht gespeichert werden.'
  } finally {
    savingCategory.value = false
  }
}

watch(
  selectedTransaction,
  (transaction) => {
    categoryDraft.value = transaction?.category_id ? String(transaction.category_id) : ''
  },
  { immediate: true },
)

watch(
  () => authStore.token,
  async (token) => {
    if (!token) {
      dashboard.value = null
      selectedAccountId.value = ''
      defaultAccountInitialized.value = false
      return
    }

    await loadTransactions()
  },
  { immediate: true },
)
</script>

<template>
  <section class="transactions-layout">
    <aside class="panel accounts-panel card">
      <div class="panel-header">
        <div>
          <p class="eyebrow">Buchungen</p>
          <h2>Konten</h2>
        </div>
        <button class="ghost-button" type="button" @click="selectAccount(null)">Alle</button>
      </div>

      <p class="muted">Wähle ein Konto oder arbeite über alle Buchungen hinweg.</p>

      <button
        v-for="account in accounts"
        :key="account.id"
        class="account-card"
        :class="{ active: selectedAccountId === String(account.id) }"
        type="button"
        @click="selectAccount(account.id)"
      >
        <div>
          <strong>{{ account.name }}</strong>
          <p>{{ account.institution || 'Ohne Institut' }}</p>
          <p>
            {{
              account.balance_as_of ? `Stand ${formatDate(account.balance_as_of)}` : 'Kein Stichtag'
            }}
          </p>
        </div>
        <span :class="Number(account.current_balance) >= 0 ? 'positive' : 'negative'">
          {{ formatMoney(account.current_balance, account.currency) }}
        </span>
      </button>
    </aside>

    <section class="panel list-panel card">
      <div class="panel-header">
        <div>
          <p class="eyebrow">Zeitraum</p>
          <h2>{{ periodLabel }}</h2>
        </div>
        <div class="toolbar">
          <button
            type="button"
            class="filter-toggle"
            :class="{ active: viewMode === 'month' }"
            @click="showMonthlyView"
          >
            Monat
          </button>
          <button
            type="button"
            class="filter-toggle"
            :class="{ active: viewMode === 'all' }"
            @click="showAllView"
          >
            Alle
          </button>
        </div>
      </div>

      <form class="filters-form" @submit.prevent="applyFilters">
        <input
          v-model="searchQuery"
          type="search"
          class="search-input"
          placeholder="Suche nach Gegenstelle oder Beschreibung"
        />
        <div class="filter-actions">
          <button type="submit" class="primary-button">Suchen</button>
          <button type="button" class="ghost-button" @click="resetFilters">Zurücksetzen</button>
        </div>
      </form>

      <div v-if="viewMode === 'month' && availableMonths.length" class="month-nav">
        <button
          type="button"
          class="ghost-button"
          :disabled="!canGoToOlderMonth"
          @click="goToOlderMonth"
        >
          ← Älter
        </button>
        <strong>{{ periodLabel }}</strong>
        <button
          type="button"
          class="ghost-button"
          :disabled="!canGoToNewerMonth"
          @click="goToNewerMonth"
        >
          Neuer →
        </button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="loading" class="muted">Buchungen werden geladen…</p>

      <div v-else-if="groupedTransactions.length" class="group-list">
        <section v-for="group in groupedTransactions" :key="group.dateKey" class="day-group">
          <header class="day-header">
            <strong>{{ group.dateLabel }}</strong>
            <span class="day-balance">
              <span>({{ formatSignedMoney(group.total) }})</span>
              <span>Stand {{ formatMoney(group.closingBalance) }}</span>
            </span>
          </header>

          <button
            v-for="transaction in group.items"
            :key="transaction.id"
            class="transaction-row"
            :class="{ active: selectedTransaction?.id === transaction.id }"
            type="button"
            @click="selectTransaction(transaction.id)"
          >
            <div class="transaction-main">
              <strong>{{ transaction.counterparty_name || 'Ohne Gegenstelle' }}</strong>
              <p>{{ transaction.description || formatSourceType(transaction.source_system) }}</p>
            </div>
            <div class="transaction-meta">
              <span class="chip">{{ transaction.category_name || 'Unkategorisiert' }}</span>
              <strong :class="transaction.direction === 'credit' ? 'positive' : 'negative'">
                {{ formatMoney(transaction.amount, transaction.currency) }}
              </strong>
            </div>
          </button>
        </section>
      </div>
      <p v-else class="muted">Für diese Auswahl wurden keine Buchungen gefunden.</p>
    </section>

    <aside class="panel details-panel card">
      <div v-if="selectedTransaction">
        <p class="eyebrow">Details</p>
        <h2>{{ selectedTransaction.counterparty_name || 'Buchung' }}</h2>
        <p
          class="amount"
          :class="selectedTransaction.direction === 'credit' ? 'positive' : 'negative'"
        >
          {{ formatMoney(selectedTransaction.amount, selectedTransaction.currency) }}
        </p>

        <dl class="details-grid">
          <div>
            <dt>Buchungstag</dt>
            <dd>{{ formatDate(selectedTransaction.booking_date) }}</dd>
          </div>
          <div>
            <dt>Wertstellung</dt>
            <dd>{{ formatDate(selectedTransaction.value_date) }}</dd>
          </div>
          <div>
            <dt>Konto</dt>
            <dd>{{ selectedTransaction.account_name || '—' }}</dd>
          </div>
          <div>
            <dt>Quelle</dt>
            <dd>{{ formatSourceType(selectedTransaction.source_system) }}</dd>
          </div>
        </dl>

        <section class="detail-box">
          <h3>Beschreibung</h3>
          <p>
            {{ selectedTransaction.description || 'Keine zusätzliche Beschreibung vorhanden.' }}
          </p>
        </section>

        <section class="detail-box">
          <h3>Kategorie</h3>
          <div class="category-editor">
            <select v-model="categoryDraft" class="category-select">
              <option value="">Ohne Kategorie</option>
              <option
                v-for="category in categories"
                :key="category.id"
                :value="String(category.id)"
              >
                {{ category.name }}
              </option>
            </select>
            <button
              class="primary-button"
              type="button"
              :disabled="savingCategory"
              @click="saveCategory"
            >
              {{ savingCategory ? 'Speichert…' : 'Kategorie speichern' }}
            </button>
          </div>
        </section>
      </div>

      <div v-else class="empty-state">
        <h2>Keine Buchung ausgewählt</h2>
        <p>Wähle links eine Buchung aus, um Details und Kategoriezuordnung zu sehen.</p>
      </div>

      <RouterLink class="text-link" to="/imports">Neue Datei importieren</RouterLink>
    </aside>
  </section>
</template>

<style scoped>
.transactions-layout {
  display: grid;
  gap: 1rem;
}

.card {
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-elevated);
}

.panel {
  min-height: 540px;
}

.list-panel {
  scrollbar-gutter: stable;
}

.panel-header {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: start;
  margin-bottom: 0.75rem;
}

.eyebrow {
  margin: 0 0 0.25rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

h2,
h3,
p {
  margin-top: 0;
}

.muted {
  color: var(--color-text-muted);
}

.toolbar,
.filter-actions,
.category-editor {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: center;
}

.filters-form {
  display: grid;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.search-input,
.category-select {
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.7rem 0.85rem;
}

.primary-button,
.ghost-button,
.filter-toggle {
  border-radius: 999px;
  padding: 0.55rem 0.85rem;
  font-weight: 700;
  cursor: pointer;
}

.primary-button {
  border: 0;
  background: var(--color-accent-strong);
  color: white;
}

.ghost-button,
.filter-toggle {
  border: 1px solid var(--color-border);
  background: var(--color-surface-strong);
  color: var(--color-text);
}

.filter-toggle.active {
  background: var(--color-accent-strong);
  color: white;
  border-color: transparent;
}

.month-nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--color-border);
  border-bottom: 1px solid var(--color-border);
  margin-bottom: 0.75rem;
}

.account-card,
.transaction-row {
  width: 100%;
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  text-align: left;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
  color: var(--color-text);
}

.account-card {
  padding: 0.9rem;
  margin-bottom: 0.6rem;
}

.account-card.active,
.transaction-row.active {
  border-color: var(--color-accent-strong);
  box-shadow: 0 8px 24px rgba(79, 70, 229, 0.12);
}

.account-card p,
.transaction-main p {
  margin: 0.2rem 0 0;
  color: var(--color-text-muted);
}

.group-list {
  display: grid;
  gap: 1rem;
}

.day-group {
  display: grid;
  gap: 0.5rem;
}

.day-header {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
  font-size: 0.95rem;
}

.day-balance {
  display: inline-flex;
  gap: 0.45rem;
  align-items: center;
  flex-wrap: wrap;
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--color-text-muted);
}

.transaction-row {
  padding: 0.8rem 0.9rem;
  align-items: center;
}

.transaction-main {
  min-width: 0;
}

.transaction-main p {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
  line-clamp: 2;
}

.transaction-meta {
  display: grid;
  justify-items: end;
  gap: 0.35rem;
}

.chip {
  display: inline-flex;
  padding: 0.25rem 0.55rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  font-size: 0.8rem;
}

.amount {
  font-size: clamp(1.8rem, 4vw, 2.4rem);
  font-weight: 800;
}

.details-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

dt {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

dd {
  margin: 0.2rem 0 0;
}

.detail-box {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--color-border);
}

.text-link {
  display: inline-flex;
  margin-top: 1rem;
  color: var(--color-accent-strong);
  font-weight: 700;
  text-decoration: none;
}

.error,
.negative {
  color: var(--color-danger);
}

.positive {
  color: #059669;
}

@media (min-width: 1100px) {
  .transactions-layout {
    grid-template-columns: 280px minmax(0, 1fr) 320px;
    align-items: start;
  }

  .list-panel {
    max-height: min(720px, calc(100vh - 8rem));
    overflow-y: auto;
    overscroll-behavior: contain;
  }
}
</style>
