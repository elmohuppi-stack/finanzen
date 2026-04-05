<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

type MatchField = 'description' | 'counterparty' | 'both'
type WorkbenchTab = 'categories' | 'rules' | 'datasets' | 'editor'
type CategorySubTab = 'overview' | 'list' | 'form'
type CategorySort = 'rules' | 'name' | 'type'
type PreviewMode = 'matches' | 'all'

interface CategoryItem {
  id: number
  name: string
  category_type: string
  color: string | null
  is_system: boolean
}

interface CategoryRuleItem {
  id: number
  category_id: number
  category_name: string | null
  category_color: string | null
  name: string | null
  pattern: string
  match_field: MatchField
  match_type: 'contains'
  priority: number
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}

interface PreviewTransactionItem {
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
  category_source: string | null
  category_rule_id: number | null
  category_rule_name: string | null
}

interface CategoryRulesResponse {
  categories: CategoryItem[]
  rules: CategoryRuleItem[]
}

interface ApplyRulesResponse {
  summary: {
    matched_transactions: number
    updated_transactions: number
    skipped_manual_transactions: number
  }
}

interface RuleImportSummary {
  imported_rules: number
  updated_rules: number
  skipped_rows: number
}

interface RulePreviewResponse {
  summary: {
    matched_transactions: number
    category_name: string | null
    pattern: string
    match_field: MatchField
  }
  transactions: PreviewTransactionItem[]
}

const authStore = useAuthStore()
const categories = ref<CategoryItem[]>([])
const rules = ref<CategoryRuleItem[]>([])
const previewTransactions = ref<PreviewTransactionItem[]>([])
const allTransactions = ref<PreviewTransactionItem[]>([])
const loading = ref(false)
const saving = ref(false)
const applying = ref(false)
const importing = ref(false)
const exporting = ref(false)
const resetting = ref(false)
const seedingDefaults = ref(false)
const previewLoading = ref(false)
const allTransactionsLoading = ref(false)
const error = ref('')
const successMessage = ref('')
const applySummary = ref<ApplyRulesResponse['summary'] | null>(null)
const previewSummary = ref<RulePreviewResponse['summary'] | null>(null)
const editingRuleId = ref<number | null>(null)
const importMode = ref<'merge' | 'replace'>('merge')
const activeTab = ref<WorkbenchTab>('categories')
const categorySubTab = ref<CategorySubTab>('list')
const previewMode = ref<PreviewMode>('matches')
const selectedRuleCategoryId = ref('all')
const categorySort = ref<CategorySort>('rules')
const hideEmptyCategories = ref(true)
const fileInput = ref<HTMLInputElement | null>(null)
const categorySaving = ref(false)
const editingCategoryId = ref<number | null>(null)
let successMessageTimer: ReturnType<typeof setTimeout> | null = null

const categoryForm = ref<{
  name: string
  category_type: string
  color: string
}>({
  name: '',
  category_type: 'expense',
  color: '',
})

const form = ref<{
  category_id: string
  name: string
  pattern: string
  match_field: MatchField
  priority: number
  is_active: boolean
}>({
  category_id: '',
  name: '',
  pattern: '',
  match_field: 'both',
  priority: 100,
  is_active: true,
})

const sortedRules = computed(() => {
  return [...rules.value].sort((left, right) => {
    return (
      (left.category_name ?? '').localeCompare(right.category_name ?? '', 'de') ||
      Number(right.is_active) - Number(left.is_active) ||
      right.priority - left.priority ||
      left.pattern.localeCompare(right.pattern, 'de')
    )
  })
})

const filteredRules = computed(() => {
  if (selectedRuleCategoryId.value === 'all') {
    return sortedRules.value
  }

  return sortedRules.value.filter(
    (rule) => String(rule.category_id) === selectedRuleCategoryId.value,
  )
})

const selectedRuleCategory = computed(
  () =>
    categories.value.find((category) => String(category.id) === selectedRuleCategoryId.value) ??
    undefined,
)

const currentEditableCategory = computed(
  () => categories.value.find((category) => category.id === editingCategoryId.value) ?? null,
)

const categoryStats = computed(() => {
  return categories.value.map((category) => ({
    ...category,
    ruleCount: rules.value.filter((rule) => rule.category_id === category.id).length,
  }))
})

const visibleCategoryStats = computed(() => {
  const entries = hideEmptyCategories.value
    ? categoryStats.value.filter((category) => category.ruleCount > 0)
    : categoryStats.value

  return [...entries].sort((left, right) => {
    if (categorySort.value === 'name') {
      return left.name.localeCompare(right.name, 'de')
    }

    if (categorySort.value === 'type') {
      return (
        left.category_type.localeCompare(right.category_type, 'de') ||
        left.name.localeCompare(right.name, 'de')
      )
    }

    return right.ruleCount - left.ruleCount || left.name.localeCompare(right.name, 'de')
  })
})

const activeRuleCount = computed(() => rules.value.filter((rule) => rule.is_active).length)
const displayedTransactions = computed(() =>
  previewMode.value === 'all' ? allTransactions.value : previewTransactions.value,
)
const matchingTransactionIds = computed(
  () => new Set(previewTransactions.value.map((transaction) => transaction.id)),
)
const ruleHitCounts = computed(() => {
  const counts = new Map<number, number>()

  for (const rule of rules.value) {
    if (!rule.is_active) {
      counts.set(rule.id, 0)
      continue
    }

    const normalizedPattern = rule.pattern.trim().toLocaleLowerCase('de')

    if (!normalizedPattern) {
      counts.set(rule.id, 0)
      continue
    }

    const hits = allTransactions.value.filter((transaction) => {
      const description = (transaction.description ?? '').toLocaleLowerCase('de')
      const counterparty = (transaction.counterparty_name ?? '').toLocaleLowerCase('de')

      if (rule.match_field === 'description') {
        return description.includes(normalizedPattern)
      }

      if (rule.match_field === 'counterparty') {
        return counterparty.includes(normalizedPattern)
      }

      return description.includes(normalizedPattern) || counterparty.includes(normalizedPattern)
    }).length

    counts.set(rule.id, hits)
  }

  return counts
})

