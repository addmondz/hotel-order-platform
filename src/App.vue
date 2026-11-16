<script setup>
import { computed, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import { api, collection, resource } from './api'

const nav = [
  { key: 'orders', label: 'Orders', icon: 'orders', subtitle: 'Enter WhatsApp orders, adjust quantities, and issue invoices.' },
  { key: 'customers', label: 'Hotels', icon: 'customers', subtitle: 'Manage the hotels your team supplies.' },
  { key: 'products', label: 'Products', icon: 'products', subtitle: 'Set the selling units and base prices for your catalogue.' },
  { key: 'prices', label: 'Hotel prices', icon: 'prices', subtitle: 'Set agreed rates for each hotel without changing the catalogue.' },
]
const page = ref('orders')
const section = computed(() => nav.find(entry => entry.key === page.value))
const mode = ref('')
const loading = ref(true)
const session = ref(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const credentials = ref({ username: '', password: '' })
const orders = ref([])
const customers = ref([])
const products = ref([])
const prices = ref([])
const draft = ref(null)
const orderView = ref(null)
const search = ref('')
const filter = ref('all')
const dateFrom = ref('')
const dateTo = ref('')
const pricingCustomer = ref('')
const pricingDraft = ref(null)
const currency = new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', currencyDisplay: 'narrowSymbol' })
const dateFormat = new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium', timeZone: 'Asia/Kuala_Lumpur' })
const sellerName = import.meta.env.VITE_INVOICE_SELLER_NAME || 'OrderDesk'
const sellerAddress = import.meta.env.VITE_INVOICE_SELLER_ADDRESS || ''
let localItemId = 0
let pricingRequest = 0

const money = value => currency.format(Number(value ?? 0))
const date = value => value ? dateFormat.format(new Date(value)) : '—'
const dateKeyFormat = new Intl.DateTimeFormat('sv-SE', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Kuala_Lumpur' })
const dateKey = value => value ? dateKeyFormat.format(new Date(value)) : ''
const orderCode = id => '#' + String(id).padStart(4, '0')
const statusLabel = status => ({ open: 'Open', invoiced: 'Invoiced', cancelled: 'Cancelled' }[status] || status)
const matching = (values, term) => values.some(v => String(v ?? '').toLowerCase().includes(term.toLowerCase().trim()))
const filteredOrders = computed(() => orders.value.filter(o =>
  (filter.value === 'all' || o.status === filter.value) &&
  (!dateFrom.value || dateKey(o.createdAt) >= dateFrom.value) &&
  (!dateTo.value || dateKey(o.createdAt) <= dateTo.value) &&
  matching([orderCode(o.id), o.customerName, o.customerPhone, o.invoiceNumber], search.value)
))
const filteredCustomers = computed(() => customers.value.filter(c => matching([c.name, c.phone], search.value)))
const filteredProducts = computed(() => products.value.filter(p => matching([p.name, p.sku], search.value)))
const productFor = id => products.value.find(p => String(p.id) === String(id))
const unitFor = (id, unit) => productFor(id)?.units?.find(u => u.unit === unit)
const lineCents = ({ quantity, unitPrice, suggestedPrice }) =>
  Math.round(Math.round(Number(unitPrice === '' || unitPrice == null ? suggestedPrice : unitPrice) * 100) * Math.round(Number(quantity) * 1000) / 1000)
const estimatedTotal = computed(() => (draft.value?.items ?? []).reduce((sum, item) => sum + lineCents(item), 0) / 100)
const savedOrder = computed(() => mode.value === 'view-order' ? orderView.value : null)

function clearMessages() {
  error.value = ''
  notice.value = ''
}
async function action(run) {
  if (busy.value) return
  clearMessages()
  busy.value = true
  try {
    return await run()
  } catch (e) {
    error.value = e.message
    if (e.status === 401 && session.value) {
      session.value = null
      credentials.value.password = ''
      error.value = 'Your session ended. Sign in again to continue.'
    }
  } finally {
    busy.value = false
  }
}

async function refresh() {
  const [orderData, customerData, productData] = await Promise.all([api('/orders'), api('/customers'), api('/products')])
  orders.value = collection(orderData, 'orders')
  customers.value = collection(customerData, 'customers')
  products.value = collection(productData, 'products')
}
onMounted(async () => {
  try {
    session.value = resource(await api('/me'), 'user')
    if (session.value) await refresh()
  } catch (e) {
    if (e.status !== 401) error.value = e.message
  } finally {
    loading.value = false
  }
})
function signIn() {
  return action(async () => {
    await api('/login', 'POST', credentials.value)
    session.value = resource(await api('/me'), 'user')
    credentials.value.password = ''
    filter.value = 'all'
    dateFrom.value = ''
    dateTo.value = ''
    navigate('orders')
    await refresh()
  })
}
function signOut() {
  return action(async () => {
    await api('/logout', 'POST')
    session.value = null
    prices.value = []
    orders.value = []
    customers.value = []
    products.value = []
    pricingCustomer.value = ''
    navigate('orders')
  })
}

function navigate(key) {
  page.value = key
  if (key === 'prices') {
    pricingRequest++
    pricingCustomer.value = ''
    pricingDraft.value = null
    prices.value = []
  }
  mode.value = ''
  orderView.value = null
  draft.value = null
  search.value = ''
  clearMessages()
  window.scrollTo(0, 0)
}
function back() {
  mode.value = ''
  orderView.value = null
  draft.value = null
  clearMessages()
  window.scrollTo(0, 0)
}

async function loadPrices(id) {
  const request = ++pricingRequest
  prices.value = []
  if (!id) return false
  const rows = collection(await api('/prices?customerId=' + encodeURIComponent(id)), 'prices')
  if (request !== pricingRequest) return false
  prices.value = rows
  return true
}
function clientPrice(productId, unit) {
  return prices.value.find(p => String(p.productId) === String(productId) && p.unit === unit)
}
function applySuggestion(item) {
  const productUnit = unitFor(item.productId, item.unit)
  const override = clientPrice(item.productId, item.unit)
  item.suggestedPrice = Number(override?.price ?? productUnit?.price ?? 0)
  item.priceSource = override ? 'Customer price' : 'Base price'
}
function setProduct(item) {
  const product = productFor(item.productId)
  item.unit = product?.units?.[0]?.unit ?? ''
  item.unitPrice = ''
  applySuggestion(item)
}
function setUnit(item) {
  item.unitPrice = ''
  applySuggestion(item)
}
function addLine() {
  draft.value.items.push({ key: ++localItemId, productId: '', unit: '', quantity: 1, unitPrice: '', suggestedPrice: 0, priceSource: '' })
}
function newOrder() {
  clearMessages()
  pricingRequest++
  prices.value = []
  draft.value = { customerId: '', notes: '', items: [] }
  addLine()
  mode.value = 'edit-order'
}
function changeOrderCustomer() {
  return action(async () => {
    if (await loadPrices(draft.value.customerId)) {
      for (const item of draft.value.items) {
        item.unitPrice = ''
        applySuggestion(item)
      }
    }
  })
}
function openOrder(id) {
  return action(async () => {
    orderView.value = resource(await api('/orders/' + id), 'order')
    mode.value = 'view-order'
    window.scrollTo(0, 0)
  })
}
function editOrder() {
  return action(async () => {
    const o = orderView.value
    await loadPrices(o.customerId)
    draft.value = {
      id: o.id,
      revision: o.revision,
      customerId: o.customerId,
      notes: o.notes || '',
      items: o.items.map(line => ({ ...line, key: ++localItemId, suggestedPrice: line.unitPrice, priceSource: 'Saved price' })),
    }
    mode.value = 'edit-order'
  })
}
function saveOrder() {
  return action(async () => {
    const d = draft.value
    if (!d.items.length) throw new Error('Add at least one item to save the order.')
    const body = {
      customerId: Number(d.customerId),
      notes: d.notes,
      ...(d.id ? { revision: d.revision } : {}),
      items: d.items.map(l => ({
        productId: Number(l.productId), unit: l.unit, quantity: Number(l.quantity),
        unitPrice: l.unitPrice === '' || l.unitPrice == null ? null : Number(l.unitPrice),
      })),
    }
    const data = await api(d.id ? '/orders/' + d.id : '/orders', d.id ? 'PUT' : 'POST', body)
    orderView.value = resource(data, 'order')
    mode.value = 'view-order'
    if (!d.id) { filter.value = 'all'; dateFrom.value = ''; dateTo.value = ''; search.value = '' }
    notice.value = d.id ? 'Order updated.' : 'Order entered successfully.'
    await refresh()
    window.scrollTo(0, 0)
  })
}
function moveOrder(next) {
  if (busy.value) return
  const prompt = next === 'invoice'
    ? 'Issue this invoice? Its quantities and prices will be locked.'
    : 'Cancel this order? It will stay visible in your order history.'
  if (!window.confirm(prompt)) return
  return action(async () => {
    const id = orderView.value.id
    orderView.value = resource(await api('/orders/' + id + '/' + next, 'POST', { revision: orderView.value.revision }), 'order')
    notice.value = next === 'invoice' ? 'Invoice issued. It is ready to print.' : 'Order cancelled.'
    await refresh()
  })
}
function printInvoice() {
  window.print()
}

function editCustomer(item) {
  clearMessages()
  draft.value = item ? { ...item } : { name: '', phone: '', notes: '' }
  mode.value = 'edit-customer'
}
function saveCustomer() {
  return action(async () => {
    const d = draft.value
    await api(d.id ? '/customers/' + d.id : '/customers', d.id ? 'PUT' : 'POST', {
      name: d.name, phone: d.phone || '', notes: d.notes || ''
    })
    await refresh()
    back()
    notice.value = 'Hotel saved.'
  })
}
function editProduct(item) {
  clearMessages()
  draft.value = item
    ? { ...item, units: item.units.map(u => ({ ...u, key: ++localItemId })) }
    : { name: '', sku: '', active: true, units: [{ unit: 'piece', price: '', stock: '', key: ++localItemId }] }
  mode.value = 'edit-product'
}
function addUnit() {
  draft.value.units.push({ unit: '', price: '', stock: '', key: ++localItemId })
}
function saveProduct() {
  return action(async () => {
    const d = draft.value
    await api(d.id ? '/products/' + d.id : '/products', d.id ? 'PUT' : 'POST', {
      name: d.name, sku: d.sku, active: d.active,
      units: d.units.map(u => ({ unit: u.unit.trim(), price: Number(u.price), stock: u.stock === '' || u.stock == null ? null : Number(u.stock) })),
    })
    await refresh()
    back()
    notice.value = 'Product saved.'
  })
}
function changePricingCustomer() {
  pricingDraft.value = null
  return action(() => loadPrices(pricingCustomer.value))
}
function showCustomerPrices(customer) {
  navigate('prices')
  pricingCustomer.value = customer.id
  changePricingCustomer()
}
function editPrice(item) {
  clearMessages()
  pricingDraft.value = item ? { ...item, editing: true } : { productId: '', unit: '', price: '' }
}
function setPricingProduct() {
  pricingDraft.value.unit = productFor(pricingDraft.value.productId)?.units?.[0]?.unit ?? ''
}
function savePrice() {
  return action(async () => {
    const { productId, unit, price } = pricingDraft.value
    await api('/prices', 'PUT', { customerId: Number(pricingCustomer.value), productId: Number(productId), unit, price: Number(price) })
    await loadPrices(pricingCustomer.value)
    pricingDraft.value = null
    notice.value = 'Hotel price saved.'
  })
}
function removePrice(item) {
  if (busy.value || !window.confirm('Remove this custom price? Future orders will use the base price.')) return
  return action(async () => {
    await api('/prices', 'DELETE', { customerId: Number(pricingCustomer.value), productId: Number(item.productId), unit: item.unit })
    await loadPrices(pricingCustomer.value)
    pricingDraft.value = null
    notice.value = 'Custom price removed.'
  })
}
</script>

<template>
  <main v-if="loading" class="loading-view"><span class="brand-mark"><Icon name="orders" /></span> Opening OrderDesk…</main>
  <main v-else-if="!session" class="login-shell">
    <div class="login-visual">
      <div class="brand"><span class="brand-mark"><Icon name="orders" /></span> OrderDesk</div>
      <div class="login-copy">
        <span class="eyebrow">HOTEL SUPPLY ORDERS</span>
        <h1>From order<br>to invoice.</h1>
        <p>Record orders from hotels, confirm quantities and prices, and print the final invoice.</p>
      </div>
      <div class="login-foot">STAFF WORKSPACE</div>
    </div>
    <form class="login-form" @submit.prevent="signIn">
      <span class="eyebrow">STAFF ACCESS</span>
      <h2>Sign in</h2>
      <p>Use your staff account to open the order desk.</p>
      <div v-if="error" class="alert error" role="alert">{{ error }}</div>
      <label>Email or username <input v-model.trim="credentials.username" autocomplete="username" required /></label>
      <label>Password <input v-model="credentials.password" type="password" autocomplete="current-password" required /></label>
      <button class="primary" :disabled="busy" type="submit">{{ busy ? 'Signing in…' : 'Sign in' }} <Icon name="arrow" /></button>
    </form>
  </main>

  <div v-else class="shell">
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark"><Icon name="orders" /></span><div>OrderDesk<span>HOTEL SUPPLY ORDERS</span></div></div>
      <nav aria-label="Workspace">
        <span class="nav-caption">WORKSPACE</span>
        <button v-for="entry in nav" :key="entry.key" type="button" :class="{ selected: page === entry.key }" :aria-current="page === entry.key ? 'page' : undefined" :disabled="busy" @click="navigate(entry.key)">
          <Icon :name="entry.icon" /><span>{{ entry.label }}</span>
        </button>
      </nav>
      <div class="side-footer">
        <div class="avatar">{{ (session.username || session.name || 'S')[0].toUpperCase() }}</div>
        <div class="session-name">{{ session.username || session.name || 'Staff' }}<small>Staff account</small></div>
        <button class="icon-button" type="button" title="Sign out" aria-label="Sign out" :disabled="busy" @click="signOut"><Icon name="logout" /></button>
      </div>
    </aside>
    <main class="content">
      <div class="topline"><span>Hotel supply orders</span><span aria-hidden="true" class="topline-slash">/</span>{{ section.label }}<strong>Staff workspace</strong></div>
      <header class="page-head">
        <div>
          <button v-if="mode" class="back-link" type="button" :disabled="busy" @click="back"><Icon name="back" /> Back to {{ section.label.toLowerCase() }}</button>
          <div v-else class="eyebrow page-eyebrow">HOTEL SUPPLY</div>
          <h1>{{ mode === 'edit-order' ? (draft.id ? 'Edit order' : 'New order') : mode === 'view-order' ? (savedOrder.invoiceNumber || 'Order ' + orderCode(savedOrder.id)) : mode === 'edit-customer' ? (draft.id ? 'Edit hotel' : 'New hotel') : mode === 'edit-product' ? (draft.id ? 'Edit product' : 'New product') : section.label }}</h1>
          <p v-if="!mode">{{ section.subtitle }}</p>
          <p v-else-if="mode === 'edit-order'">Enter the customer’s items and confirm the final quantities before invoicing.</p>
          <p v-else-if="mode === 'view-order'">Order {{ orderCode(savedOrder.id) }} · {{ date(savedOrder.createdAt) }}</p>
        </div>
        <button v-if="!mode && page !== 'prices'" class="primary" :disabled="busy" type="button" @click="page === 'orders' ? newOrder() : page === 'customers' ? editCustomer() : editProduct()">
          <Icon name="plus" /> {{ page === 'orders' ? 'New order' : page === 'customers' ? 'New hotel' : 'New product' }}
        </button>
        <button v-if="savedOrder?.status === 'invoiced'" class="primary" type="button" @click="printInvoice"><Icon name="print" /> Print invoice</button>
      </header>
      <div v-if="error" class="alert error" role="alert">{{ error }}</div>
      <div v-if="notice" class="alert success" role="status">{{ notice }}</div>

      <template v-if="page === 'orders' && !mode">
        <section class="panel">
          <div class="status-toolbar">
            <div class="filters" aria-label="Order status">
              <button v-for="option in ['all', 'open', 'invoiced', 'cancelled']" :key="option" type="button" :aria-pressed="filter === option" @click="filter = option">{{ option === 'all' ? 'All orders' : statusLabel(option) }}</button>
            </div>
            <span class="hint status-currency">MYR · Hotel supplies</span>
          </div>
          <div class="toolbar order-filters">
            <label class="search"><Icon name="search" /><input v-model="search" type="search" aria-label="Search orders" placeholder="Search order, invoice or hotel…" /></label>
            <label class="date-filter">From <input v-model="dateFrom" type="date" aria-label="From date" /></label>
            <label class="date-filter">To <input v-model="dateTo" type="date" aria-label="To date" /></label>
          </div>
          <div class="dataset-note"><span>Order records</span><span>{{ filteredOrders.length }} {{ filteredOrders.length === 1 ? 'order' : 'orders' }}</span></div>
          <div v-if="filteredOrders.length" class="table-wrap"><table>
            <thead><tr><th>Order</th><th>Hotel</th><th>Date</th><th>Status</th><th class="right">Total</th><th></th></tr></thead>
            <tbody>
              <tr v-for="o in filteredOrders" :key="o.id">
                <td><button class="text-button" :disabled="busy" type="button" @click="openOrder(o.id)">{{ orderCode(o.id) }}</button><small v-if="o.invoiceNumber">{{ o.invoiceNumber }}</small></td>
                <td>{{ o.customerName }}</td><td>{{ date(o.createdAt) }}</td>
                <td><span class="status" :class="o.status">{{ statusLabel(o.status) }}</span></td>
                <td class="right">{{ money(o.total) }}</td>
                <td><button class="icon-button" type="button" :disabled="busy" :aria-label="'View order ' + o.id" @click="openOrder(o.id)"><Icon name="arrow" /></button></td>
              </tr>
            </tbody>
          </table></div>
          <div v-else class="empty"><div class="empty-icon"><Icon name="orders" /></div><h2>{{ orders.length ? 'No matching orders' : 'No orders yet' }}</h2><p>{{ orders.length ? 'Try another search or status.' : 'Add a hotel and a product, then enter your first order.' }}</p></div>
        </section>
      </template>

      <template v-else-if="mode === 'edit-order'">
        <form class="editor-layout" @submit.prevent="saveOrder">
          <div class="stack editor-main">
          <section class="panel padded">
            <div class="section-row numbered"><span class="step" aria-hidden="true">01</span><h2>Order details</h2></div>
            <div class="fields">
              <label>Hotel
                <select v-model="draft.customerId" :disabled="busy" required @change="changeOrderCustomer">
                  <option value="" disabled>Select a hotel</option>
                  <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
              </label>
              <label>Notes <input v-model="draft.notes" placeholder="Optional delivery details or order reference" maxlength="1000" /></label>
            </div>
            <p v-if="!customers.length" class="hint">Add a hotel in Hotels before entering an order.</p>
          </section>
          <section class="panel padded">
            <div class="section-row numbered"><span class="step" aria-hidden="true">02</span><div><h2>Order items</h2><p class="hint">Adjust quantities and override a unit price only for this order.</p></div></div>
            <div class="line-item" v-for="(item, i) in draft.items" :key="item.key">
              <label>Product
                <select v-model="item.productId" required @change="setProduct(item)">
                  <option value="" disabled>Select product</option>
                  <option v-for="p in products.filter(p => p.active !== false || String(p.id) === String(item.productId))" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </label>
              <label>Unit
                <select v-model="item.unit" required @change="setUnit(item)">
                  <option value="" disabled>Select unit</option>
                  <option v-if="item.unit && !productFor(item.productId)?.units?.some(u => u.unit === item.unit)" :value="item.unit">{{ item.unit }}</option>
                  <option v-for="u in productFor(item.productId)?.units ?? []" :key="u.unit" :value="u.unit">{{ u.unit }}</option>
                </select>
                <small v-if="unitFor(item.productId, item.unit)?.stock != null" class="hint">{{ unitFor(item.productId, item.unit).stock }} in stock</small>
              </label>
              <label>Quantity <input v-model="item.quantity" required type="number" step="0.001" min="0.001" /></label>
              <label>Unit price (RM)
                <input v-model="item.unitPrice" type="number" step="0.01" min="0" :placeholder="item.productId ? item.suggestedPrice.toFixed(2) : '0.00'" />
                <small v-if="item.productId" class="hint">{{ item.priceSource }}</small>
              </label>
              <div class="item-total"><span>Line total</span><strong>{{ money(lineCents(item) / 100) }}</strong></div>
              <button class="icon-button remove-button" type="button" :aria-label="'Remove item ' + (i + 1)" @click="draft.items.splice(i, 1)"><Icon name="close" /></button>
            </div>
            <p v-if="!products.some(p => p.active !== false)" class="hint">Add an active product in Products before entering an order.</p>
            <button class="secondary add-order-item" type="button" @click="addLine"><Icon name="plus" /> Add item</button>
          </section>
          </div>
          <aside class="editor-summary">
            <section class="panel padded stack">
              <div><span class="eyebrow">ORDER SUMMARY</span><h2>Order totals</h2></div>
              <div class="summary-rows"><span>Line items</span><strong>{{ draft.items.length }}</strong></div>
              <div class="order-total summary-total"><span>Total amount</span><strong>{{ money(estimatedTotal) }}</strong><small>Malaysian ringgit</small></div>
              <button class="primary" :disabled="busy" type="submit">{{ busy ? 'Saving…' : 'Save order' }}</button>
              <button class="secondary" :disabled="busy" type="button" @click="back">Back</button>
              <p class="hint">Saving keeps this order open. Issue the invoice after confirming the final quantities and prices.</p>
            </section>
          </aside>
        </form>
      </template>

      <template v-else-if="savedOrder">
        <section class="panel document" :class="{ 'print-document': savedOrder.status === 'invoiced' }">
          <header class="document-head">
            <div>
              <div class="eyebrow">{{ savedOrder.status === 'invoiced' ? 'INVOICE' : 'ORDER SUMMARY' }}</div>
              <h2>{{ savedOrder.invoiceNumber || orderCode(savedOrder.id) }}</h2>
              <p>{{ savedOrder.issuedAt ? 'Issued ' + date(savedOrder.issuedAt) : 'Entered ' + date(savedOrder.createdAt) }}</p>
            </div>
            <span class="status" :class="savedOrder.status">{{ statusLabel(savedOrder.status) }}</span>
          </header>
          <div class="document-parties">
            <div v-if="savedOrder.status === 'invoiced'"><span>From</span><strong>{{ sellerName }}</strong><span v-if="sellerAddress" class="seller-address">{{ sellerAddress }}</span></div>
            <div><span>{{ savedOrder.status === 'invoiced' ? 'Bill to' : 'Customer' }}</span><strong>{{ savedOrder.customerName }}</strong><span v-if="savedOrder.customerPhone">{{ savedOrder.customerPhone }}</span></div>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>Item</th><th>Unit</th><th class="right">Quantity</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead>
            <tbody><tr v-for="(l, i) in savedOrder.items" :key="i">
              <td>{{ l.productName }}</td><td>{{ l.unit }}</td><td class="right">{{ l.quantity }}</td><td class="right">{{ money(l.unitPrice) }}</td><td class="right">{{ money(l.lineTotal) }}</td>
            </tr></tbody>
          </table></div>
          <div class="order-total"><span>Total</span><strong>{{ money(savedOrder.total) }}</strong></div>
          <p v-if="savedOrder.notes && savedOrder.status !== 'invoiced'" class="document-notes"><strong>Notes</strong><br>{{ savedOrder.notes }}</p>
        </section>
        <section v-if="savedOrder.createdBy" class="panel activity-summary" aria-label="Staff activity">
          <h2>Staff activity</h2>
          <dl><div><dt>Entered by</dt><dd>{{ savedOrder.createdBy }}</dd></div>
            <div v-if="savedOrder.updatedBy"><dt>Last updated by</dt><dd>{{ savedOrder.updatedBy }}</dd></div>
            <div v-if="savedOrder.issuedBy"><dt>Invoice issued by</dt><dd>{{ savedOrder.issuedBy }}</dd></div></dl>
        </section>
        <div v-if="savedOrder.status === 'open'" class="actions">
          <button class="danger-link" type="button" :disabled="busy" @click="moveOrder('cancel')">Cancel order</button>
          <button class="secondary" type="button" :disabled="busy" @click="editOrder">Edit order</button>
          <button class="primary" type="button" :disabled="busy" @click="moveOrder('invoice')">Generate invoice</button>
        </div>
      </template>

      <template v-else-if="page === 'customers' && !mode">
        <section class="panel">
          <div class="toolbar"><h2>Hotel directory</h2><label class="search"><Icon name="search" /><input v-model="search" aria-label="Search hotels" placeholder="Search hotels…" /></label></div>
          <div v-if="filteredCustomers.length" class="table-wrap"><table>
            <thead><tr><th>Name</th><th>Phone</th><th>Notes</th><th></th></tr></thead>
            <tbody><tr v-for="c in filteredCustomers" :key="c.id">
              <td><button class="text-button" type="button" :disabled="busy" @click="editCustomer(c)">{{ c.name }}</button></td>
              <td>{{ c.phone || '—' }}</td><td>{{ c.notes || '—' }}</td>
              <td class="right"><button class="secondary compact" type="button" :disabled="busy" @click="showCustomerPrices(c)">Manage prices</button></td>
            </tr></tbody>
          </table></div>
          <div v-else class="empty"><div class="empty-icon"><Icon name="customers" /></div><h2>{{ customers.length ? 'No matching hotels' : 'No hotels yet' }}</h2><p>{{ customers.length ? 'Try another search.' : 'Add your first hotel to start entering orders.' }}</p></div>
        </section>
      </template>

      <form v-else-if="mode === 'edit-customer'" class="panel padded edit-panel stack" @submit.prevent="saveCustomer">
        <div class="fields"><label>Hotel or company name <input v-model.trim="draft.name" required maxlength="160" /></label><label>Phone <input v-model.trim="draft.phone" type="tel" maxlength="60" /></label></div>
        <label>Notes <textarea v-model="draft.notes" rows="3" maxlength="1000"></textarea></label>
        <div class="actions"><button class="secondary" :disabled="busy" type="button" @click="back">Back</button><button class="primary" :disabled="busy" type="submit">Save hotel</button></div>
      </form>

      <template v-else-if="page === 'products' && !mode">
        <section class="panel">
          <div class="toolbar"><h2>Product catalogue</h2><label class="search"><Icon name="search" /><input v-model="search" aria-label="Search products" placeholder="Search products…" /></label></div>
          <div v-if="filteredProducts.length" class="table-wrap"><table>
            <thead><tr><th>Product</th><th>SKU</th><th>Base prices / stock</th><th>Status</th></tr></thead>
            <tbody><tr v-for="p in filteredProducts" :key="p.id">
              <td><button class="text-button" type="button" :disabled="busy" @click="editProduct(p)">{{ p.name }}</button></td>
              <td>{{ p.sku || '—' }}</td>
              <td><span v-for="u in p.units" :key="u.unit" class="unit-chip">{{ money(u.price) }} / {{ u.unit }}<span v-if="u.stock != null" class="unit-stock"> · {{ u.stock }} in stock</span></span></td>
              <td><span class="status" :class="p.active === false ? 'cancelled' : 'invoiced'">{{ p.active === false ? 'Inactive' : 'Active' }}</span></td>
            </tr></tbody>
          </table></div>
          <div v-else class="empty"><div class="empty-icon"><Icon name="products" /></div><h2>{{ products.length ? 'No matching products' : 'No products yet' }}</h2><p>{{ products.length ? 'Try another search.' : 'Add a product and set its first selling unit and price.' }}</p></div>
        </section>
      </template>

      <form v-else-if="mode === 'edit-product'" class="panel padded edit-panel stack" @submit.prevent="saveProduct">
        <div class="fields"><label>Product name <input v-model.trim="draft.name" required maxlength="160" /></label><label>SKU <input v-model.trim="draft.sku" maxlength="64" /></label></div>
        <label class="check"><input v-model="draft.active" type="checkbox" /> Active and available for new orders</label>
        <div class="section-row"><div><h2>Selling units</h2><p class="hint">Set a price and optionally track stock manually for each unit, such as piece, carton, or kg. Orders do not deduct stock automatically.</p></div><button class="secondary" type="button" @click="addUnit"><Icon name="plus" /> Add unit</button></div>
        <div v-for="(u,i) in draft.units" :key="u.key" class="unit-fields">
          <label>Unit <input v-model.trim="u.unit" required maxlength="35" placeholder="e.g. carton" /></label>
          <label>Base price (RM) <input v-model="u.price" required type="number" min="0" step="0.01" /></label>
          <label>Stock (optional) <input v-model="u.stock" type="number" min="0" max="10000" step="0.001" placeholder="Not tracked" /></label>
          <button type="button" class="icon-button remove-button" :aria-label="'Remove unit ' + (i + 1)" :disabled="draft.units.length < 2" @click="draft.units.splice(i, 1)"><Icon name="close" /></button>
        </div>
        <div class="actions"><button class="secondary" :disabled="busy" type="button" @click="back">Back</button><button class="primary" :disabled="busy" type="submit">Save product</button></div>
      </form>

      <template v-else-if="page === 'prices'">
        <section class="panel">
          <div class="toolbar">
            <label>Hotel
              <select v-model="pricingCustomer" :disabled="busy" @change="changePricingCustomer">
                <option value="" disabled>Select a hotel</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </label>
            <button v-if="pricingCustomer && !pricingDraft" class="secondary" type="button" :disabled="busy" @click="editPrice()"><Icon name="plus" /> Add custom price</button>
          </div>
          <form v-if="pricingDraft" class="padded stack price-form" @submit.prevent="savePrice">
            <div class="fields">
              <label>Product
                <select v-model="pricingDraft.productId" :disabled="pricingDraft.editing" required @change="setPricingProduct">
                  <option value="" disabled>Select product</option>
                  <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </label>
              <label>Unit
                <select v-model="pricingDraft.unit" :disabled="pricingDraft.editing" required>
                  <option value="" disabled>Select unit</option>
                  <option v-for="u in productFor(pricingDraft.productId)?.units ?? []" :key="u.unit" :value="u.unit">{{ u.unit }}</option>
                </select>
              </label>
              <label>Hotel price (RM) <input v-model="pricingDraft.price" type="number" required min="0" step="0.01" /></label>
            </div>
            <div class="actions"><button class="secondary" :disabled="busy" type="button" @click="pricingDraft = null">Back</button><button class="primary" :disabled="busy" type="submit">Save custom price</button></div>
          </form>
          <div v-if="pricingCustomer && prices.length" class="table-wrap"><table>
            <thead><tr><th>Product</th><th>Unit</th><th class="right">Hotel price</th><th></th></tr></thead>
            <tbody><tr v-for="p in prices" :key="p.productId + ':' + p.unit">
              <td>{{ productFor(p.productId)?.name || p.productName || 'Product ' + p.productId }}</td><td>{{ p.unit }}</td><td class="right">{{ money(p.price) }}</td>
              <td class="right"><button class="secondary compact" :disabled="busy" type="button" @click="editPrice(p)">Edit price</button> <button class="secondary compact" :disabled="busy" type="button" @click="removePrice(p)">Remove</button></td>
            </tr></tbody>
          </table></div>
          <div v-else-if="!pricingDraft" class="empty"><div class="empty-icon"><Icon name="prices" /></div><h2>{{ pricingCustomer ? 'No custom prices' : 'Select a hotel' }}</h2><p>{{ pricingCustomer ? 'This hotel currently uses base prices for all products.' : 'Choose the hotel whose prices you want to view or edit.' }}</p></div>
        </section>
      </template>
    </main>
  </div>
</template>
