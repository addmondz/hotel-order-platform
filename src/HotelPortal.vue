<script setup>
import { computed, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import TransactionHistory from './TransactionHistory.vue'
import { api, collection, resource } from './api'

const props = defineProps({ session: Object, busy: Boolean, sessionError: String })
const emit = defineEmits(['sign-out', 'sign-in-needed'])
const ownerPaths = { catalogue: '/customer/catalog', orders: '/customer/orders', transactions: '/customer/transactions' }
const route = () => window.location.pathname.replace(/\/+$/, '')
const tab = ref(Object.keys(ownerPaths).find(key => ownerPaths[key] === route()) || 'catalogue')
function setTab(key) {
  tab.value = key
  if (ownerPaths[key] !== route()) window.history.replaceState(window.history.state, '', ownerPaths[key])
}
const details = ref(null)
const catalog = ref(null)
const orders = ref([])
const cart = ref([])
const search = ref('')
const notes = ref('')
const pending = ref(false)
const error = ref('')
const notice = ref('')
const uncertain = ref(false)
const tabs = [
  { key: 'catalogue', name: 'Catalogue', icon: 'products' },
  { key: 'orders', name: 'My orders', icon: 'orders' },
  { key: 'transactions', name: 'Transactions', icon: 'prices' },
]
const section = computed(() => tabs.find(t => t.key === tab.value))
const filteredProducts = computed(() => {
  const term = search.value.trim().toLowerCase()
  return (catalog.value?.products ?? []).filter(p => [p.name, p.sku].some(v => String(v ?? '').toLowerCase().includes(term)))
})
const currency = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', currencyDisplay: 'narrowSymbol' })
const dateFormat = new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium', timeZone: 'Asia/Kuala_Lumpur' })
const sellerName = import.meta.env.VITE_INVOICE_SELLER_NAME || 'OrderDesk'
const sellerAddress = import.meta.env.VITE_INVOICE_SELLER_ADDRESS || ''
const money = value => currency.format(Number(value ?? 0))
const date = value => value ? dateFormat.format(new Date(value)) : '—'
const orderCode = id => '#' + String(id).padStart(4, '0')
const statusLabel = status => ({ open: 'Open', invoiced: 'Invoiced', cancelled: 'Cancelled' }[status] || status)
const lineTotal = row => Math.round(Math.round(Number(row.price) * 100) * Math.round(Number(row.quantity) * 1000) / 1000) / 100
const estimate = computed(() => cart.value.reduce((total, line) => total + Math.round(lineTotal(line) * 100), 0) / 100)
const brandName = computed(() => catalog.value?.hotel?.name || props.session.username)
const controlsBusy = computed(() => props.busy || pending.value)

async function act(run) {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    await run()
  } catch (e) {
    if (e.status === 401) emit('sign-in-needed')
    else error.value = e.message
  } finally {
    pending.value = false
  }
}
async function loadCatalog() {
  catalog.value = await api('/portal/catalog')
  for (const row of cart.value) {
    const unit = catalog.value.products.find(p => p.id === row.productId)?.units.find(u => u.unit === row.unit)
    row.unavailable = !unit
    if (unit) row.price = unit.price
  }
}
async function loadOrders() {
  orders.value = collection(await api('/portal/orders'), 'orders')
}
onMounted(() => act(() => tab.value === 'orders' ? Promise.all([loadCatalog(), loadOrders()]) : loadCatalog()))

