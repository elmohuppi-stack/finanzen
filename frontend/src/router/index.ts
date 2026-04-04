import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      alias: '/dashboard',
      name: 'dashboard',
      component: HomeView,
    },
    {
      path: '/transactions',
      name: 'transactions',
      component: () => import('../views/TransactionsView.vue'),
    },
    {
      path: '/analysis',
      name: 'analysis',
      component: () => import('../views/AnalysisView.vue'),
    },
    {
      path: '/imports',
      alias: '/imports/preview',
      name: 'imports',
      component: () => import('../views/ImportPreviewView.vue'),
    },
    {
      path: '/help',
      name: 'help',
      component: () => import('../views/HelpView.vue'),
    },
    {
      path: '/about',
      redirect: '/help',
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
    },
  ],
})

export default router
