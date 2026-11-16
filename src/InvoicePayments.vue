<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from './api'
import Icon from './Icon.vue'

const props = defineProps({ orderId: { type: Number, required: true } })
const emit = defineEmits(['sign-in-needed'])
const dateKey = new Intl.DateTimeFormat('sv-SE', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Kuala_Lumpur' })
const dateDisplay = new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium', timeZone: 'Asia/Kuala_Lumpur' })
const currency = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', currencyDisplay: 'narrowSymbol' })
const MAX_RECEIPT_BYTES = 5 * 1024 * 1024
const receiptTypes = new Set(['image/jpeg', 'image/png', 'image/webp'])
const today = () => dateKey.format(new Date())
const money = n => currency.format(Number(n))
const formatDay = value => dateDisplay.format(new Date(value + 'T12:00:00Z'))
const labels = { unpaid: 'Unpaid', partially_paid: 'Partially paid', paid: 'Paid' }
const summary = ref(null)
const loading = ref(false)
const saving = ref(false)
const open = ref(false)
const cannotConfirm = ref(false)
const loadError = ref('')
const formError = ref('')
const notice = ref('')
const amount = ref('')
const paidOn = ref(today())
const reference = ref('')
const receipt = ref(null)
const endpoint = computed(() => '/orders/' + props.orderId + '/payments')

async function load() {
  if (loading.value) return false
  loading.value = true
  loadError.value = ''
  try {
    summary.value = await api(endpoint.value)
    cannotConfirm.value = false
    return true
  } catch (e) {
    if (e.status === 401) emit('sign-in-needed')
    loadError.value = e.message
    return false
  } finally {
    loading.value = false
  }
}
onMounted(load)

function begin() {
  amount.value = ''
  paidOn.value = today()
  reference.value = ''
  receipt.value = null
  formError.value = ''
  notice.value = ''
  open.value = true
}
function hideForm() {
  if (saving.value) return
  open.value = false
  receipt.value = null
  formError.value = ''
}
function setReceipt(event) {
  const input = event.target
  const file = input.files?.[0]
  receipt.value = null
  if (!file) { formError.value = ''; return }
  if (!receiptTypes.has(file.type) || file.size === 0 || file.size > MAX_RECEIPT_BYTES) {
    input.value = ''
    formError.value = 'Choose a JPEG, PNG or WebP receipt up to 5 MB.'
    return
  }
  receipt.value = file
  formError.value = ''
}
async function save() {
  if (saving.value || loading.value || cannotConfirm.value || !summary.value?.balance) return
  formError.value = ''
  notice.value = ''
  if (!Number.isFinite(Number(amount.value)) || Number(amount.value) <= 0 || Number(amount.value) > Number(summary.value.balance)) {
    formError.value = 'Enter an amount up to the remaining balance.'
    return
  }
  const data = new FormData()
  data.append('amount', Number(amount.value).toFixed(2))
  data.append('paidOn', paidOn.value)
  if (reference.value.trim()) data.append('reference', reference.value.trim())
  if (receipt.value) data.append('receipt', receipt.value)

  saving.value = true
  try {
    summary.value = await api(endpoint.value, 'POST', data)
    open.value = false
    receipt.value = null
    notice.value = 'Payment recorded.'
  } catch (e) {
    if (e.status === 401) {
      emit('sign-in-needed')
    } else if (!e.status || e.status >= 500) {
      cannotConfirm.value = true
      await load()
      formError.value = 'I couldn’t confirm the save. Check the payment history before recording this payment again.'
    } else {
      if (e.status === 409 || e.status === 422) await load()
      formError.value = e.message
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="panel padded payment-panel no-print" aria-labelledby="payment-title">
    <header class="section-row payment-heading">
      <div><h2 id="payment-title">Payments</h2><p class="hint">Record money already received. This does not charge the hotel.</p></div>
      <button v-if="summary?.balance > 0 && !open" type="button" class="secondary" :disabled="loading || cannotConfirm" @click="begin"><Icon name="plus" /> Record payment</button>
    </header>
    <div v-if="loadError" class="alert error" role="alert">{{ loadError }} <button class="text-button" type="button" :disabled="loading || saving" @click="load">Reload payments</button></div>
    <p v-if="loading && !summary" class="hint" role="status">Loading payments…</p>
    <div v-if="notice" class="alert success" role="status">{{ notice }}</div>

    <template v-if="summary">
      <dl class="payment-totals">
        <div><dt>Status</dt><dd><span class="status" :class="summary.status === 'paid' ? 'invoiced' : 'open'">{{ labels[summary.status] }}</span></dd></div>
        <div><dt>Received</dt><dd>{{ money(summary.paidTotal) }}</dd></div>
        <div><dt>Balance due</dt><dd>{{ money(summary.balance) }}</dd></div>
      </dl>

      <form v-if="open" class="stack payment-form" @submit.prevent="save">
        <h3>Record a received payment</h3>
        <div v-if="formError" class="alert error" role="alert">{{ formError }}</div>
        <div class="payment-fields">
          <label>Amount (RM) <input v-model="amount" type="number" required min="0.01" step="0.01" :max="summary.balance" inputmode="decimal" :disabled="saving" /></label>
          <label>Date received <input v-model="paidOn" type="date" required :max="today()" :disabled="saving" /></label>
          <label>Bank reference (optional) <input v-model="reference" maxlength="120" placeholder="e.g. transfer reference" :disabled="saving" /></label>
          <label>Receipt image (optional) <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="saving" @change="setReceipt" /><small class="hint">JPEG, PNG or WebP, up to 5 MB.</small></label>
        </div>
        <div class="actions">
          <button class="secondary" type="button" :disabled="saving" @click="hideForm">Close</button>
          <button class="primary" type="submit" :disabled="saving || loading || cannotConfirm || !(summary.balance > 0)">{{ saving ? 'Recording…' : 'Save payment' }}</button>
        </div>
      </form>

      <h3>Payment history</h3>
      <div v-if="summary.payments.length" class="table-wrap">
        <table class="payment-history">
          <thead><tr><th>Date received</th><th>Reference</th><th>Recorded by</th><th class="right">Amount</th><th>Receipt</th></tr></thead>
          <tbody><tr v-for="p in summary.payments" :key="p.id">
            <td>{{ formatDay(p.paidOn) }}</td><td>{{ p.reference || '—' }}</td><td>{{ p.recordedBy || '—' }}</td>
            <td class="right">{{ money(p.amount) }}</td>
            <td><a v-if="p.receiptUrl" class="text-button" :href="p.receiptUrl" target="_blank" rel="noopener noreferrer" :aria-label="'View receipt for ' + formatDay(p.paidOn) + ' payment (opens in a new tab)'">View receipt</a><span v-else>—</span></td>
          </tr></tbody>
        </table>
      </div>
      <p v-else class="hint">No payments recorded.</p>
    </template>
  </section>
</template>
