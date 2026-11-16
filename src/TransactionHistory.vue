<script setup>
import { computed, ref, useId, watch } from 'vue'
import { api } from './api'

const props = defineProps({
  hotelId: [Number, String],
  linkOrders: Boolean,
})
const emit = defineEmits(['open-order', 'sign-in-needed'])
const uid = useId()
const timeZone = 'Asia/Kuala_Lumpur'
const monthParts = new Intl.DateTimeFormat('en-CA', { timeZone, month: '2-digit', year: 'numeric' }).formatToParts(new Date())
const currentMonth = monthParts.find(part => part.type === 'year').value + '-' + monthParts.find(part => part.type === 'month').value
const month = ref(currentMonth)
const summary = ref(null)
const error = ref('')
const loading = ref(false)
const region = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', currencyDisplay: 'narrowSymbol' })
const dates = new Intl.DateTimeFormat('en-MY', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
const endpoint = computed(() => props.hotelId == null ? '/portal/transactions' : '/customers/' + encodeURIComponent(props.hotelId) + '/transactions')
const validMonth = computed(() => /^[0-9]{4}-(0[1-9]|1[0-2])$/.test(month.value) && month.value >= '1900-01' && month.value <= currentMonth)
let requestId = 0

const formatMoney = value => region.format(Number(value ?? 0))
const formatDate = value => {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '—'
  return dates.format(new Date(value + 'T00:00:00Z'))
}
const receiptLink = value => typeof value === 'string' && /^\/api\/[^\/]/.test(value) ? value : null

async function load() {
  const id = ++requestId
  summary.value = null
  error.value = ''
  if (!validMonth.value) {
    loading.value = false
    return
  }
  loading.value = true
  try {
    const data = await api(endpoint.value + '?month=' + encodeURIComponent(month.value))
    if (id === requestId) summary.value = data
  } catch (e) {
    if (id !== requestId) return
    error.value = e.message
    if (e.status === 401) emit('sign-in-needed')
  } finally {
    if (id === requestId) loading.value = false
  }
}
watch([month, endpoint], load, { immediate: true })
</script>

<template>
  <section class="panel padded ledger" :aria-labelledby="uid + '-title'" :aria-busy="loading">
    <header class="section-row ledger-heading">
      <div>
        <h2 :id="uid + '-title'">Transaction history</h2>
        <p class="hint">Issued invoices, payments received and the balance owed.</p>
      </div>
      <label class="ledger-picker" :for="uid + '-month'">
        Statement month
        <input :id="uid + '-month'" v-model.lazy="month" type="month" min="1900-01" :max="currentMonth"
          placeholder="YYYY-MM" pattern="[0-9]{4}-(0[1-9]|1[0-2])" :aria-invalid="!validMonth" />
      </label>
    </header>

    <p v-if="!validMonth" class="alert error" role="alert">Choose a valid month up to the current month (YYYY-MM).</p>
    <p v-else-if="loading" class="hint" role="status">Loading transactions…</p>
    <div v-else-if="error" class="alert error" role="alert">
      {{ error }} <button class="text-button" type="button" @click="load">Try again</button>
    </div>

    <template v-else-if="summary">
      <dl class="ledger-totals" aria-label="Monthly totals">
        <div><dt>Opening balance</dt><dd>{{ formatMoney(summary.openingBalance) }}</dd></div>
        <div><dt>Invoiced</dt><dd>{{ formatMoney(summary.invoicedTotal) }}</dd></div>
        <div><dt>Paid</dt><dd>{{ formatMoney(summary.paidTotal) }}</dd></div>
        <div class="ledger-closing"><dt>Closing balance</dt><dd>{{ formatMoney(summary.closingBalance) }}</dd></div>
      </dl>
      <p v-if="!summary.entries?.length" class="hint">No invoices or payments posted this month.</p>
      <div v-else class="table-wrap ledger-scroll" tabindex="0" role="region" aria-label="Invoice and payment entries">
        <table class="ledger-table">
          <caption class="ledger-sr-only">Invoices and payments for {{ summary.month || month }}</caption>
          <thead><tr>
            <th scope="col">Date</th><th scope="col">Transaction</th><th scope="col">Invoice / reference</th>
            <th scope="col" class="right">Amount</th><th scope="col" class="right">Balance</th>
          </tr></thead>
          <tbody>
            <tr v-for="entry in summary.entries" :key="entry.type + '-' + entry.id">
              <td>{{ formatDate(entry.date) }}</td>
              <td>{{ entry.type === 'payment' ? 'Payment' : 'Invoice' }}</td>
              <td>
                <button v-if="linkOrders && entry.orderId" type="button" class="text-button ledger-ref"
                  @click="emit('open-order', entry.orderId)">{{ entry.invoiceNumber || 'Order #' + entry.orderId }}</button>
                <span v-else>{{ entry.invoiceNumber || (entry.orderId ? 'Order #' + entry.orderId : '—') }}</span>
                <small v-if="entry.reference" class="hint ledger-detail">{{ entry.reference }}</small>
                <a v-if="receiptLink(entry.receiptUrl)" class="text-button ledger-detail" :href="receiptLink(entry.receiptUrl)"
                  target="_blank" rel="noopener noreferrer">View receipt <span class="ledger-sr-only">(opens in a new tab)</span></a>
              </td>
              <td class="right ledger-number">{{ formatMoney(entry.type === 'payment' ? -Math.abs(Number(entry.amount)) : entry.amount) }}</td>
              <td class="right ledger-number">{{ formatMoney(entry.balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.ledger { min-width: 0; }
.ledger-heading { align-items: flex-end; gap: 12px; }
.ledger-picker { min-width: 145px; }
.ledger-totals { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 0 0 16px; }
.ledger-totals > div { padding: 10px 11px; min-width: 0; background: #f7faf8; border: 1px solid var(--line); border-radius: 7px; }
.ledger-totals .ledger-closing { background: #edf7f0; }
.ledger-totals dt { color: var(--muted); font-size: .75rem; }
.ledger-totals dd { margin: 4px 0 0; font-weight: 700; overflow-wrap: anywhere; }
.ledger-table { min-width: 550px; }
.ledger-table td { vertical-align: top; }
.ledger-table td:first-child, .ledger-number { white-space: nowrap; font-variant-numeric: tabular-nums; }
.ledger-ref { padding: 0; text-align: left; }
.ledger-detail { display: block; overflow-wrap: anywhere; }
.ledger-scroll:focus-visible { outline: 2px solid var(--accent, #245f49); outline-offset: -2px; }
.ledger-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; clip: rect(0, 0, 0, 0); overflow: hidden; white-space: nowrap; border: 0; }
@media (max-width: 690px) {
  .ledger-totals { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 480px) {
  .ledger-heading { flex-wrap: wrap; align-items: start; }
}
</style>