const previewGroups = computed(() => {
  const groups = new Map<
    string,
    { dateKey: string; dateLabel: string; items: PreviewTransactionItem[] }
  >()

  for (const transaction of displayedTransactions.value) {
    const dateKey = transaction.booking_date ?? 'ohne-datum'

    if (!groups.has(dateKey)) {
      groups.set(dateKey, {
        dateKey,
        dateLabel: formatDate(transaction.booking_date),
        items: [],
      })
    }

    groups.get(dateKey)?.items.push(transaction)
  }

  return Array.from(groups.values())
})

function resetCategoryForm() {
  editingCategoryId.value = null
  categorySubTab.value = 'form'
  categoryForm.value = {
    name: '',
    category_type: 'expense',
    color: '',
  }
}

function resetForm(nextCategoryId = '') {
  editingRuleId.value = null
  form.value = {
    category_id: String(nextCategoryId || categories.value[0]?.id || ''),
    name: '',
    pattern: '',
    match_field: 'both',
    priority: 100,
    is_active: true,
  }
}

function populateFormFromRule(rule: CategoryRuleItem) {
  editingRuleId.value = rule.id
  form.value = {
    category_id: String(rule.category_id),
    name: rule.name ?? '',
    pattern: rule.pattern,
    match_field: rule.match_field,
    priority: rule.priority,
    is_active: rule.is_active,
  }
}

function startCreateRule(category?: CategoryItem, showAllTransactions = false) {
  activeTab.value = 'editor'
  previewMode.value = showAllTransactions ? 'all' : 'matches'
  successMessage.value = ''
  error.value = ''
  previewSummary.value = null
  previewTransactions.value = []
  resetForm(category ? String(category.id) : '')
}

function showRulesForCategory(categoryId: number | string = 'all') {
  activeTab.value = 'rules'
  selectedRuleCategoryId.value = String(categoryId)
  successMessage.value = ''
  error.value = ''
}

function openCategory(category: CategoryItem & { ruleCount: number }) {
  if (category.ruleCount > 0) {
    showRulesForCategory(category.id)
    return
  }

  startCreateRule(category)
}

function startEditCategory(category: CategoryItem) {
  if (category.is_system) {
    return
  }

  categorySubTab.value = 'form'
  editingCategoryId.value = category.id
  categoryForm.value = {
    name: category.name,
    category_type: category.category_type,
    color: category.color ?? '',
  }

  successMessage.value = ''
  error.value = ''
}

function startEditRule(rule: CategoryRuleItem) {
  activeTab.value = 'editor'
  previewMode.value = 'matches'
  successMessage.value = ''
  error.value = ''
  populateFormFromRule(rule)
  void previewRule(false)
}

function formatMatchField(matchField: MatchField) {
  switch (matchField) {
    case 'description':
      return 'Nur Beschreibung'
    case 'counterparty':
      return 'Nur Gegenstelle'
    default:
      return 'Beschreibung + Gegenstelle'
  }
}

function formatCategoryType(categoryType: string) {
  switch (categoryType) {
    case 'income':
      return 'Einnahme'
    case 'expense':
      return 'Ausgabe'
    case 'transfer':
      return 'Transfer'
    default:
      return categoryType
  }
}

function formatCategorySource(categorySource: string | null) {
  switch (categorySource) {
    case 'manual':
      return 'Manuell'
    case 'rule':
      return 'Regel'
    default:
      return 'Ohne Kategorie'
  }
}

function formatImportSummary(summary: RuleImportSummary, prefix: string) {
  return `${prefix} ${summary.imported_rules} neu, ${summary.updated_rules} aktualisiert, ${summary.skipped_rows} übersprungen.`
}

function formatMoney(amount: number | string, currency = 'EUR') {
  return new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency,
  }).format(Number(amount))
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

function openImportPicker() {
  fileInput.value?.click()
}

