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
    cashflow_amount: string
    cash_withdrawal_amount: string | null
    currency: string
    direction: string
    source_system: string
    account_name: string | null
    is_transfer: boolean
    is_hidden_from_cashflow: boolean
    transfer_group_id: string | null
    transfer_kind: string | null
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
const isRefreshing = ref(false)
const error = ref('')
const viewMode = ref<'month' | 'all'>('month')
const selectedMonth = ref(new Date().toISOString().slice(0, 7))
const searchQuery = ref('')
const selectedAccountId = ref('')
const selectedTransactionId = ref<number | null>(null)
const transferFilter = ref<'all' | 'transfer' | 'linked' | 'group'>('all')
const categoryDraft = ref('')
const savingCategory = ref(false)
const defaultAccountInitialized = ref(false)
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null
let latestLoadRequestId = 0

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

const transferFilteredTransactions = computed(() => {
  switch (transferFilter.value) {
    case 'transfer':
      return transactions.value.filter((transaction) => transaction.is_transfer)
    case 'linked':
      return transactions.value.filter(
        (transaction) => transaction.is_transfer && Boolean(transaction.transfer_group_id),
      )
    case 'group': {
      const selectedGroupId = selectedTransaction.value?.transfer_group_id

      return selectedGroupId
        ? transactions.value.filter(
            (transaction) => transaction.transfer_group_id === selectedGroupId,
          )
        : transactions.value.filter(
            (transaction) => transaction.is_transfer && Boolean(transaction.transfer_group_id),
          )
    }
    default:
      return transactions.value
  }
})

