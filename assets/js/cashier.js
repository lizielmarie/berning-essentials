/**
 * BERNING STORE — CASHIER POS JAVASCRIPT
 * Fully Database-Driven Barcode Scanner, Inventory & Point-of-Sale System
 * Single Source of Truth: MySQL Database (berning_store)
 */

// ── STATE VARIABLES ──
let dbProducts     = [];     // Loaded dynamically from MySQL database via API
let orderItems     = [];     // Active transaction cart items
let activeCategory = 'all';  // Category filter ('all' or category_id)
let payMethod      = 'cash'; // 'cash', 'card', 'gcash'
let appliedPromo   = null;   // Applied promo code
let orderCount     = 1;

const VAT_RATE   = 0.12;
const PROMO_CODE = 'BERNINGFRESH';
const PROMO_DISC = 0.20;

// Scanner Buffer for Global Keystroke Auto-Detection
let scannerBuffer   = '';
let scannerLastTime = 0;

// ── INITIALIZATION ──
document.addEventListener('DOMContentLoaded', () => {
  setupBarcodeInput();
  setupSearch();
  startClock();
  setupKeyboardShortcuts();
  setupGlobalClickFocus();

  // Requirement 1 & 8: Load products dynamically from MySQL database (No hardcoded data!)
  loadProductsFromDatabase();

  // Requirement 5: Auto-focus barcode input on load
  focusBarcodeInput();
});

// Helper: Focus and select barcode input field
function focusBarcodeInput() {
  const barcodeInput = document.getElementById('pos-barcode-input');
  if (barcodeInput && document.activeElement !== barcodeInput) {
    const activeId = document.activeElement ? document.activeElement.id : '';
    if (activeId !== 'pos-search-input' && activeId !== 'pos-promo-input' && activeId !== 'pos-tender-input') {
      barcodeInput.focus();
      barcodeInput.select();
    }
  }
}

// ── REAL-TIME DATABASE FETCH (REQUIREMENT 1, 3, 7, 8) ──
async function loadProductsFromDatabase() {
  const grid = document.getElementById('pos-products-grid');
  if (grid) {
    grid.innerHTML = `
      <div class="pos-no-results">
        <i class="fas fa-spinner fa-spin" style="font-size:2.5rem;color:var(--pos-accent);"></i>
        <p style="margin-top:12px;">Loading products from database…</p>
      </div>`;
  }

  try {
    const res  = await fetch('api/get_products.php');
    const data = await res.json();

    if (data.success && Array.isArray(data.products)) {
      dbProducts = data.products;
      renderCategories();
      renderProducts(document.getElementById('pos-search-input') ? document.getElementById('pos-search-input').value : '');
    } else {
      showPosToast('Failed to load products from database', 'danger');
    }
  } catch (err) {
    console.error('Error fetching database products:', err);
    showPosToast('Database connection error while loading products', 'danger');
  }
}

// ── RENDER DYNAMIC CATEGORY TABS FROM DATABASE ──
function renderCategories() {
  const bar = document.getElementById('pos-category-bar');
  if (!bar) return;

  // Extract unique categories from dbProducts
  const categoriesMap = {};
  dbProducts.forEach(p => {
    if (p.category_id && p.category_name) {
      categoriesMap[p.category_id] = p.category_name;
    }
  });

  let html = `
    <button class="pos-cat-btn ${activeCategory === 'all' ? 'active' : ''}" data-cat="all" onclick="selectCategory('all')">
      <i class="fas fa-th-large"></i> All
    </button>`;

  Object.keys(categoriesMap).forEach(catId => {
    const catName = categoriesMap[catId];
    const isActive = String(activeCategory) === String(catId);
    html += `
      <button class="pos-cat-btn ${isActive ? 'active' : ''}" data-cat="${catId}" onclick="selectCategory('${catId}')">
        <i class="fas fa-layer-group"></i> ${catName}
      </button>`;
  });

  bar.innerHTML = html;
}

function selectCategory(catId) {
  activeCategory = catId;
  renderCategories();
  renderProducts(document.getElementById('pos-search-input') ? document.getElementById('pos-search-input').value : '');
  focusBarcodeInput();
}