function navigate(key) {
  if (controlsBusy.value) return
  setTab(key)
  details.value = null
  error.value = ''
  notice.value = ''
  window.scrollTo(0, 0)
  if (key === 'catalogue') return act(loadCatalog)
  if (key === 'orders') return act(loadOrders)
}
function selectOrder(id) {
  return act(async () => {
    details.value = resource(await api('/portal/orders/' + encodeURIComponent(id)), 'order')
    setTab('orders')
    window.scrollTo(0, 0)
  })
}
function addItem(product, unit) {
  if (controlsBusy.value) return
  const line = cart.value.find(l => l.productId === product.id && l.unit === unit.unit)
  if (line) {
    line.quantity = (Number(line.quantity) || 0) + 1
  } else {
    cart.value.push({ productId: product.id, name: product.name, unit: unit.unit, price: unit.price, quantity: 1 })
  }
}
function submitOrder() {
  if (controlsBusy.value || !cart.value.length || uncertain.value || cart.value.some(row => row.unavailable)) return
  return act(async () => {
    let submitted
    try {
      submitted = await api('/portal/orders', 'POST', {
        items: cart.value.map(({ productId, unit, quantity }) => ({ productId, unit, quantity: Number(quantity) })),
        notes: notes.value.trim(),
      })
    } catch (e) {
      if (!e.status || e.status >= 500) {
        uncertain.value = true
        setTab('orders')
        error.value = 'We couldn’t confirm the order was submitted. Check My orders before submitting the same order again.'
        window.scrollTo(0, 0)
        try { await loadOrders() } catch { /* Keep the original submission warning. */ }
        return
      }
      if (e.status === 400 || e.status === 409 || e.status === 422) {
        try { await loadCatalog() } catch { /* Keep the original validation reason. */ }
      }
      throw e
    }
    details.value = resource(submitted, 'order')
    cart.value = []
    notes.value = ''
    uncertain.value = false
    setTab('orders')
    notice.value = 'Order submitted. The supplier will confirm quantities before issuing the invoice.'
    window.scrollTo(0, 0)
  })
}
function printOrder() { window.print() }
</script>