async function exportRulesCsv() {
  if (!authStore.token) {
    return
  }

  exporting.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const csvContent = await apiFetch<string>('/api/category-rules/export', {}, authStore.token)
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = `category-rules-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()

    window.URL.revokeObjectURL(url)
    successMessage.value = 'Regelsatz als CSV exportiert.'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'CSV konnte nicht exportiert werden.'
  } finally {
    exporting.value = false
  }
}

async function importRulesFromFile(event: Event) {
  if (!authStore.token) {
    return
  }

  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]

  if (!file) {
    return
  }

  importing.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const csvContent = await file.text()
    const response = await apiFetch<{ summary: RuleImportSummary }>(
      '/api/category-rules/import',
      {
        method: 'POST',
        body: JSON.stringify({
          csv_content: csvContent,
          mode: importMode.value,
        }),
      },
      authStore.token,
    )

    await loadRules()
    successMessage.value = formatImportSummary(response.summary, 'CSV importiert.')
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'CSV konnte nicht importiert werden.'
  } finally {
    importing.value = false

    if (input) {
      input.value = ''
    }
  }
}

async function importDefaultRuleSet() {
  if (!authStore.token) {
    return
  }

  seedingDefaults.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const response = await apiFetch<{ summary: RuleImportSummary }>(
      '/api/category-rules/import-defaults',
      { method: 'POST' },
      authStore.token,
    )

    await loadRules()
    successMessage.value = formatImportSummary(response.summary, 'Default-Regeln importiert.')
  } catch (err) {
    error.value =
      err instanceof Error ? err.message : 'Default-Regeln konnten nicht importiert werden.'
  } finally {
    seedingDefaults.value = false
  }
}

async function resetAllRules() {
  if (!authStore.token) {
    return
  }

  if (
    !window.confirm(
      'Wirklich alle Regeln löschen? Diese Aktion betrifft nur deinen aktuellen Regelsatz.',
    )
  ) {
    return
  }

  resetting.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const response = await apiFetch<{ deleted_rules: number }>(
      '/api/category-rules/reset',
      { method: 'DELETE' },
      authStore.token,
    )

    await loadRules()
    startCreateRule()
    applySummary.value = null
    successMessage.value = `${response.deleted_rules} Regeln gelöscht.`
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regelsatz konnte nicht gelöscht werden.'
  } finally {
    resetting.value = false
  }
}

async function loadRules() {
  if (!authStore.token) {
    categories.value = []
    rules.value = []
    return
  }

  loading.value = true
  error.value = ''

  try {
    const response = await apiFetch<CategoryRulesResponse>(
      '/api/category-rules',
      {},
      authStore.token,
    )

    categories.value = response.categories
    rules.value = response.rules

    const hasSelectedCategory = response.categories.some(
      (category) => String(category.id) === form.value.category_id,
    )

    if (!hasSelectedCategory) {
      form.value.category_id = String(response.categories[0]?.id || '')
    }

    if (
      selectedRuleCategoryId.value !== 'all' &&
      !response.categories.some((category) => String(category.id) === selectedRuleCategoryId.value)
    ) {
      selectedRuleCategoryId.value = 'all'
    }
  } catch (err) {
    error.value =
      err instanceof Error ? err.message : 'Kategorien und Regeln konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function saveCategory() {
  if (!authStore.token) {
    return
  }

  categorySaving.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const payload = {
      name: categoryForm.value.name.trim(),
      category_type: categoryForm.value.category_type,
      color: categoryForm.value.color.trim() || null,
    }

    const isEditing = editingCategoryId.value !== null

    await apiFetch<{ category: CategoryItem }>(
      isEditing ? `/api/categories/${editingCategoryId.value}` : '/api/categories',
      {
        method: isEditing ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      },
      authStore.token,
    )

    await loadRules()
    resetCategoryForm()
    successMessage.value = isEditing ? 'Kategorie aktualisiert.' : 'Kategorie erstellt.'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Kategorie konnte nicht gespeichert werden.'
  } finally {
    categorySaving.value = false
  }
}

async function deleteCategory(category: CategoryItem) {
  if (!authStore.token || category.is_system) {
    return
  }

  if (!window.confirm(`Kategorie „${category.name}“ wirklich löschen?`)) {
    return
  }

  error.value = ''
  successMessage.value = ''

  try {
    await apiFetch<{ deleted: boolean }>(
      `/api/categories/${category.id}`,
      { method: 'DELETE' },
      authStore.token,
    )

    if (editingCategoryId.value === category.id) {
      resetCategoryForm()
    }

    await loadRules()
    successMessage.value = 'Kategorie gelöscht.'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Kategorie konnte nicht gelöscht werden.'
  }
}

async function loadAllTransactions() {
  if (!authStore.token) {
    allTransactions.value = []
    return
  }

  allTransactionsLoading.value = true
  error.value = ''

  try {
    const response = await apiFetch<{ transactions: PreviewTransactionItem[] }>(
      '/api/dashboard?view=all',
      {},
      authStore.token,
    )

    allTransactions.value = response.transactions ?? []
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Transaktionen konnten nicht geladen werden.'
  } finally {
    allTransactionsLoading.value = false
  }
}

function setPreviewMode(mode: PreviewMode) {
  previewMode.value = mode

  if (mode === 'all' && !allTransactions.value.length && !allTransactionsLoading.value) {
    void loadAllTransactions()
  }
}

async function previewRule(showSuccess = true) {
  if (!authStore.token) {
    return
  }

  const pattern = form.value.pattern.trim()

  if (!pattern) {
    previewSummary.value = null
    previewTransactions.value = []
    return
  }

  previewLoading.value = true
  error.value = ''

  try {
    const response = await apiFetch<RulePreviewResponse>(
      '/api/category-rules/preview',
      {
        method: 'POST',
        body: JSON.stringify({
          category_id: form.value.category_id ? Number(form.value.category_id) : null,
          pattern,
          match_field: form.value.match_field,
          match_type: 'contains',
        }),
      },
      authStore.token,
    )

    previewSummary.value = response.summary
    previewTransactions.value = response.transactions

    if (showSuccess) {
      successMessage.value = `Regeltest aktualisiert: ${response.summary.matched_transactions} Treffer.`
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regel konnte nicht getestet werden.'
  } finally {
    previewLoading.value = false
  }
}

async function saveRule() {
  if (!authStore.token) {
    return
  }

  saving.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const payload = {
      category_id: Number(form.value.category_id),
      name: form.value.name.trim() || null,
      pattern: form.value.pattern.trim(),
      match_field: form.value.match_field,
      match_type: 'contains' as const,
      priority: Number(form.value.priority),
      is_active: form.value.is_active,
    }

    const isEditing = editingRuleId.value !== null
    const response = await apiFetch<{ rule: CategoryRuleItem }>(
      isEditing ? `/api/category-rules/${editingRuleId.value}` : '/api/category-rules',
      {
        method: isEditing ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      },
      authStore.token,
    )

    const index = rules.value.findIndex((rule) => rule.id === response.rule.id)

    if (index >= 0) {
      rules.value.splice(index, 1, response.rule)
    } else {
      rules.value.unshift(response.rule)
    }

    populateFormFromRule(response.rule)
    activeTab.value = 'editor'
    successMessage.value = isEditing ? 'Regel aktualisiert.' : 'Regel gespeichert.'
    await previewRule(false)
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regel konnte nicht gespeichert werden.'
  } finally {
    saving.value = false
  }
}

async function toggleRule(rule: CategoryRuleItem) {
  if (!authStore.token) {
    return
  }

  error.value = ''
  successMessage.value = ''

  try {
    const response = await apiFetch<{ rule: CategoryRuleItem }>(
      `/api/category-rules/${rule.id}`,
      {
        method: 'PATCH',
        body: JSON.stringify({ is_active: !rule.is_active }),
      },
      authStore.token,
    )

    const index = rules.value.findIndex((entry) => entry.id === rule.id)

    if (index >= 0) {
      rules.value.splice(index, 1, response.rule)
    }

    successMessage.value = response.rule.is_active ? 'Regel aktiviert.' : 'Regel pausiert.'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regel konnte nicht geändert werden.'
  }
}

async function deleteRule(rule: CategoryRuleItem) {
  if (!authStore.token) {
    return
  }

  if (!window.confirm(`Regel „${rule.pattern}“ wirklich löschen?`)) {
    return
  }

  error.value = ''
  successMessage.value = ''

  try {
    await apiFetch<{ deleted: boolean }>(
      `/api/category-rules/${rule.id}`,
      { method: 'DELETE' },
      authStore.token,
    )

    rules.value = rules.value.filter((entry) => entry.id !== rule.id)

    if (editingRuleId.value === rule.id) {
      startCreateRule()
    }

    successMessage.value = 'Regel gelöscht.'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regel konnte nicht gelöscht werden.'
  }
}

async function applyRules() {
  if (!authStore.token) {
    return
  }

  applying.value = true
  error.value = ''
  successMessage.value = ''

  try {
    const response = await apiFetch<ApplyRulesResponse>(
      '/api/category-rules/apply',
      { method: 'POST' },
      authStore.token,
    )

    applySummary.value = response.summary
    successMessage.value = 'Automatische Kategorisierung wurde ausgeführt.'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Regeln konnten nicht angewendet werden.'
  } finally {
    applying.value = false
  }
}

watch(successMessage, (message) => {
  if (successMessageTimer) {
    clearTimeout(successMessageTimer)
    successMessageTimer = null
  }

  if (!message) {
    return
  }

  successMessageTimer = setTimeout(() => {
    successMessage.value = ''
    successMessageTimer = null
  }, 3000)
})

watch(
  () => authStore.token,
  async (token) => {
    if (!token) {
      categories.value = []
      rules.value = []
      previewTransactions.value = []
      allTransactions.value = []
      previewMode.value = 'matches'
      selectedRuleCategoryId.value = 'all'
      resetCategoryForm()
      resetForm()
      applySummary.value = null
      previewSummary.value = null
      return
    }

    await loadRules()
    await loadAllTransactions()
  },
  { immediate: true },
)
</script>

<template>
  <section class="stack">
    <article class="card hero hero--compact">
      <p class="eyebrow">Kategorien & Regeln</p>
      <p class="hero-copy">
        Regeln testen, Kategorien pflegen und den Regelsatz zentral verwalten.
      </p>
    </article>

    <article v-if="!authStore.isAuthenticated" class="card">
      <h3>Anmelden, um Regeln zu verwalten</h3>
      <p>Die automatische Kategorisierung ist nur im eingeloggten Bereich verfügbar.</p>
      <RouterLink class="primary-link" to="/login">Zum Login</RouterLink>
    </article>

    <template v-else>
      <article v-if="loading" class="card">
        <p>Lade Kategorien und Regeln…</p>
      </article>

      <article v-else-if="error && !categories.length && !rules.length" class="card warning">
        <p>{{ error }}</p>
      </article>

      <section v-else class="workbench">
        <aside class="card workbench-sidebar">
          <div class="tab-strip">
            <button
              class="tab-button"
              :class="{ 'is-active': activeTab === 'categories' }"
              type="button"
              @click="activeTab = 'categories'"
            >
              Kategorien
            </button>
            <button
              class="tab-button"
              :class="{ 'is-active': activeTab === 'rules' }"
              type="button"
              @click="activeTab = 'rules'"
            >
              Regeln
            </button>
            <button
              class="tab-button"
              :class="{ 'is-active': activeTab === 'datasets' }"
              type="button"
              @click="activeTab = 'datasets'"
            >
              Import / Export
            </button>
            <button
              class="tab-button"
              :class="{ 'is-active': activeTab === 'editor' }"
              type="button"
              @click="activeTab = 'editor'"
            >
              {{ editingRuleId ? 'Regel bearbeiten' : 'Neue Regel' }}
            </button>
          </div>

          <div v-if="successMessage && activeTab !== 'categories'" class="success-note">
            {{ successMessage }}
          </div>
          <div v-if="error" class="warning-note">{{ error }}</div>

          <template v-if="activeTab === 'categories'">
            <div class="subtab-strip">
              <button
                class="subtab-button"
                :class="{ 'is-active': categorySubTab === 'overview' }"
                type="button"
                @click="categorySubTab = 'overview'"
              >
                Kategorisierung
              </button>
              <button
                class="subtab-button"
                :class="{ 'is-active': categorySubTab === 'list' }"
                type="button"
                @click="categorySubTab = 'list'"
              >
                Deine Kategorien
              </button>
              <button
                class="subtab-button"
                :class="{ 'is-active': categorySubTab === 'form' }"
                type="button"
                @click="categorySubTab = 'form'"
              >
                {{ editingCategoryId ? 'Kategorie bearbeiten' : 'Neue Kategorie' }}
              </button>
            </div>

            <template v-if="categorySubTab === 'overview'">
              <div class="section-header section-gap">
                <div>
                  <h3>Kategorisierung</h3>
                  <p class="muted">
                    Hier führst du deine Regeln aus und behältst Treffer, aktive Regeln und
                    Kategorien im Blick.
                  </p>
                </div>
              </div>

              <div v-if="successMessage" class="success-note">{{ successMessage }}</div>

              <div class="stats-grid">
                <div class="mini-stat">
                  <span>Regeln gesamt</span>
                  <strong>{{ rules.length }}</strong>
                </div>
                <div class="mini-stat">
                  <span>Aktiv</span>
                  <strong>{{ activeRuleCount }}</strong>
                </div>
                <div class="mini-stat">
                  <span>Kategorien</span>
                  <strong>{{ categories.length }}</strong>
                </div>
              </div>

              <button
                class="primary-button apply-button"
                type="button"
                :disabled="applying || activeRuleCount === 0"
                @click="applyRules"
              >
                {{ applying ? 'Wird angewendet…' : 'Automatische Kategorisierung ausführen' }}
              </button>

              <div v-if="applySummary" class="summary-box">
                <p><strong>Treffer:</strong> {{ applySummary.matched_transactions }}</p>
                <p><strong>Aktualisiert:</strong> {{ applySummary.updated_transactions }}</p>
                <p>
                  <strong>Manuell übersprungen:</strong>
                  {{ applySummary.skipped_manual_transactions }}
                </p>
              </div>
            </template>

            <template v-else-if="categorySubTab === 'form'">
              <div class="section-header section-gap">
                <div>
                  <h3>
                    {{ editingCategoryId ? 'Kategorie bearbeiten' : 'Neue Kategorie anlegen' }}
                  </h3>
                  <p class="muted">
                    Eigene Kategorien kannst du hier anlegen, anpassen oder wieder entfernen.
                  </p>
                </div>
                <button
                  class="ghost-button small-button"
                  type="button"
                  @click="resetCategoryForm()"
                >
                  Neue Kategorie
                </button>
              </div>

              <form class="rule-form" @submit.prevent="saveCategory">
                <label>
                  <span>Name</span>
                  <input
                    v-model="categoryForm.name"
                    type="text"
                    placeholder="z. B. Freizeit"
                    required
                  />
                </label>

                <label>
                  <span>Typ</span>
                  <select v-model="categoryForm.category_type">
                    <option value="expense">Ausgabe</option>
                    <option value="income">Einnahme</option>
                    <option value="transfer">Transfer</option>
                  </select>
                </label>

                <label>
                  <span>Farbe optional</span>
                  <input v-model="categoryForm.color" type="text" placeholder="#f97316" />
                </label>

                <div class="form-actions">
                  <button class="primary-button" type="submit" :disabled="categorySaving">
                    {{
                      categorySaving
                        ? 'Speichert…'
                        : editingCategoryId
                          ? 'Kategorie aktualisieren'
                          : 'Kategorie speichern'
                    }}
                  </button>
                  <button
                    v-if="currentEditableCategory"
                    class="danger-button"
                    type="button"
                    @click="deleteCategory(currentEditableCategory)"
                  >
                    Kategorie löschen
                  </button>
                </div>
              </form>
            </template>

            <template v-else>
              <div class="section-header section-gap">
                <div>
                  <h3>Kategorien</h3>
                  <p class="muted">
                    Ein Klick zeigt entweder die vorhandenen Regeln oder legt direkt eine neue Regel
                    für diese Kategorie an.
                  </p>
                </div>
                <div class="category-list-toolbar">
                  <label class="inline-field inline-field--compact">
                    <span>Sortierung</span>
                    <select v-model="categorySort">
                      <option value="rules">Meiste Regeln zuerst</option>
                      <option value="name">Name A–Z</option>
                      <option value="type">Typ</option>
                    </select>
                  </label>
                  <label class="toggle-chip">
                    <input v-model="hideEmptyCategories" type="checkbox" />
                    <span>Kategorien ohne Regeln ausblenden</span>
                  </label>
                </div>
              </div>

              <div class="category-list compact-grid">
                <article
                  v-for="category in visibleCategoryStats"
                  :key="category.id"
                  class="category-card"
                >
                  <button class="category-card__main" type="button" @click="openCategory(category)">
                    <div>
                      <strong>{{ category.name }}</strong>
                      <p>{{ formatCategoryType(category.category_type) }}</p>
                    </div>
                    <span>{{ category.ruleCount }} Regeln</span>
                  </button>

                  <div class="category-card__actions">
                    <span v-if="category.is_system" class="status-pill">System</span>
                    <template v-else>
                      <button
                        class="ghost-button small-button"
                        type="button"
                        @click="startEditCategory(category)"
                      >
                        Bearbeiten
                      </button>
                      <button
                        class="danger-button small-button"
                        type="button"
                        @click="deleteCategory(category)"
                      >
                        Löschen
                      </button>
                    </template>
                  </div>
                </article>
              </div>
              <p v-if="!visibleCategoryStats.length" class="muted preview-empty">
                {{
                  hideEmptyCategories
                    ? 'Aktuell gibt es keine Kategorien mit Regeln.'
                    : 'Noch keine Kategorien vorhanden.'
                }}
              </p>
            </template>
          </template>

          <template v-else-if="activeTab === 'rules'">
            <div class="section-header">
              <div>
                <h3>Vorhandene Regeln</h3>
                <p class="muted">
                  Regeln sind nach Kategorien sortiert. Über den Filter kannst du gezielt nur eine
                  Kategorie ansehen.
                </p>
              </div>
            </div>

            <div class="dataset-toolbar">
              <label class="inline-field">
                <span>Kategorie filtern</span>
                <select v-model="selectedRuleCategoryId">
                  <option value="all">Alle Kategorien</option>
                  <option
                    v-for="category in categories"
                    :key="category.id"
                    :value="String(category.id)"
                  >
                    {{ category.name }}
                  </option>
                </select>
              </label>
            </div>

            <div v-if="filteredRules.length" class="rule-list">
              <article v-for="rule in filteredRules" :key="rule.id" class="rule-card">
                <div class="rule-card__top">
                  <div class="rule-card__headline">
                    <strong>{{ rule.category_name || '—' }}</strong>
                    <span class="muted small-text">
                      · {{ rule.name || formatMatchField(rule.match_field) }}
                    </span>
                  </div>
                  <div class="rule-card__badges">
                    <span v-if="rule.is_active" class="status-pill rule-hit-pill">
                      {{ ruleHitCounts.get(rule.id) ?? 0 }} Treffer
                    </span>
                    <span class="status-pill" :class="{ inactive: !rule.is_active }">
                      {{ rule.is_active ? 'Aktiv' : 'Pausiert' }}
                    </span>
                  </div>
                </div>

                <p class="rule-pattern">
                  <code>{{ rule.pattern }}</code>
                </p>
                <p class="muted small-text">
                  Suche in {{ formatMatchField(rule.match_field) }} · Priorität {{ rule.priority }}
                </p>

                <div class="table-actions">
                  <button
                    class="ghost-button small-button"
                    type="button"
                    @click="startEditRule(rule)"
                  >
                    Bearbeiten
                  </button>
                  <button class="ghost-button small-button" type="button" @click="toggleRule(rule)">
                    {{ rule.is_active ? 'Pausieren' : 'Aktivieren' }}
                  </button>
                  <button
                    class="danger-button small-button"
                    type="button"
                    @click="deleteRule(rule)"
                  >
                    Löschen
                  </button>
                </div>
              </article>
            </div>
            <p v-else>
              Für {{ selectedRuleCategory?.name || 'diese Kategorie' }} gibt es noch keine Regeln.
            </p>
          </template>

          <template v-else-if="activeTab === 'datasets'">
            <div class="section-header">
              <div>
                <h3>CSV Import / Export</h3>
                <p class="muted">
                  Hier kannst du deinen kompletten Regelsatz sichern, importieren, mit
                  Default-Regeln starten oder alles zurücksetzen.
                </p>
              </div>
            </div>

            <div class="dataset-toolbar">
              <label class="inline-field">
                <span>CSV-Import-Modus</span>
                <select v-model="importMode">
                  <option value="merge">Ergänzen / aktualisieren</option>
                  <option value="replace">Vorher ersetzen</option>
                </select>
              </label>

              <div class="form-actions">
                <button
                  class="primary-button"
                  type="button"
                  :disabled="seedingDefaults"
                  @click="importDefaultRuleSet"
                >
                  {{ seedingDefaults ? 'Importiert…' : 'Default-Regeln importieren' }}
                </button>
                <button
                  class="ghost-button"
                  type="button"
                  :disabled="exporting || !rules.length"
                  @click="exportRulesCsv"
                >
                  {{ exporting ? 'Exportiert…' : 'CSV exportieren' }}
                </button>
                <button
                  class="ghost-button"
                  type="button"
                  :disabled="importing"
                  @click="openImportPicker"
                >
                  {{ importing ? 'Importiert…' : 'CSV importieren' }}
                </button>
                <button
                  class="danger-button"
                  type="button"
                  :disabled="resetting || !rules.length"
                  @click="resetAllRules"
                >
                  {{ resetting ? 'Löscht…' : 'Alle Regeln löschen' }}
                </button>
              </div>
            </div>

            <input
              ref="fileInput"
              class="visually-hidden"
              type="file"
              accept=".csv,text/csv"
              @change="importRulesFromFile"
            />

            <div class="summary-box quickstart-box">
              <p>
                <strong>Schnellstart:</strong> Importiere Default-Regeln als Ausgangspunkt oder
                sichere deinen aktuellen Regelsatz als CSV.
              </p>
            </div>
          </template>

          <template v-else>
            <div class="section-header">
              <div>
                <h3>{{ editingRuleId ? 'Regel bearbeiten' : 'Neue Regel anlegen' }}</h3>
                <p class="muted">
                  Teste die Regel rechts gegen echte Buchungen, bevor du sie speicherst.
                </p>
              </div>
            </div>

            <form class="rule-form" @submit.prevent="saveRule">
              <label>
                <span>Kategorie</span>
                <select v-model="form.category_id" required>
                  <option value="" disabled>Bitte wählen</option>
                  <option
                    v-for="category in categories"
                    :key="category.id"
                    :value="String(category.id)"
                  >
                    {{ category.name }} · {{ formatCategoryType(category.category_type) }}
                  </option>
                </select>
              </label>

              <label>
                <span>Interner Name optional</span>
                <input v-model="form.name" type="text" placeholder="z. B. Gehaltseingang" />
              </label>

              <label>
                <span>Suchstring</span>
                <input v-model="form.pattern" type="text" placeholder="z. B. lohn" required />
              </label>

              <label>
                <span>Suche in</span>
                <select v-model="form.match_field">
                  <option value="both">Beschreibung + Gegenstelle</option>
                  <option value="description">Nur Beschreibung</option>
                  <option value="counterparty">Nur Gegenstelle</option>
                </select>
              </label>

              <label>
                <span>Priorität</span>
                <input v-model.number="form.priority" type="number" min="0" max="1000" />
              </label>

              <label class="checkbox-row">
                <input v-model="form.is_active" type="checkbox" />
                <span>Regel aktiv</span>
              </label>

              <div class="form-actions">
                <button
                  class="ghost-button"
                  type="button"
                  :disabled="previewLoading"
                  @click="previewRule()"
                >
                  {{ previewLoading ? 'Testet…' : 'Regel testen' }}
                </button>
                <button class="primary-button" type="submit" :disabled="saving">
                  {{
                    saving
                      ? 'Speichert…'
                      : editingRuleId
                        ? 'Regel aktualisieren'
                        : 'Regel speichern'
                  }}
                </button>
                <button
                  v-if="editingRuleId"
                  class="danger-button"
                  type="button"
                  @click="
                    deleteRule({
                      id: editingRuleId,
                      category_id: Number(form.category_id),
                      category_name: selectedRuleCategory?.name ?? null,
                      category_color: null,
                      name: form.name || null,
                      pattern: form.pattern,
                      match_field: form.match_field,
                      match_type: 'contains',
                      priority: form.priority,
                      is_active: form.is_active,
                      created_at: null,
                      updated_at: null,
                      category_rule_id: null,
                    } as CategoryRuleItem)
                  "
                >
                  Regel löschen
                </button>
                <button
                  class="ghost-button"
                  type="button"
                  @click="startCreateRule(undefined, true)"
                >
                  Neu beginnen
                </button>
              </div>
            </form>

            <div v-if="previewSummary" class="summary-box">
              <p><strong>Treffer:</strong> {{ previewSummary.matched_transactions }}</p>
              <p>
                <strong>Kategorie:</strong>
                {{ previewSummary.category_name || 'Noch nicht gewählt' }}
              </p>
              <p><strong>Suche in:</strong> {{ formatMatchField(previewSummary.match_field) }}</p>
            </div>
          </template>
        </aside>

        <section class="card workbench-preview">
          <div class="section-header">
            <div>
              <h3>
                {{ previewMode === 'all' ? 'Alle Transaktionen' : 'Treffer zur aktuellen Regel' }}
              </h3>
              <p class="muted">
                {{
                  previewMode === 'all'
                    ? 'Du siehst alle Buchungen. Mit dem Schalter kannst du wieder nur die Treffer der aktuellen Regel anzeigen.'
                    : 'Hier siehst du direkt, welche Buchungen von deinem aktuellen Suchstring erfasst werden.'
                }}
              </p>
            </div>
            <div class="preview-toolbar">
              <div class="preview-switch">
                <button
                  class="ghost-button small-button"
                  :class="{ 'is-active': previewMode === 'matches' }"
                  type="button"
                  @click="setPreviewMode('matches')"
                >
                  Regel testen
                </button>
                <button
                  class="ghost-button small-button"
                  :class="{ 'is-active': previewMode === 'all' }"
                  type="button"
                  @click="setPreviewMode('all')"
                >
                  Alle Transaktionen
                </button>
              </div>
              <span class="status-pill">
                {{
                  previewMode === 'all'
                    ? `${displayedTransactions.length} Buchungen`
                    : `${previewSummary?.matched_transactions ?? displayedTransactions.length} Treffer`
                }}
              </span>
            </div>
          </div>

          <p v-if="previewMode === 'all' && allTransactionsLoading" class="muted preview-empty">
            Alle Transaktionen werden geladen…
          </p>
          <p
            v-else-if="
              previewMode !== 'all' && activeTab !== 'editor' && !previewTransactions.length
            "
            class="muted preview-empty"
          >
            Wähle links eine Regel aus oder erstelle eine neue. Im Editor kannst du die Regel
            testen.
          </p>
          <p v-else-if="previewMode !== 'all' && previewLoading" class="muted preview-empty">
            Treffer werden gesucht…
          </p>
          <p v-else-if="!displayedTransactions.length" class="muted preview-empty">
            {{
              previewMode === 'all'
                ? 'Es sind noch keine Buchungen vorhanden.'
                : 'Für die aktuelle Regel wurden noch keine passenden Buchungen gefunden.'
            }}
          </p>

          <div v-else class="preview-list">
            <section v-for="group in previewGroups" :key="group.dateKey" class="day-group">
              <header class="day-header">
                <strong>{{ group.dateLabel }}</strong>
                <span class="day-balance">
                  {{
                    previewMode === 'all'
                      ? `${group.items.length} Buchungen`
                      : `${group.items.length} Treffer`
                  }}
                </span>
              </header>

              <article v-for="transaction in group.items" :key="transaction.id" class="preview-row">
                <div class="transaction-main">
                  <strong>{{ transaction.counterparty_name || 'Ohne Gegenstelle' }}</strong>
                  <p>
                    {{ transaction.description || formatSourceType(transaction.source_system) }}
                  </p>
                  <span class="muted small-text">
                    {{ transaction.account_name || '—' }} ·
                    {{ formatSourceType(transaction.source_system) }}
                  </span>
                </div>
                <div class="transaction-meta">
                  <span
                    v-if="previewMode === 'all' && form.pattern.trim()"
                    class="status-pill"
                    :class="{ inactive: !matchingTransactionIds.has(transaction.id) }"
                  >
                    {{
                      matchingTransactionIds.has(transaction.id) ? 'Trifft Regel' : 'Kein Treffer'
                    }}
                  </span>
                  <span
                    class="status-pill"
                    :class="{ inactive: (transaction.category_source ?? 'none') === 'none' }"
                  >
                    {{ formatCategorySource(transaction.category_source) }}
                    <template v-if="transaction.category_name">
                      · {{ transaction.category_name }}
                    </template>
                  </span>
                  <strong :class="transaction.direction === 'credit' ? 'positive' : 'negative'">
                    {{ formatMoney(transaction.amount, transaction.currency) }}
                  </strong>
                </div>
              </article>
            </section>
          </div>
        </section>
      </section>
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

.hero--compact {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding-block: 0.8rem;
}

.hero--compact .eyebrow,
.hero-copy {
  margin: 0;
}

.hero-copy {
  color: var(--color-text-muted);
}

.workbench {
  display: grid;
  gap: 1rem;
  align-items: start;
}

.workbench-sidebar,
.workbench-preview {
  min-height: 620px;
}

.workbench-sidebar {
  display: grid;
  align-content: start;
  gap: 0.9rem;
  max-height: min(760px, calc(100vh - 12rem));
  overflow-y: auto;
  padding-right: 0.2rem;
}

.workbench-preview {
  display: grid;
  align-content: start;
  gap: 0.75rem;
}

.preview-toolbar {
  display: grid;
  gap: 0.5rem;
  justify-items: end;
}

.preview-switch {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.preview-switch .ghost-button.is-active {
  background: var(--color-accent-strong);
  color: white;
  border-color: transparent;
}

.tab-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.5rem;
  position: sticky;
  top: 0;
  z-index: 2;
  padding-bottom: 0.1rem;
  background: var(--color-surface);
}

.subtab-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}

.subtab-button {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.55rem 0.7rem;
  font-weight: 600;
}

.subtab-button.is-active {
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  border-color: transparent;
}

.tab-button {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.65rem 0.75rem;
  font-weight: 700;
}

.tab-button.is-active {
  background: var(--color-accent-strong);
  color: white;
  border-color: transparent;
}

.eyebrow {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  color: var(--color-accent-strong);
}

.section-header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: start;
}

.section-gap {
  margin-top: 0.35rem;
}

.category-list-toolbar {
  display: flex;
  gap: 0.6rem;
  align-items: flex-end;
  flex-wrap: wrap;
}

.inline-field--compact {
  min-width: 12rem;
}

.dataset-toolbar,
.rule-form,
.category-list,
.rule-list,
.preview-list {
  display: grid;
  gap: 0.85rem;
}

.compact-grid {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.inline-field {
  display: grid;
  gap: 0.35rem;
  max-width: 20rem;
}

.rule-form label {
  display: grid;
  grid-template-columns: minmax(130px, 160px) minmax(0, 1fr);
  gap: 0.75rem;
  align-items: center;
}

.rule-form span,
.inline-field span,
.small-text,
.muted {
  color: var(--color-text-muted);
}

.inline-field select,
.rule-form input,
.rule-form select {
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface-strong);
  color: var(--color-text);
  padding: 0.7rem 0.85rem;
}

.checkbox-row {
  display: flex !important;
  gap: 0.5rem;
  align-items: center;
}

.checkbox-row input {
  width: auto;
}

.form-actions,
.table-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.primary-button,
.ghost-button,
.danger-button,
.primary-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  padding: 0.6rem 0.95rem;
  font-weight: 700;
  text-decoration: none;
}

.primary-button,
.primary-link {
  border: 0;
  background: var(--color-accent-strong);
  color: white;
}

.ghost-button {
  border: 1px solid var(--color-border);
  background: var(--color-surface-strong);
  color: var(--color-text);
}

.danger-button {
  border: 1px solid transparent;
  background: color-mix(in srgb, var(--color-danger) 14%, transparent);
  color: var(--color-danger);
}

.small-button {
  padding: 0.45rem 0.7rem;
  font-size: 0.85rem;
}

.apply-button {
  margin-top: 0.15rem;
}

.stats-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
}

.mini-stat,
.summary-box,
.category-card,
.rule-card,
.preview-row {
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface-strong);
}

.quickstart-box {
  margin-top: 0.1rem;
}

.mini-stat span {
  display: block;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.mini-stat strong {
  display: block;
  margin-top: 0.2rem;
  font-size: 1.35rem;
}

.category-card {
  display: grid;
  gap: 0.7rem;
  min-height: 82px;
}

.category-card__main {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
  text-align: left;
  border: 0;
  background: transparent;
  color: inherit;
  padding: 0;
}

.category-card__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.45rem;
  flex-wrap: wrap;
}

.toggle-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.55rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: var(--color-surface-strong);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.toggle-chip input {
  width: auto;
}

.category-card p,
.rule-pattern,
.day-header,
.preview-empty {
  margin: 0;
}

.rule-card {
  display: grid;
  gap: 0.6rem;
}

.rule-card__top,
.day-header {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
}

.rule-card__headline {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
  min-width: 0;
}

.rule-card__headline strong,
.rule-card__headline span {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rule-card__badges {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.rule-hit-pill {
  background: var(--color-background-soft);
  color: var(--color-text-muted);
}

.status-pill,
.day-balance {
  display: inline-flex;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  background: var(--color-accent-soft);
  color: var(--color-accent-strong);
  font-size: 0.78rem;
  font-weight: 700;
}

.day-balance {
  background: var(--color-background-soft);
  color: var(--color-text-muted);
}

.status-pill.inactive {
  background: var(--color-surface-strong);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border);
}

.preview-list {
  max-height: min(760px, calc(100vh - 12rem));
  overflow-y: auto;
  padding-right: 0.2rem;
}

.day-group {
  display: grid;
  gap: 0.5rem;
}

.preview-row {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: start;
}

.transaction-main {
  min-width: 0;
}

.transaction-main p {
  display: -webkit-box;
  line-clamp: 2;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
  margin: 0.2rem 0;
}

.transaction-meta {
  display: grid;
  justify-items: end;
  gap: 0.35rem;
}

.success-note,
.warning-note {
  padding: 0.7rem 0.85rem;
  border-radius: 12px;
}

.success-note {
  background: color-mix(in srgb, #059669 12%, transparent);
  color: #047857;
}

.warning,
.warning-note {
  background: var(--color-warning-soft);
  color: var(--color-warning);
}

.positive {
  color: #059669;
}

.negative {
  color: var(--color-danger);
}

code {
  font-family: inherit;
  font-weight: 700;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (min-width: 1100px) {
  .workbench {
    grid-template-columns: minmax(520px, 1.15fr) minmax(0, 1fr);
  }
}

@media (max-width: 720px) {
  .form-actions,
  .table-actions,
  .rule-card__top,
  .rule-card__badges,
  .day-header,
  .preview-row,
  .category-card__main,
  .category-card__actions,
  .toggle-chip,
  .hero--compact,
  .category-list-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .rule-form label {
    grid-template-columns: 1fr;
    gap: 0.35rem;
    align-items: stretch;
  }

  .tab-strip,
  .subtab-strip {
    grid-template-columns: 1fr;
  }

  .workbench-sidebar,
  .preview-list {
    max-height: none;
    overflow: visible;
  }

  .transaction-meta {
    justify-items: start;
  }
}
</style>