const filteredTransactions = computed(() => {
  const normalizedQuery = searchQuery.value.trim().toLocaleLowerCase('de')

  if (!normalizedQuery) {
    return transferFilteredTransactions.value
  }

  return transferFilteredTransactions.value.filter((transaction) => {
    const haystacks = [
      transaction.counterparty_name ?? '',
      transaction.description ?? '',
      transaction.account_name ?? '',
      transaction.source_system ?? '',
    ]

    return haystacks.some((value) => value.toLocaleLowerCase('de').includes(normalizedQuery))
  })
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
  const sortedTransactions = [...filteredTransactions.value].sort((left, right) => {
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

function formatTransferKind(transferKind: string | null) {
  switch (transferKind) {
    case 'credit_card_settlement':
      return 'Kreditkarten-Ausgleich'
    case 'paypal_settlement':
      return 'PayPal-Ausgleich'
    case 'cash_withdrawal':
      return 'Barabhebung'
    case 'internal_transfer':
      return 'Interner Transfer'
    default:
      return 'Technischer Transfer'
  }
}

function formatTransferState(transaction: TransactionItem) {
  if (!transaction.is_transfer) {
    return ''
  }

  return transaction.transfer_group_id ? 'Verknüpft' : 'Noch ohne Gegenbuchung'
}

function hasCashWithdrawalComponent(transaction: TransactionItem | null) {
  return Number(transaction?.cash_withdrawal_amount ?? 0) > 0
}

function formatCashWithdrawalHint(transaction: TransactionItem) {
  const cashWithdrawalAmount = Number(transaction.cash_withdrawal_amount ?? 0)
  const purchaseAmount = Math.abs(Number(transaction.cashflow_amount ?? transaction.amount))

  if (!(cashWithdrawalAmount > 0)) {
    return ''
  }

  return `Enthält Bargeldauszahlung ${formatMoney(cashWithdrawalAmount, transaction.currency)} · Einkaufsanteil ca. ${formatMoney(purchaseAmount, transaction.currency)}`
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

async function loadTransactions(options: { silent?: boolean } = {}) {
  if (!authStore.token) {
    dashboard.value = null
    return
  }

  const { silent = false } = options
  const requestId = ++latestLoadRequestId
  const trimmedSearchQuery = searchQuery.value.trim()

  if (silent) {
    isRefreshing.value = true
  } else {
    loading.value = true
  }

  error.value = ''

  try {
    const params = new URLSearchParams({ view: viewMode.value })

    if (viewMode.value === 'month' && selectedMonth.value) {
      params.set('month', selectedMonth.value)
    }

    if (selectedAccountId.value) {
      params.set('account_id', selectedAccountId.value)
    }

    if (trimmedSearchQuery) {
      params.set('query', trimmedSearchQuery)
    }

    const response = await apiFetch<DashboardResponse>(
      `/api/dashboard?${params.toString()}`,
      {},
      authStore.token,
    )

    if (requestId !== latestLoadRequestId) {
      return
    }

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
        await loadTransactions({ silent })
        return
      }
    }

    defaultAccountInitialized.value = true
    dashboard.value = response
    selectedAccountId.value = responseSelectedAccountId || selectedAccountId.value

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
    if (silent) {
      isRefreshing.value = false
    } else {
      loading.value = false
    }
  }
}

async function applyFilters() {
  await loadTransactions()
}

async function resetFilters() {
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }

  searchQuery.value = ''
  selectedAccountId.value = ''
  transferFilter.value = 'all'
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

function isRelatedTransfer(transaction: TransactionItem) {
  const selectedGroupId = selectedTransaction.value?.transfer_group_id

  return Boolean(
    selectedGroupId &&
    transaction.transfer_group_id === selectedGroupId &&
    transaction.id !== selectedTransaction.value?.id,
  )
}

function getLinkedCounterpart(transaction: TransactionItem | null) {
  if (!transaction?.transfer_group_id) {
    return null
  }

  return (
    transactions.value.find(
      (candidate) =>
        candidate.transfer_group_id === transaction.transfer_group_id &&
        candidate.id !== transaction.id,
    ) ?? null
  )
}

async function jumpToLinkedTransaction(transaction: TransactionItem | null) {
  if (!transaction?.transfer_group_id) {
    return
  }

  error.value = ''

  let counterpart: TransactionItem | null = getLinkedCounterpart(transaction)

  if (!counterpart) {
    selectedAccountId.value = ''
    searchQuery.value = ''
    viewMode.value = 'all'
    transferFilter.value = 'group'

    await loadTransactions()

    counterpart =
      transactions.value.find(
        (candidate) =>
          candidate.transfer_group_id === transaction.transfer_group_id &&
          candidate.id !== transaction.id,
      ) ?? null
  }

  if (!counterpart) {
    error.value =
      'Die verknüpfte Gegenbuchung konnte in den geladenen Buchungen nicht gefunden werden.'
    return
  }

  transferFilter.value = 'group'
  selectedTransactionId.value = counterpart.id
}

function showSelectedTransferGroup() {
  if (!selectedTransaction.value?.transfer_group_id) {
    return
  }

  transferFilter.value = 'group'
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

    if (transferFilter.value === 'group' && !transaction?.transfer_group_id) {
      transferFilter.value = 'linked'
    }
  },
  { immediate: true },
)

watch(searchQuery, (nextValue, previousValue) => {
  if (!authStore.token || nextValue === previousValue) {
    return
  }

  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
  }

  searchDebounceTimer = setTimeout(() => {
    void loadTransactions({ silent: true })
    searchDebounceTimer = null
  }, 350)
})

watch(
  filteredTransactions,
  (visibleTransactions) => {
    if (visibleTransactions.length === 0) {
      selectedTransactionId.value = null
      return
    }

    const visibleIds = visibleTransactions.map((transaction) => transaction.id)

    if (selectedTransactionId.value === null || !visibleIds.includes(selectedTransactionId.value)) {
      selectedTransactionId.value = visibleTransactions[0]?.id ?? null
    }
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
          <span class="muted small-note">Sucht automatisch nach kurzer Pause</span>
          <button type="button" class="ghost-button" @click="resetFilters">Zurücksetzen</button>
        </div>
      </form>

      <div class="transfer-toolbar">
        <span class="toolbar-caption">Transfer-Filter</span>
        <div class="toolbar">
          <button
            type="button"
            class="filter-toggle"
            :class="{ active: transferFilter === 'all' }"
            @click="transferFilter = 'all'"
          >
            Alle
          </button>
          <button
            type="button"
            class="filter-toggle"
            :class="{ active: transferFilter === 'transfer' }"
            @click="transferFilter = 'transfer'"
          >
            Transfers
          </button>
          <button
            type="button"
            class="filter-toggle"
            :class="{ active: transferFilter === 'linked' }"
            @click="transferFilter = 'linked'"
          >
            Verknüpft
          </button>
          <button
            v-if="selectedTransaction?.transfer_group_id"
            type="button"
            class="filter-toggle"
            :class="{ active: transferFilter === 'group' }"
            @click="showSelectedTransferGroup"
          >
            Diese Gruppe
          </button>
        </div>
        <p class="compact-note">
          {{ filteredTransactions.length }} von {{ transactions.length }} Buchungen sichtbar
        </p>
      </div>

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

      <div v-if="groupedTransactions.length" class="group-list-wrap">
        <div v-if="isRefreshing" class="refresh-indicator" aria-hidden="true">
          <span class="refresh-spinner"></span>
        </div>
        <div class="group-list" :class="{ refreshing: isRefreshing }">
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
              :class="{
                active: selectedTransaction?.id === transaction.id,
                related: isRelatedTransfer(transaction),
              }"
              type="button"
              @click="selectTransaction(transaction.id)"
            >
              <div class="transaction-main">
                <strong>{{ transaction.counterparty_name || 'Ohne Gegenstelle' }}</strong>
                <p>{{ transaction.description || formatSourceType(transaction.source_system) }}</p>
                <p v-if="transaction.is_transfer" class="transaction-note">
                  {{ formatTransferKind(transaction.transfer_kind) }} ·
                  {{ formatTransferState(transaction) }}
                </p>
                <p v-else-if="hasCashWithdrawalComponent(transaction)" class="transaction-note">
                  {{ formatCashWithdrawalHint(transaction) }}
                </p>
              </div>
              <div class="transaction-meta">
                <div class="transaction-tags">
                  <span class="chip">{{ transaction.category_name || 'Unkategorisiert' }}</span>
                  <span
                    v-if="transaction.is_transfer && transaction.transfer_group_id"
                    class="chip chip-muted chip-button"
                    role="button"
                    tabindex="0"
                    @click.stop="jumpToLinkedTransaction(transaction)"
                    @keydown.enter.stop.prevent="jumpToLinkedTransaction(transaction)"
                    @keydown.space.stop.prevent="jumpToLinkedTransaction(transaction)"
                  >
                    verknüpft ↗
                  </span>
                  <span v-else-if="transaction.is_transfer" class="chip chip-muted">Transfer</span>
                </div>
                <strong :class="transaction.direction === 'credit' ? 'positive' : 'negative'">
                  {{ formatMoney(transaction.amount, transaction.currency) }}
                </strong>
              </div>
            </button>
          </section>
        </div>
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
          <p v-if="hasCashWithdrawalComponent(selectedTransaction)" class="detail-copy">
            Diese Kartenzahlung enthält zusätzlich eine Bargeldauszahlung von
            {{
              formatMoney(
                Number(selectedTransaction.cash_withdrawal_amount ?? 0),
                selectedTransaction.currency,
              )
            }}. Für die Auswertungen wird nur der Einkaufsanteil von
            {{
              formatMoney(
                Math.abs(Number(selectedTransaction.cashflow_amount)),
                selectedTransaction.currency,
              )
            }}
            als Ausgabe gezählt.
          </p>
        </section>

        <section v-if="selectedTransaction.is_transfer" class="detail-box">
          <h3>Technische Buchung</h3>
          <div class="transfer-status-row">
            <span class="chip">{{ formatTransferKind(selectedTransaction.transfer_kind) }}</span>
            <span class="chip chip-muted">{{ formatTransferState(selectedTransaction) }}</span>
          </div>
          <p class="detail-copy">
            Diese Buchung ist als Transfer zwischen Zahlungsquellen markiert und wird deshalb in
            Einnahmen und Ausgaben nicht mitgerechnet.
          </p>
          <div class="transfer-actions">
            <button
              v-if="selectedTransaction.transfer_group_id"
              type="button"
              class="ghost-button"
              @click="showSelectedTransferGroup"
            >
              Nur diese Gruppe zeigen
            </button>
            <button
              v-if="getLinkedCounterpart(selectedTransaction)"
              type="button"
              class="ghost-button"
              @click="jumpToLinkedTransaction(selectedTransaction)"
            >
              Zur Gegenbuchung
            </button>
            <button
              v-if="transferFilter !== 'all'"
              type="button"
              class="ghost-button"
              @click="transferFilter = 'all'"
            >
              Alle Buchungen anzeigen
            </button>
          </div>
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
  min-height: 680px;
}

.group-list-wrap {
  position: relative;
}

.group-list.refreshing {
  opacity: 0.88;
  transition: opacity 120ms ease;
}

.refresh-indicator {
  position: absolute;
  top: 0.2rem;
  right: 0.2rem;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-surface) 88%, transparent);
  pointer-events: none;
}

