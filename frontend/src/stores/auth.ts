import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { apiFetch } from '@/lib/api'

export interface AuthUser {
  id: number
  name: string
  email: string
}

const tokenStorageKey = 'finanzen.auth.token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string>(localStorage.getItem(tokenStorageKey) ?? '')
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isAuthenticated = computed(() => token.value.length > 0)

  function persistToken() {
    if (token.value) {
      localStorage.setItem(tokenStorageKey, token.value)
      return
    }

    localStorage.removeItem(tokenStorageKey)
  }

  function clearSession() {
    token.value = ''
    user.value = null
    persistToken()
  }

  async function login(email: string, password: string) {
    loading.value = true
    error.value = null

    try {
      const response = await apiFetch<{ token: string; user: AuthUser }>('/api/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })

      token.value = response.token
      user.value = response.user
      persistToken()
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Login failed.'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    if (!token.value) {
      return
    }

    try {
      const response = await apiFetch<{ user: AuthUser }>('/api/me', {}, token.value)
      user.value = response.user
    } catch {
      clearSession()
    }
  }

  async function logout() {
    if (token.value) {
      try {
        await apiFetch('/api/logout', { method: 'POST' }, token.value)
      } catch {
        // ignore logout transport issues and clear the local session anyway
      }
    }

    clearSession()
  }

  return {
    token,
    user,
    loading,
    error,
    isAuthenticated,
    login,
    fetchMe,
    logout,
  }
})