<template>
  <div class="shell hotel-portal">
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark"><Icon name="orders" /></span><div>OrderDesk<span>HOTEL PORTAL</span></div></div>
      <nav aria-label="Hotel portal">
        <span class="nav-caption">YOUR HOTEL</span>
        <button v-for="item in tabs" :key="item.key" type="button" :class="{ selected: tab === item.key }" :aria-current="tab === item.key ? 'page' : undefined" :disabled="controlsBusy" @click="navigate(item.key)"><Icon :name="item.icon" />{{ item.name }}</button>
      </nav>
      <div class="side-footer">
        <div class="avatar">{{ (brandName || 'H')[0].toUpperCase() }}</div>
        <div class="session-name">{{ brandName }}<small>Hotel account</small></div>
        <button class="icon-button" type="button" title="Sign out" aria-label="Sign out" :disabled="controlsBusy" @click="emit('sign-out')"><Icon name="logout" /></button>
      </div>
    </aside>

    <main class="content">
      <div class="topline"><span>{{ brandName }}</span><span aria-hidden="true">/</span>{{ section.name }}<strong>Hotel portal</strong></div>
      <header class="page-head">
        <div>
          <button v-if="details" class="back-link" type="button" :disabled="controlsBusy" @click="navigate('orders')"><Icon name="back" /> Back to my orders</button>
          <div v-else class="eyebrow page-eyebrow">HOTEL SUPPLY</div>
          <h1>{{ details ? (details.invoiceNumber || 'Order ' + orderCode(details.id)) : section.name }}</h1>
          <p v-if="details">Order {{ orderCode(details.id) }} · {{ date(details.createdAt) }}</p>
          <p v-else-if="tab === 'catalogue'">Browse the supplies and prices agreed for your hotel.</p>
          <p v-else-if="tab === 'orders'">Review your hotel’s submitted orders and issued invoices.</p>
          <p v-else>View your hotel’s invoices, recorded payments, and balance.</p>
        </div>
        <button v-if="details?.status === 'invoiced'" class="primary" type="button" @click="printOrder"><Icon name="print" /> Print invoice</button>
        <button v-else-if="tab === 'orders' && !details" class="secondary" type="button" :disabled="controlsBusy" @click="navigate('orders')">Refresh orders</button>
      </header>

      <div v-if="error || sessionError" class="alert error" role="alert">{{ error || sessionError }}</div>
      <div v-if="notice" class="alert success" role="status">{{ notice }}</div>
      <p v-if="pending" class="hint portal-loading" role="status">Loading…</p>

      <div v-if="tab === 'catalogue'" class="catalogue-layout">
        <section class="panel">
          <div class="toolbar catalogue-toolbar">
            <h2>Available products</h2>
            <label class="search"><Icon name="search" /><input v-model="search" type="search" aria-label="Search products" placeholder="Search product name or SKU…" /></label>
            <button v-if="!catalog && !pending" type="button" class="secondary" :disabled="controlsBusy" @click="act(loadCatalog)">Retry</button>
          </div>
          <div v-if="filteredProducts.length" class="catalog-list">
            <article v-for="product in filteredProducts" :key="product.id" class="catalog-product">
              <div class="catalog-product-head"><h3>{{ product.name }}</h3><span v-if="product.sku">{{ product.sku }}</span></div>
              <div v-for="unit in product.units" :key="unit.unit" class="catalog-unit">
                <span><strong>{{ money(unit.price) }}</strong> / {{ unit.unit }}</span>
                <button type="button" class="secondary compact" :aria-label="'Add ' + product.name + ' (' + unit.unit + ')'" :disabled="controlsBusy" @click="addItem(product, unit)"><Icon name="plus" /> Add</button>
              </div>
            </article>
          </div>
          <div v-else-if="catalog" class="empty"><div class="empty-icon"><Icon name="products" /></div><h2>{{ search ? 'No matching products' : 'No products listed yet' }}</h2></div>
        </section>

        <form class="panel padded stack cart" @submit.prevent="submitOrder">
          <h2>Your order</h2>
          <p v-if="!cart.length" class="hint">Add a product to start your order.</p>
          <div v-for="(item, index) in cart" :key="item.productId + ':' + item.unit" class="cart-row">
            <div class="cart-item"><strong>{{ item.name }}</strong><small>{{ money(item.price) }} / {{ item.unit }}</small><small v-if="item.unavailable" class="cart-unavailable">No longer offered. Remove it to submit.</small></div>
            <label class="cart-quantity">Quantity <input v-model="item.quantity" :aria-label="'Quantity for ' + item.name + ' (' + item.unit + ')'" type="number" required step="0.001" min="0.001" max="10000" :disabled="controlsBusy" /></label>
            <div class="cart-item-total"><strong>{{ money(lineTotal(item)) }}</strong><button type="button" class="icon-button remove-button" :aria-label="'Remove ' + item.name + ' (' + item.unit + ')'" :disabled="controlsBusy" @click="cart.splice(index, 1)"><Icon name="close" /></button></div>
          </div>
          <template v-if="cart.length">
            <label>Order notes (optional)<textarea v-model="notes" maxlength="1000" rows="2" :disabled="controlsBusy" placeholder="Add delivery instructions or a purchase order reference"></textarea></label>
            <div class="order-total"><span>Estimated total</span><strong>{{ money(estimate) }}</strong></div>
            <p v-if="uncertain" class="hint">After checking that this order isn’t in My orders, you can try again.</p>
            <button v-if="uncertain" class="secondary" type="button" :disabled="controlsBusy" @click="uncertain = false">I checked My orders</button>
            <button type="submit" class="primary" :disabled="controlsBusy || uncertain || cart.some(row => row.unavailable)">Submit order</button>
          </template>
        </form>
      </div>

      <section v-else-if="tab === 'orders' && !details" class="panel">
        <div v-if="orders.length" class="table-wrap"><table>
          <thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Invoice</th><th class="right">Total</th></tr></thead>
          <tbody><tr v-for="order in orders" :key="order.id">
            <td><button type="button" class="text-button" :disabled="controlsBusy" @click="selectOrder(order.id)">{{ orderCode(order.id) }}</button></td>
            <td>{{ date(order.createdAt) }}</td><td><span class="status" :class="order.status">{{ statusLabel(order.status) }}</span></td><td>{{ order.invoiceNumber || '—' }}</td><td class="right">{{ money(order.total) }}</td>
          </tr></tbody>
        </table></div>
        <div v-else-if="!pending" class="empty"><div class="empty-icon"><Icon name="orders" /></div><h2>No orders yet</h2><p>Place an order from the catalogue to see it here.</p></div>
      </section>

      <section v-else-if="details" class="panel document" :class="{ 'print-document': details.status === 'invoiced' }">
        <header class="document-head"><div>
          <div class="eyebrow">{{ details.invoiceNumber ? 'INVOICE' : 'ORDER SUMMARY' }}</div><h2>{{ details.invoiceNumber || orderCode(details.id) }}</h2>
          <p>{{ details.issuedAt ? 'Issued ' + date(details.issuedAt) : 'Received ' + date(details.createdAt) }}</p>
        </div><span class="status" :class="details.status">{{ statusLabel(details.status) }}</span></header>
        <div class="document-parties">
          <div v-if="details.status === 'invoiced'"><span>From</span><strong>{{ sellerName }}</strong><span v-if="sellerAddress" class="seller-address">{{ sellerAddress }}</span></div>
          <div><span>{{ details.status === 'invoiced' ? 'Bill to' : 'Hotel' }}</span><strong>{{ details.customerName }}</strong></div>
        </div>
        <div class="table-wrap"><table>
          <thead><tr><th>Item</th><th>Unit</th><th class="right">Quantity</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead>
          <tbody><tr v-for="(item,i) in details.items" :key="i"><td>{{ item.productName }}</td><td>{{ item.unit }}</td><td class="right">{{ item.quantity }}</td><td class="right">{{ money(item.unitPrice) }}</td><td class="right">{{ money(item.lineTotal) }}</td></tr></tbody>
        </table></div>
        <div class="order-total"><span>Total</span><strong>{{ money(details.total) }}</strong></div>
        <p v-if="details.notes && details.status !== 'invoiced'" class="document-notes"><strong>Your notes</strong><br>{{ details.notes }}</p>
      </section>

      <TransactionHistory v-else :link-orders="true" @open-order="selectOrder" @sign-in-needed="emit('sign-in-needed')" />
    </main>
  </div>