.refresh-spinner {
  width: 0.8rem;
  height: 0.8rem;
  border-radius: 999px;
  border: 2px solid var(--color-border);
  border-top-color: var(--color-accent-strong);
  animation: spin 0.8s linear infinite;
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
.category-editor,
.transfer-actions {
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

.transfer-toolbar {
  display: grid;
  gap: 0.45rem;
  margin-bottom: 0.75rem;
}

.toolbar-caption {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.compact-note,
.small-note {
  margin: 0;
  font-size: 0.82rem;
  color: var(--color-text-muted);
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
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

.transaction-row.related {
  border-color: rgba(79, 70, 229, 0.38);
  background: color-mix(in srgb, var(--color-accent-soft) 45%, var(--color-surface-strong));
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

.transaction-tags,
.transfer-status-row {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.transaction-tags {
  justify-content: flex-end;
}

.transfer-status-row {
  justify-content: flex-start;
  margin-top: 0.25rem;
}

.transaction-note {
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.chip {
  display: inline-flex;
  padding: 0.25rem 0.55rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  font-size: 0.8rem;
}

.chip-muted {
  background: var(--color-surface);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border);
}

.chip-button {
  cursor: pointer;
  font-weight: 700;
  user-select: none;
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

.detail-copy {
  margin-top: 0.75rem;
  color: var(--color-text-muted);
}

.transfer-actions {
  margin-top: 0.85rem;
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
    height: calc(100vh - 3rem);
    height: calc(100dvh - 3rem);
    max-height: calc(100vh - 3rem);
    max-height: calc(100dvh - 3rem);
    overflow-y: auto;
    overscroll-behavior: contain;
  }
}
</style>