// ── RENDER PRODUCT CARDS GRID FROM DATABASE PRODUCTS ──
function renderProducts(keyword = '') {
  const grid = document.getElementById('pos-products-grid');
  if (!grid) return;

  const filtered = dbProducts.filter(p => {
    const catMatch = activeCategory === 'all' || String(p.category_id) === String(activeCategory);
    const kwMatch  = !keyword ||
      p.name.toLowerCase().includes(keyword.toLowerCase()) ||
      (p.category_name && p.category_name.toLowerCase().includes(keyword.toLowerCase())) ||
      p.barcode.includes(keyword);
    return catMatch && kwMatch;
  });

  if (!filtered.length) {
    grid.innerHTML = `
      <div class="pos-no-results">
        <i class="fas fa-search"></i>
        <p>No matching products in database</p>
        <span>Scan a registered barcode or search by name</span>
      </div>`;
    return;
  }

  // Placeholder images mapping by category name
  const categoryImages = {
    'Beverages': 'images/fresh_produce.png',
    'Snacks': 'images/bakery_pantry.png',
    'Canned Goods': 'images/hero_basket.png',
    'Instant Noodles': 'images/hero_basket.png',
    'Rice': 'images/hero_basket.png',
    'Condiments': 'images/bakery_pantry.png',
    'Dairy Products': 'images/hero_basket.png',
    'Personal Care': 'images/hero_basket.png',
    'Cleaning Supplies': 'images/bakery_pantry.png',
    'Biscuits': 'images/bakery_pantry.png'
  };

  grid.innerHTML = filtered.map(p => {
    const isOut = p.stock <= 0;
    const isLow = p.stock > 0 && p.stock <= (p.reorder_level || 5);
    const stockClass = isOut ? 'out' : isLow ? 'low' : '';
    const stockText  = isOut ? 'Out of Stock' : `Stock: ${p.stock} pcs`;
    const imgSrc     = categoryImages[p.category_name] || 'images/hero_basket.png';

    return `
      <div class="pos-product-card ${isOut ? 'out-of-stock' : ''}" onclick="addDatabaseProductToOrder(${p.id})" id="pcard-${p.id}">
        <div class="prod-img">
          <img src="${imgSrc}" alt="${p.name}" loading="lazy">
        </div>
        <div class="prod-body">
          <div class="prod-cat-tag">${p.category_name}</div>
          <div class="prod-name">${p.name}</div>
          <div class="prod-barcode"><i class="fas fa-barcode"></i> ${p.barcode}</div>
          <div class="prod-stock-badge ${stockClass}" style="margin-top:4px;">
            <i class="fas ${isOut ? 'fa-circle-xmark' : isLow ? 'fa-triangle-exclamation' : 'fa-boxes-stacked'}"></i>
            ${stockText}
          </div>
          <div class="prod-footer" style="margin-top:8px;">
            <div class="prod-price">&#8369;${p.price.toFixed(2)}</div>
            <div class="prod-add-btn"><i class="fas ${isOut ? 'fa-ban' : 'fa-plus'}"></i></div>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// ── MANUAL SEARCH INPUT ──
function setupSearch() {
  const searchInput = document.getElementById('pos-search-input');
  if (searchInput) {
    searchInput.addEventListener('input', e => {
      renderProducts(e.target.value.trim());
    });
  }
}

// ── REAL-TIME DATABASE BARCODE SCANNING (REQUIREMENTS 1, 2, 3, 4, 5, 6, 7, 8) ──
function setupBarcodeInput() {
  const inputEl  = document.getElementById('pos-barcode-input');
  const groupEl  = document.getElementById('pos-barcode-group');
  const statusEl = document.getElementById('barcode-status');

  if (!inputEl) return;

  // Requirement 2 & 3: Handle scanner Enter key input
  inputEl.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const rawCode = inputEl.value;
      handleBarcodeScan(rawCode, inputEl, groupEl, statusEl);
    }
  });

  // Global Keystroke Listener for Hardware Scanners
  document.addEventListener('keypress', e => {
    const activeTag = document.activeElement ? document.activeElement.tagName : '';
    if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') {
      if (document.activeElement.id !== 'pos-barcode-input') return;
    }

    const now = Date.now();
    if (now - scannerLastTime > 250) {
      scannerBuffer = '';
    }
    scannerLastTime = now;

    if (e.key === 'Enter') {
      if (scannerBuffer.length >= 3) {
        handleBarcodeScan(scannerBuffer, inputEl, groupEl, statusEl);
        scannerBuffer = '';
      }
    } else {
      scannerBuffer += e.key;
    }
  });
}

// Perform Real-Time Database Query on Barcode Scan
async function handleBarcodeScan(rawCode, inputEl, groupEl, statusEl) {
  const cleanBarcode = String(rawCode || '').trim().replace(/[^a-zA-Z0-9-]/g, '');

  if (!cleanBarcode) {
    focusBarcodeInput();
    return;
  }

  try {
    // Requirement 3: Every scan performs a real-time lookup against current database tables
    const response = await fetch(`api/get_product_by_barcode.php?barcode=${encodeURIComponent(cleanBarcode)}`);
    const data     = await response.json();

    if (data.success && data.product) {
      // Product found in database!
      const product = data.product;

      // Update in-memory dbProducts list with latest database record
      const existingIdx = dbProducts.findIndex(p => p.id === product.id);
      if (existingIdx !== -1) {
        dbProducts[existingIdx] = product;
      } else {
        dbProducts.push(product);
      }

      // Add to transaction cart & check stock
      const added = addDatabaseProductToOrder(product.id, 1, true);

      if (added) {
        // Scan Success UI Feedback
        groupEl.classList.remove('scan-error');
        groupEl.classList.add('scan-success');
        statusEl.className = 'barcode-status ok';
        statusEl.textContent = `✓ ${product.name} added`;

        setTimeout(() => {
          groupEl.classList.remove('scan-success');
          statusEl.textContent = '';
        }, 1500);
      } else {
        // Stock limit reached
        groupEl.classList.remove('scan-success');
        groupEl.classList.add('scan-error');
        statusEl.className = 'barcode-status err';
        statusEl.textContent = `⚠ Insufficient stock in database (${product.stock} pcs available)`;

        setTimeout(() => {
          groupEl.classList.remove('scan-error');
          statusEl.textContent = '';
        }, 2000);
      }
    } else {
      // Requirement 4: Exact error message when scanned barcode does not exist in database
      // "Product not found. This barcode is not registered in the database."
      const exactErrMsg = data.message || "Product not found. This barcode is not registered in the database.";

      groupEl.classList.remove('scan-success');
      groupEl.classList.add('scan-error');
      statusEl.className = 'barcode-status err';
      statusEl.textContent = exactErrMsg;

      showPosToast(exactErrMsg, 'danger');

      setTimeout(() => {
        groupEl.classList.remove('scan-error');
        statusEl.textContent = '';
      }, 3000);
    }
  } catch (err) {
    console.error('Error in barcode lookup API:', err);
    showPosToast('Server connection error during barcode lookup', 'danger');
  }

  // Requirement 5: Return focus to barcode field after every scan
  inputEl.value = '';
  setTimeout(() => {
    inputEl.focus();
  }, 50);
}

// ── ADD DATABASE PRODUCT TO TRANSACTION CART (REQUIREMENTS 2, 5, 6) ──
function addDatabaseProductToOrder(productId, quantityToAdd = 1, fromScan = false) {
  const product = dbProducts.find(p => p.id === productId);
  if (!product) return false;

  const existingInCart = orderItems.find(i => i.id === productId);
  const currentCartQty = existingInCart ? existingInCart.qty : 0;
  const requestedTotalQty = currentCartQty + quantityToAdd;

  // Requirement 6: Check database stock before adding
  if (product.stock <= 0) {
    showPosToast(`"${product.name}" is out of stock in database!`, 'danger');
    return false;
  }

  if (requestedTotalQty > product.stock) {
    const warningMsg = `Cannot exceed database stock for "${product.name}". Only ${product.stock} available.`;
    showPosToast(warningMsg, 'warning');
    return false;
  }

  // Requirement 5: Increase cart quantity on duplicate scans
  if (existingInCart) {
    existingInCart.qty += quantityToAdd;
  } else {
    orderItems.push({
      id: product.id,
      name: product.name,
      price: product.price,
      barcode: product.barcode,
      category_name: product.category_name,
      stock: product.stock,
      qty: quantityToAdd
    });
  }

  updateOrderUI();

  if (!fromScan) {
    showPosToast(`Added ${product.name} to order`);
  }

  // Card highlight effect
  const card = document.getElementById(`pcard-${productId}`);
  if (card) {
    card.style.borderColor = 'var(--pos-accent)';
    card.style.boxShadow   = '0 0 18px rgba(16,185,129,.5)';
    setTimeout(() => { card.style.borderColor = ''; card.style.boxShadow = ''; }, 600);
  }

  return true;
}

// ── ADJUST CART QUANTITY ──
function changeQty(productId, delta) {
  const idx = orderItems.findIndex(i => i.id === productId);
  if (idx === -1) return;

  const item = orderItems[idx];
  const product = dbProducts.find(p => p.id === productId);
  const targetQty = item.qty + delta;

  if (delta > 0 && product) {
    if (targetQty > product.stock) {
      showPosToast(`Cannot exceed current database stock (${product.stock} pcs)`, 'warning');
      return;
    }
  }

  if (targetQty <= 0) {
    orderItems.splice(idx, 1);
    showPosToast(`Removed ${item.name} from order`);
  } else {
    item.qty = targetQty;
  }

  updateOrderUI();
  focusBarcodeInput();
}

// ── VOID ORDER ──
function voidOrder() {
  if (orderItems.length === 0) {
    showPosToast('Current order is already empty');
    focusBarcodeInput();
    return;
  }
  document.getElementById('void-modal-overlay').classList.add('active');
}

function closeVoidModal() {
  document.getElementById('void-modal-overlay').classList.remove('active');
  focusBarcodeInput();
}

function confirmVoid() {
  orderItems     = [];
  appliedPromo   = null;
  document.getElementById('pos-promo-input').value       = '';
  document.getElementById('pos-promo-status').textContent = '';
  document.getElementById('pos-promo-status').className   = 'pos-promo-status';
  closeVoidModal();
  updateOrderUI();
  showPosToast('Order voided successfully');
  focusBarcodeInput();
}

// ── PROMO CODE DISCOUNT ──
function applyPosPromo() {
  const codeInput = document.getElementById('pos-promo-input');
  const code      = codeInput.value.trim().toUpperCase();
  const statusEl  = document.getElementById('pos-promo-status');
  const discRow   = document.getElementById('pos-discount-row');

  if (code === PROMO_CODE) {
    appliedPromo = code;
    statusEl.textContent = '✓ BERNINGFRESH applied — 20% discount!';
    statusEl.className   = 'pos-promo-status success';
    if (discRow) discRow.style.display = 'flex';
    updateOrderUI();
    showPosToast('Promo code applied — 20% off total!');
  } else if (code === '') {
    statusEl.textContent = 'Enter a promo code first.';
    statusEl.className   = 'pos-promo-status error';
  } else {
    appliedPromo = null;
    statusEl.textContent = `✗ "${code}" is not a valid promo code.`;
    statusEl.className   = 'pos-promo-status error';
    if (discRow) discRow.style.display = 'none';
    updateOrderUI();
  }
  focusBarcodeInput();
}

// ── PAYMENT METHOD SELECTOR ──
function selectPayMethod(method) {
  payMethod = method;
  document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.remove('active'));
  const btn = document.getElementById(`pay-${method}`);
  if (btn) btn.classList.add('active');

  const cashSection = document.getElementById('pos-cash-tender-section');
  if (cashSection) {
    cashSection.style.display = method === 'cash' ? 'block' : 'none';
  }
  calcChange();
  if (method !== 'cash') focusBarcodeInput();
}

// ── QUICK CASH BILL BUTTONS ──
function setTender(amount) {
  const tenderInput = document.getElementById('pos-tender-input');
  if (tenderInput) {
    tenderInput.value = amount;
    calcChange();
  }
}

// ── CALCULATE CASH CHANGE ──
function calcChange() {
  const total          = computeTotal();
  const tenderInput    = document.getElementById('pos-tender-input');
  const tender         = parseFloat(tenderInput ? tenderInput.value : 0) || 0;
  const changeEl       = document.getElementById('pos-change-display');
  const changeAmountEl = document.getElementById('pos-change-amount');

  if (!changeAmountEl) return;

  if (payMethod === 'cash' && tender > 0 && tender < total) {
    if (changeEl) {
      changeEl.style.borderColor = 'rgba(239,68,68,.4)';
      changeEl.style.background  = 'rgba(239,68,68,.08)';
    }
    changeAmountEl.style.color = 'var(--pos-red)';
    changeAmountEl.textContent = `₱${(total - tender).toFixed(2)} short`;
  } else {
    if (changeEl) {
      changeEl.style.borderColor = '';
      changeEl.style.background  = '';
    }
    changeAmountEl.style.color = 'var(--pos-accent)';
    changeAmountEl.textContent = `₱${Math.max(0, tender - total).toFixed(2)}`;
  }
}

// ── COMPUTATIONS ──
function computeSubtotal() {
  return orderItems.reduce((sum, item) => sum + (item.price * item.qty), 0);
}

function computeTotal() {
  const sub  = computeSubtotal();
  const disc = appliedPromo ? sub * PROMO_DISC : 0;
  const tax  = (sub - disc) * VAT_RATE;
  return sub - disc + tax;
}

// ── UPDATE CART ORDER UI (REQUIREMENT 2) ──
function updateOrderUI() {
  const container = document.getElementById('pos-order-items');
  const sub  = computeSubtotal();
  const disc = appliedPromo ? sub * PROMO_DISC : 0;
  const tax  = (sub - disc) * VAT_RATE;
  const total = (sub - disc) + tax;

  if (!container) return;

  if (orderItems.length === 0) {
    container.innerHTML = '';
    container.appendChild(createEmptyState());
  } else {
    container.innerHTML = orderItems.map(item => {
      const itemSubtotal = (item.price * item.qty).toFixed(2);
      return `
        <div class="pos-order-item">
          <div class="order-item-info">
            <div class="order-item-name" title="${item.name}">${item.name}</div>
            <div class="order-item-unit">₱${item.price.toFixed(2)} &times; ${item.qty}</div>
          </div>
          <div class="order-qty-ctrl">
            <button class="qty-minus" onclick="changeQty(${item.id}, -1)" title="Decrease Quantity"><i class="fas fa-minus"></i></button>
            <span class="qty-val">${item.qty}</span>
            <button class="qty-plus" onclick="changeQty(${item.id}, 1)" title="Increase Quantity"><i class="fas fa-plus"></i></button>
          </div>
          <div class="order-item-price">₱${itemSubtotal}</div>
        </div>
      `;
    }).join('');
  }

  document.getElementById('pos-subtotal').textContent    = `₱${sub.toFixed(2)}`;
  document.getElementById('pos-discount-val').textContent = `-₱${disc.toFixed(2)}`;
  document.getElementById('pos-tax').textContent          = `₱${tax.toFixed(2)}`;
  document.getElementById('pos-total').textContent        = `₱${total.toFixed(2)}`;

  const discRow = document.getElementById('pos-discount-row');
  if (discRow) discRow.style.display = appliedPromo ? 'flex' : 'none';

  const chargeBtn   = document.getElementById('pos-charge-btn');
  const chargeLabel = document.getElementById('pos-charge-label');
  if (chargeLabel) chargeLabel.textContent = `Charge ₱${total.toFixed(2)}`;
  if (chargeBtn)   chargeBtn.disabled = orderItems.length === 0;

  calcChange();
}

function createEmptyState() {
  const div = document.createElement('div');
  div.className = 'pos-empty-order';
  div.id        = 'pos-empty-state';
  div.innerHTML = `
    <i class="fas fa-shopping-basket"></i>
    <p>No items in cart</p>
    <span>Scan database barcode to add item</span>`;
  return div;
}

// ── CHARGE & DATABASE SALE TRANSACTION PROCESS (REQUIREMENT 7) ──
async function processCharge() {
  if (orderItems.length === 0) return;

  const total = computeTotal();

  if (payMethod === 'cash') {
    const tender = parseFloat(document.getElementById('pos-tender-input').value) || 0;
    if (tender < total) {
      showPosToast(`Cash tendered (₱${tender.toFixed(2)}) is less than total (₱${total.toFixed(2)})!`, 'warning');
      document.getElementById('pos-tender-input').focus();
      return;
    }
  }

  // Requirement 7: Process sale & update inventory stock in MySQL database
  try {
    const response = await fetch('api/process_sale.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        cashier_id: 2, // Juan Dela Cruz (Cashier)
        total_amount: total,
        items: orderItems.map(i => ({
          id: i.id,
          qty: i.qty,
          price: i.price
        }))
      })
    });

    const result = await response.json();

    if (result.success) {
      // Display Official Receipt with sale_id from database
      showReceipt(result.order_no || result.sale_id);
      
      // Re-sync products & stock levels directly from database
      await loadProductsFromDatabase();
    } else {
      showPosToast(`Sale Error: ${result.message}`, 'danger');
    }
  } catch (err) {
    console.error('Error submitting transaction to database:', err);
    showPosToast('Database transaction failed. Please check connection.', 'danger');
  }
}

// Show Receipt Modal
function showReceipt(officialReceiptNo) {
  const sub    = computeSubtotal();
  const disc   = appliedPromo ? sub * PROMO_DISC : 0;
  const tax    = (sub - disc) * VAT_RATE;
  const total  = (sub - disc) + tax;
  const now    = new Date();
  const dateStr = now.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
  const timeStr = now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });
  const orderNum = officialReceiptNo || `BS-${String(orderCount).padStart(4,'0')}`;
  const tender  = parseFloat(document.getElementById('pos-tender-input').value) || total;
  const change  = Math.max(0, tender - total);
  const payLabels = { cash: 'Cash Payment', card: 'Card Payment', gcash: 'GCash Payment' };

  document.getElementById('receipt-body').innerHTML = `
    <div class="receipt-store-header">
      <h3><i class="fas fa-leaf"></i> Berning Store</h3>
      <p>Farm-Fresh Organic Groceries</p>
      <p style="margin-top:4px;font-size:.7rem;">OR No: <strong style="color:var(--pos-accent);">${orderNum}</strong></p>
    </div>
    <div class="receipt-meta">
      <span>${dateStr}</span>
      <span>${timeStr} &middot; Cashier: Juan Dela Cruz</span>
    </div>
    <div class="receipt-items">
      ${orderItems.map(i => `
        <div class="receipt-item-row">
          <span><span class="ri-name">${i.name}</span><span class="ri-qty"> &times;${i.qty}</span></span>
          <span class="ri-price">₱${(i.price * i.qty).toFixed(2)}</span>
        </div>
      `).join('')}
    </div>
    <div class="receipt-totals">
      <div class="receipt-total-row"><span>Subtotal</span><span>₱${sub.toFixed(2)}</span></div>
      ${appliedPromo ? `<div class="receipt-total-row"><span>Discount (${PROMO_CODE})</span><span style="color:var(--pos-accent);">-₱${disc.toFixed(2)}</span></div>` : ''}
      <div class="receipt-total-row"><span>VAT (12%)</span><span>₱${tax.toFixed(2)}</span></div>
      <div class="receipt-total-row final"><span>TOTAL</span><span>₱${total.toFixed(2)}</span></div>
      ${payMethod === 'cash' ? `
        <div class="receipt-total-row" style="margin-top:6px;"><span>Cash Tendered</span><span>₱${tender.toFixed(2)}</span></div>
        <div class="receipt-total-row" style="color:var(--pos-accent);font-weight:700;"><span>Change Due</span><span>₱${change.toFixed(2)}</span></div>
      ` : ''}
    </div>
    <div class="receipt-payment-row">
      <i class="fas fa-${payMethod==='cash'?'money-bill-wave':payMethod==='card'?'credit-card':'mobile-screen-button'}"></i>
      ${payLabels[payMethod]} &middot; Database Transaction Recorded!
    </div>
  `;

  document.getElementById('receipt-modal-overlay').classList.add('active');
  orderCount++;
}

// Thermal Printer View
function printReceipt() {
  const content = document.getElementById('receipt-body').innerHTML;
  const win = window.open('', '_blank', 'width=380,height=600');
  win.document.write(`
    <html><head><title>Receipt - Berning Store</title>
    <style>
      body{font-family:'Courier New',monospace;font-size:12px;padding:16px;max-width:320px;margin:0 auto;color:#000;}
      h3{text-align:center;font-size:14px;} p{text-align:center;margin:2px 0;}
      .receipt-item-row,.receipt-total-row{display:flex;justify-content:space-between;margin:4px 0;}
      .receipt-store-header,.receipt-meta,.receipt-items,.receipt-totals{margin-bottom:10px;}
      .receipt-items,.receipt-total-row.final{border-top:1px dashed #000;padding-top:6px;}
      .receipt-payment-row{text-align:center;margin-top:10px;border-top:1px dashed #000;padding-top:8px;}
      .ri-qty{color:#555;}
    </style></head>
    <body>${content}</body></html>
  `);
  win.document.close();
  win.focus();
  win.print();
  win.close();
}

// Start New Order Reset
function startNewOrder() {
  orderItems   = [];
  appliedPromo = null;
  document.getElementById('pos-promo-input').value        = '';
  document.getElementById('pos-promo-status').textContent  = '';
  document.getElementById('pos-promo-status').className    = 'pos-promo-status';
  document.getElementById('pos-tender-input').value        = '';
  document.getElementById('pos-barcode-input').value       = '';
  document.getElementById('barcode-status').textContent    = '';
  document.getElementById('receipt-modal-overlay').classList.remove('active');

  updateOrderUI();
  showPosToast('Ready for next transaction!');
  focusBarcodeInput();
}

// ── TOAST NOTIFICATION ──
function showPosToast(msg, type = 'success') {
  const toast = document.getElementById('pos-toast');
  const msgEl = document.getElementById('pos-toast-msg');
  if (!toast || !msgEl) return;

  msgEl.textContent = msg;
  toast.className = `pos-toast show ${type}`;

  const icon = toast.querySelector('i');
  if (icon) {
    icon.className = type === 'danger' ? 'fas fa-circle-xmark' :
                     type === 'warning' ? 'fas fa-triangle-exclamation' :
                     'fas fa-check-circle';
  }

  setTimeout(() => toast.classList.remove('show'), 3000);
}

// ── KEYBOARD SHORTCUTS ──
function setupKeyboardShortcuts() {
  document.addEventListener('keydown', e => {
    if (e.ctrlKey && e.key.toLowerCase() === 'f') {
      e.preventDefault();
      const searchInput = document.getElementById('pos-search-input');
      if (searchInput) searchInput.focus();
    }
    if (e.ctrlKey && e.key.toLowerCase() === 'b') {
      e.preventDefault();
      focusBarcodeInput();
    }
    if (e.key === 'Escape') {
      document.getElementById('receipt-modal-overlay').classList.remove('active');
      document.getElementById('void-modal-overlay').classList.remove('active');
      focusBarcodeInput();
    }
    if (e.key === 'F1') {
      e.preventDefault();
      const chargeBtn = document.getElementById('pos-charge-btn');
      if (chargeBtn && !chargeBtn.disabled) processCharge();
    }
  });
}

// Re-focus barcode field when user clicks non-input background areas
function setupGlobalClickFocus() {
  document.addEventListener('click', e => {
    const target = e.target;
    if (target.closest('input, button, a, select, textarea, .pos-product-card, .pos-modal-overlay.active')) {
      return;
    }
    focusBarcodeInput();
  });
}