</template>

<style scoped>
.catalogue-layout { display: grid; grid-template-columns: minmax(0,1fr) minmax(270px,.62fr); align-items: start; gap: 16px; }
.catalogue-toolbar { align-items: center; flex-wrap: wrap; }
.catalog-list { display: grid; grid-template-columns: repeat(auto-fill,minmax(min(100%,210px),1fr)); padding: 0 15px 15px; gap: 10px; }
.catalog-product { border: 1px solid var(--line); border-radius: 8px; padding: 11px; min-width: 0; }
.catalog-product-head { margin-bottom: 7px; overflow-wrap: anywhere; }
.catalog-product-head h3 { font-size: .9rem; margin: 0; }
.catalog-product-head span { font-size: .72rem; color: var(--muted); }
.catalog-unit { display: flex; align-items: center; justify-content: space-between; gap: 6px; border-top: 1px solid var(--line); padding: 6px 0; font-size: .75rem; }
.catalog-unit button svg { height: 14px; width: 14px; }
.cart { position: sticky; top: 12px; gap: 12px; }
.cart-row { display: grid; grid-template-columns: minmax(0,1fr) 75px; align-items: start; gap: 6px 9px; padding-top: 10px; border-top: 1px solid var(--line); }
.cart-item { overflow-wrap: anywhere; font-size: .835rem; }
.cart-item small { display: block; color: var(--muted); margin-top: 2px; }
.cart-item .cart-unavailable { color: #982b30; }
.cart-item-total { display: flex; grid-column: 1/-1; align-items: center; justify-content: space-between; font-size: .82rem; }
.cart-quantity input { padding-inline: 6px; }
.portal-loading { margin-bottom: 10px; }
@media (max-width: 1140px) { .catalogue-layout { grid-template-columns: minmax(0,1fr) minmax(237px,.7fr); } }
@media (max-width: 950px) { .catalogue-layout { grid-template-columns: minmax(0,1fr); } .cart { position: static; } }
</style>
