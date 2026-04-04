import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { ApiError, apiFetch } from '@/lib/api'

export interface AuthUser {
  id: number
  name: string
  email: string
}

const tokenStorageKey = 'finanzen.auth.token'

function toErrorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError && typeof err.payload === 'object' && err.payload !== null) {
    const payload = err.payload as { errors?: Record<string, string[]>; message?: string }
    const firstValidationError = Object.values(payload.errors ?? {})[0]?.[0]

    return firstValidationError ?? payload.message ?? fallback
  }

  return err instanceof Error ? err.message : fallback
}

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

  function clearError() {
    error.value = null
  }

  function clearSession() {
    token.value = ''
    user.value = null
    persistToken()
  }

  async function login(email: string, password: string) {
    loading.value = true
    clearError()

    try {
      const response = await apiFetch<{ token: string; user: AuthUser }>('/api/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })

      token.value = response.token
      user.value = response.user
      persistToken()
    } catch (err) {
      error.value = toErrorMessage(err, 'Login failed.')
      throw err
    } finally {
      loading.value = false
    }
  }

  async function register(
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
  ) {
    loading.value = true
    clearError()

    try {
      const response = await apiFetch<{ token: string; user: AuthUser }>('/api/register', {
        method: 'POST',
        body: JSON.stringify({
          name,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      })

      token.value = response.token
      user.value = response.user
      persistToken()
    } catch (err) {
      error.value = toErrorMessage(err, 'Registration failed.')
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
    clearError()
  }

  return {
    token,
    user,
    loading,
    error,
    isAuthenticated,
    login,
    register,
    fetchMe,
    logout,
    clearError,
  }
})
