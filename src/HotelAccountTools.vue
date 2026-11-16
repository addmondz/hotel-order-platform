<script setup>
import { ref, watch } from 'vue'
import { api } from './api'
import TransactionHistory from './TransactionHistory.vue'

const props = defineProps({ hotel: { type: Object, required: true } })
const emit = defineEmits(['sign-in-needed', 'open-order'])
const account = ref(null)
const username = ref('')
const password = ref('')
const loading = ref(true)
const saving = ref(false)
const editing = ref(false)
const error = ref('')
const notice = ref('')
let requestId = 0

async function refresh(id) {
  const request = ++requestId
  account.value = null
  editing.value = false
  password.value = ''
  error.value = ''
  notice.value = ''
  loading.value = true
  try {
    const result = await api('/customers/' + id + '/portal-account')
    if (request !== requestId) return
    account.value = result.account
  } catch (e) {
    if (request !== requestId) return
    if (e.status === 401) emit('sign-in-needed')
    else error.value = e.message
  } finally {
    if (request === requestId) loading.value = false
  }
}
watch(() => props.hotel.id, refresh, { immediate: true })

function edit() {
  username.value = account.value?.username || ''
  password.value = ''
  error.value = ''
  notice.value = ''
  editing.value = true
}
function close() {
  password.value = ''
  editing.value = false
  error.value = ''
}
async function save() {
  if (saving.value) return
  const id = props.hotel.id
  const request = requestId
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await api('/customers/' + id + '/portal-account', 'PUT', { username: username.value.trim(), password: password.value })
    if (request !== requestId) return
    account.value = result.account
    close()
    notice.value = 'Customer portal login saved.'
  } catch (e) {
    if (request !== requestId) return
    if (e.status === 401) emit('sign-in-needed')
    else error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="hotel-tools">
    <section class="panel padded" aria-labelledby="hotel-owner-access">
      <header class="section-row">
        <div>
          <h2 id="hotel-owner-access">Customer portal access</h2>
          <p class="hint">Optional login for this customer. They sign in on the home page.</p>
        </div>
        <button v-if="!loading && !editing && !error" type="button" class="secondary" @click="edit">
          {{ account ? 'Change login or password' : 'Create owner login' }}
        </button>
      </header>
      <p v-if="loading" class="hint" role="status">Checking portal access…</p>
      <p v-else-if="account && !editing" class="account-login"><span class="hint">Login:</span> <strong>{{ account.username }}</strong></p>
      <p v-else-if="!editing && !error" class="hint">No owner login yet.</p>
      <div v-if="error" class="alert error" role="alert">
        {{ error }} <button v-if="!editing" class="text-button" type="button" @click="refresh(hotel.id)">Retry</button>
      </div>
      <div v-if="notice" class="alert success" role="status">{{ notice }}</div>
      <form v-if="editing" class="stack access-form" autocomplete="off" @submit.prevent="save">
        <div class="access-fields">
          <label>Email or username <input v-model.trim="username" maxlength="64" minlength="3" autocomplete="off" required :disabled="saving" /></label>
          <label>{{ account ? 'New password' : 'Password' }} <input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required :disabled="saving" /><small class="hint">At least 12 characters.</small></label>
        </div>
        <div class="actions">
          <button class="secondary" type="button" :disabled="saving" @click="close">Cancel</button>
          <button class="primary" type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save login' }}</button>
        </div>
      </form>
    </section>
    <TransactionHistory :hotel-id="hotel.id" :link-orders="true" @sign-in-needed="emit('sign-in-needed')" @open-order="emit('open-order', $event)" />
  </div>
</template>

<style scoped>
.hotel-tools { display: grid; gap: 1rem; }
.access-form { margin-top: 1rem; }
.access-fields { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 1rem; }
.account-login { margin-top: .7rem; overflow-wrap: anywhere; }
@media (max-width: 650px) { .access-fields { grid-template-columns: minmax(0,1fr); } }
</style>
