/* =====================================================================
   MORNING CATCH — Frontend logic (Vanilla JS, no build step)

   What this file does:
     1. Loads today's catch from api/get_products.php
        (falls back to sample data in "preview mode" if the PHP isn't live yet)
     2. Renders fish cards with cut options, quantity steppers and stock bars
     3. Keeps a basket (several fish in one order), saved on the phone
     4. Validates the checkout form (Indian WhatsApp number, email, address)
     5. Asks the server to create a Razorpay order  → api/create_order.php
     6. Opens the Razorpay checkout overlay
     7. Sends the payment signature to the server   → api/verify_payment.php
     8. Shows the receipt screen

   SECURITY NOTE: the browser only sends product IDs, quantities and cuts.
   The server recalculates every price from the database, so nobody can
   edit the page and pay ₹1 for a kingfish.
   ===================================================================== */

(() => {
  'use strict';

  /* -------------------------------------------------------------------
     0. SETTINGS — edit these, or send them from get_products.php
        under a "settings" key (server values win).
     ------------------------------------------------------------------- */
  const CONFIG = {
    API_BASE: 'api/',                 // folder holding the PHP endpoints
    SHOP_NAME: 'Morning Catch',
    LANDED_AT: '5:45 AM',             // shown in the hero pill
    CUTOFF: '10:30',                  // 24h "HH:MM" — bookings close after this
    DELIVERY_FEE: 40,                 // ₹ — must match the PHP config!
    FREE_DELIVERY_ABOVE: 999,         // ₹ — free delivery from this subtotal (0 = never free)
    SLOTS: {
      delivery: ['7:00 – 8:00 AM', '8:00 – 9:00 AM', '9:00 – 10:00 AM', '10:00 – 11:00 AM', '11:00 AM – 12:00 PM'],
      pickup:   ['7:00 – 9:00 AM', '9:00 – 11:00 AM', '11:00 AM – 1:00 PM'],
    },
    THEME_COLOR: '#F2643D',           // Razorpay overlay accent

    /* Seller name, contact details and licence numbers. The server sends
       the real values from the `business` block of the config file, so
       nobody has to edit index.html to rebrand the site. */
    BUSINESS: {
      SHOP_NAME: 'Morning Catch',
      LEGAL_NAME: 'Morning Catch',
      TAGLINE: 'Fresh off the boat, kept aside for you',
      ADDRESS_LINES: [],
      PHONE: '',
      WHATSAPP_LINK: '',
      EMAIL: '',
      HOURS: '',
      FSSAI: '',
      GSTIN: '',
    },
  };

  /* Sample catch, used only in preview mode (opening index.html locally). */
  const SAMPLE_PRODUCTS = [
    { id: 1, name: 'Kingfish',       local_name: 'Surmai',   description: 'Firm, meaty steaks. Perfect for rava fry.',       category: 'fish',      unit: 'kg',    price: 900, min_qty: 0.5, step_qty: 0.5, stock_qty: 12, initial_stock: 15, cut_options: 'Whole,Cleaned+50,Steaks (fry cut)+60,Curry cut+60', badge: 'Bestseller' },
    { id: 2, name: 'Silver Pomfret', local_name: 'Paplet',   description: 'Medium size, about 350–400 g each.',              category: 'fish',      unit: 'piece', price: 380, min_qty: 1,   step_qty: 1,   stock_qty: 18, initial_stock: 24, cut_options: 'Whole,Cleaned+20,Scored for tawa fry+25',        badge: 'Just landed' },
    { id: 3, name: 'Mackerel',       local_name: 'Bangda',   description: 'Oily and full of flavour. Great for recheado.',   category: 'fish',      unit: 'kg',    price: 260, min_qty: 0.5, step_qty: 0.5, stock_qty: 20, initial_stock: 25, cut_options: 'Whole,Cleaned+40,Slit for masala+50',            badge: null },
    { id: 4, name: 'Tiger Prawns',   local_name: 'Kolambi',  description: 'Large, about 20–25 per kg.',                      category: 'shellfish', unit: 'kg',    price: 650, min_qty: 0.5, step_qty: 0.5, stock_qty: 2,  initial_stock: 10, cut_options: 'Whole,Peeled & deveined+120,Head-off+60',         badge: 'Few left' },
    { id: 5, name: 'Sardines',       local_name: 'Tarle',    description: 'Small and sweet. Best fried crisp.',              category: 'fish',      unit: 'kg',    price: 200, min_qty: 0.5, step_qty: 0.5, stock_qty: 15, initial_stock: 15, cut_options: 'Whole,Cleaned+40',                            badge: null },
    { id: 6, name: 'Lady Fish',      local_name: 'Muddoshi', description: 'Delicate white flesh. A local favourite.',         category: 'fish',      unit: 'kg',    price: 520, min_qty: 0.5, step_qty: 0.5, stock_qty: 8,  initial_stock: 10, cut_options: 'Whole,Cleaned+40,Butterflied+60',                badge: null },
    { id: 7, name: 'Mud Crab',       local_name: 'Kurlya',   description: 'Live crabs, about 300–400 g each.',               category: 'shellfish', unit: 'piece', price: 280, min_qty: 1,   step_qty: 1,   stock_qty: 0,  initial_stock: 12, cut_options: 'Whole (live),Cleaned & cut+40',               badge: null },
  ];

  /* -------------------------------------------------------------------
     1. STATE
     ------------------------------------------------------------------- */
  const state = {
    products: [],          // today's catch
    cart: [],              // [{ key, id, name, local_name, unit, price, qty, cut }]
    filter: 'all',
    demo: false,           // true when the PHP backend isn't reachable
    bookingOpen: true,
    slot: null,
    fulfilment: 'delivery',
  };

  // Per-card draft selections before "Add" is pressed: { [productId]: { qty, cut } }
  const drafts = {};

  /* -------------------------------------------------------------------
     2. SMALL HELPERS
     ------------------------------------------------------------------- */
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  /** ₹1,250 or ₹1,250.50 — Indian digit grouping */
  const money = (n) => '₹' + Number(n).toLocaleString('en-IN', {
    minimumFractionDigits: Number.isInteger(+n) ? 0 : 2,
    maximumFractionDigits: 2,
  });

  /** 0.5 kg → "500 g", 1.5 kg → "1.5 kg", 2 piece → "2 pcs" */
  const qtyLabel = (qty, unit) => {
    if (unit === 'piece') return `${qty} ${qty == 1 ? 'pc' : 'pcs'}`;
    return qty < 1 ? `${Math.round(qty * 1000)} g` : `${+qty.toFixed(2)} kg`;
  };

  /**
   * Cut options may carry a preparation charge, written "Cleaned+50" in the
   * seller's cut list. The number is per kg or per piece, on top of the fish
   * price. The server works the real total out again from the database — this
   * is only so the shopper sees the same figure before they pay.
   *
   * 'Whole,Cleaned+50' → [{ label: 'Whole', fee: 0 }, { label: 'Cleaned', fee: 50 }]
   */
  const parseCuts = (raw) => {
    const cuts = String(raw || 'Whole').split(',').map((part) => {
      const m = part.trim().match(/^(.+?)\s*\+\s*(\d+(?:\.\d{1,2})?)$/);
      return m ? { label: m[1].trim(), fee: +m[2] } : { label: part.trim(), fee: 0 };
    }).filter((c) => c.label);
    return cuts.length ? cuts : [{ label: 'Whole', fee: 0 }];
  };

  /** What a given cut adds per kg / per piece. */
  const cutFee = (p, label) => (p.cuts.find((c) => c.label === label) || { fee: 0 }).fee;

  /** '+₹50/kg', or '' when the cut is free */
  const cutFeeLabel = (fee, unit) => (fee > 0 ? `+${money(fee)}/${unit === 'piece' ? 'pc' : 'kg'}` : '');

  /** Avoid floating point drift: 0.1 + 0.2 = 0.3, not 0.30000000004 */
  const round2 = (n) => Math.round(n * 100) / 100;

  /** Escape text that came from the database before putting it into HTML */
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));

  /** localStorage can throw in private mode — never let that break the shop */
  const store = {
    get(key, fallback = null) { try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch { return fallback; } },
    set(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch { /* ignore */ } },
    del(key) { try { localStorage.removeItem(key); } catch { /* ignore */ } },
  };

  const todayKey = () => new Date().toISOString().slice(0, 10);

  let toastTimer;
  function toast(msg) {
    $('#toastInner').textContent = msg;
    const t = $('#toast');
    t.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (t.style.opacity = '0'), 2200);
  }

  function showLoader(text = 'Please wait…') { $('#loaderText').textContent = text; $('#loader').classList.replace('hidden', 'grid'); }
  function hideLoader() { $('#loader').classList.replace('grid', 'hidden'); }

  /** POST JSON to a PHP endpoint and return parsed JSON (throws on error) */
  async function api(endpoint, body) {
    const res = await fetch(CONFIG.API_BASE + endpoint, {
      method: body ? 'POST' : 'GET',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
    let data;
    try { data = await res.json(); } catch { throw new Error('The server sent an unexpected reply. Please try again.'); }
    if (!res.ok || data.success === false) throw new Error(data.message || 'Something went wrong. Please try again.');
    return data;
  }

  /* -------------------------------------------------------------------
     3. FISH ILLUSTRATIONS — lightweight inline SVG so the page looks good
        even before the seller uploads real photos (image_url).
     ------------------------------------------------------------------- */
  const ART_COLORS = [
    { bg: '#DDF0EE', body: '#5F8F9A', belly: '#C9DEE1', fin: '#3D6C77' },   // teal-grey
    { bg: '#FDF1D6', body: '#B9C3C9', belly: '#EEF2F4', fin: '#8C99A1' },   // silver
    { bg: '#E3ECF7', body: '#3E6A8C', belly: '#C7D9E6', fin: '#2A4E6B' },   // blue
    { bg: '#FDE7DF', body: '#E58A6A', belly: '#F7CDBD', fin: '#C2603F' },   // pink/coral
    { bg: '#EAF1E2', body: '#7E9A7C', belly: '#D5E2D0', fin: '#58745A' },   // green
  ];

  function fishArt(p) {
    const c = ART_COLORS[(p.id - 1) % ART_COLORS.length];
    if (p.category === 'shellfish' && /crab/i.test(p.name)) {
      return `<svg viewBox="0 0 200 120" class="h-28 w-auto drop-shadow-sm" aria-hidden="true">
        <g stroke="${c.fin}" stroke-width="5" stroke-linecap="round" fill="none">
          <path d="M62 70 L35 85 M62 78 L38 98 M138 70 L165 85 M138 78 L162 98"/>
          <path d="M70 52 Q50 25 40 30 M130 52 Q150 25 160 30"/>
        </g>
        <circle cx="38" cy="28" r="12" fill="${c.body}"/><circle cx="162" cy="28" r="12" fill="${c.body}"/>
        <ellipse cx="100" cy="68" rx="45" ry="30" fill="${c.body}"/>
        <ellipse cx="100" cy="76" rx="30" ry="14" fill="${c.belly}" opacity=".7"/>
        <circle cx="88" cy="46" r="4" fill="#0B2733"/><circle cx="112" cy="46" r="4" fill="#0B2733"/>
      </svg>`;
    }
    if (p.category === 'shellfish') {
      // Prawn
      return `<svg viewBox="0 0 200 120" class="h-28 w-auto drop-shadow-sm" aria-hidden="true">
        <path d="M150 35 Q180 60 150 92 Q110 110 70 95 Q45 85 40 60" fill="none" stroke="${c.body}" stroke-width="26" stroke-linecap="round"/>
        <path d="M150 35 Q180 60 150 92 Q110 110 70 95" fill="none" stroke="${c.belly}" stroke-width="6" stroke-dasharray="4 10" stroke-linecap="round"/>
        <path d="M40 60 L18 40 M40 60 L15 62 M40 60 L22 80" stroke="${c.fin}" stroke-width="4" stroke-linecap="round"/>
        <path d="M150 35 Q140 10 100 8 M150 35 Q150 8 120 2" fill="none" stroke="${c.fin}" stroke-width="2.5"/>
        <circle cx="146" cy="38" r="4" fill="#0B2733"/>
      </svg>`;
    }
    // Fish — slightly rounder for pomfret, slimmer for others
    const round = /pomfret/i.test(p.name);
    const ry = round ? 34 : 22;
    return `<svg viewBox="0 0 200 120" class="h-28 w-auto drop-shadow-sm" aria-hidden="true">
      <path d="M152 60 L192 ${round ? 30 : 36} L184 60 L192 ${round ? 90 : 84} Z" fill="${c.fin}"/>
      <ellipse cx="95" cy="60" rx="68" ry="${ry}" fill="${c.body}"/>
      <path d="M30 62 Q95 ${60 + ry + 6} 160 62 Q95 ${60 + ry / 2} 30 62Z" fill="${c.belly}"/>
      <path d="M80 ${60 - ry + 2} Q100 ${60 - ry - 16} 125 ${60 - ry + 4}" fill="${c.fin}"/>
      <path d="M58 46 Q64 60 58 74" stroke="${c.fin}" stroke-width="3" fill="none" stroke-linecap="round"/>
      <circle cx="44" cy="54" r="6" fill="#fff"/><circle cx="43" cy="54" r="3.2" fill="#0B2733"/>
      ${/mackerel|kingfish/i.test(p.name) ? `<path d="M70 44 Q80 52 74 60 M90 42 Q100 50 94 58 M110 42 Q120 50 114 58 M130 46 Q138 52 134 58" stroke="${c.fin}" stroke-width="3" fill="none" stroke-linecap="round" opacity=".7"/>` : ''}
    </svg>`;
  }

  /* -------------------------------------------------------------------
     4. LOAD PRODUCTS
     ------------------------------------------------------------------- */
  async function loadProducts() {
    // Opening the file directly (file://) can't run PHP — go straight to preview mode.
    if (location.protocol === 'file:') return enterDemo();

    try {
      const data = await api('get_products.php');
      if (data.settings) Object.assign(CONFIG, data.settings);   // server-side settings win
      state.products = (data.products || []).map(normaliseProduct);
    } catch (err) {
      console.warn('Backend not reachable, using sample data:', err.message);
      return enterDemo();
    }
    afterLoad();
  }

  /** Re-fetch stock after a purchase (no timers/listeners re-attached) */
  async function refreshStock() {
    if (state.demo) return;
    try {
      const data = await api('get_products.php');
      if (data.settings) Object.assign(CONFIG, data.settings);
      state.products = (data.products || []).map(normaliseProduct);
      updateCutoff();
      renderProducts();
    } catch { /* not critical */ }
  }

  function enterDemo() {
    state.demo = true;
    state.products = SAMPLE_PRODUCTS.map(normaliseProduct);
    $('#demoBanner').classList.remove('hidden');
    afterLoad();
  }

  /** MySQL DECIMALs arrive as strings — convert them to numbers once */
  function normaliseProduct(p) {
    return {
      ...p,
      id: +p.id,
      price: +p.price,
      min_qty: +p.min_qty || (p.unit === 'piece' ? 1 : 0.5),
      step_qty: +p.step_qty || (p.unit === 'piece' ? 1 : 0.5),
      stock_qty: +p.stock_qty,
      initial_stock: +p.initial_stock || +p.stock_qty,
      cuts: parseCuts(p.cut_options),
    };
  }

  /**
   * Write the seller's details into the page: brand line, footer address,
   * phone, email, WhatsApp button and licence numbers. Anything the seller
   * left blank in the config stays hidden instead of showing an empty row.
   */
  function applyBusiness() {
    const b = CONFIG.BUSINESS || {};
    const shop = b.SHOP_NAME || CONFIG.SHOP_NAME;

    /** Set an element's text, or hide it when there's nothing to show. */
    const fill = (sel, text, show = 'block') => {
      const el = $(sel);
      if (!el) return;
      el.textContent = text || '';
      el.classList.toggle('hidden', !text);
      if (text) el.classList.add(show);
    };

    if (shop) {
      document.title = `${shop} · Book today's fresh fish`;
      $('#brandName').textContent = shop;
      $('#footerName').textContent = shop;
      $('#footerLegalName').textContent = b.LEGAL_NAME || shop;
      $('#cartBtn').setAttribute('aria-label', `Open your basket`);
      $('a[aria-label$="home"]')?.setAttribute('aria-label', `${shop} home`);
    }
    if (b.TAGLINE) $('#brandTagline').textContent = b.TAGLINE;

    // Address — one line per line of the config's address
    const addr = $('#footerAddress');
    if (addr) {
      const lines = b.ADDRESS_LINES || [];
      addr.innerHTML = lines.map(esc).join('<br />');
      addr.classList.toggle('hidden', lines.length === 0);
    }

    fill('#footerHours', b.HOURS);

    const wa = $('#footerWhatsapp');
    if (wa) {
      if (b.WHATSAPP_LINK) { wa.href = b.WHATSAPP_LINK; wa.classList.remove('hidden'); wa.classList.add('inline-flex'); }
      else wa.classList.add('hidden');
    }

    const phone = $('#footerPhone');
    if (phone) {
      if (b.PHONE) { phone.href = `tel:${b.PHONE.replace(/[^\d+]/g, '')}`; phone.textContent = b.PHONE; phone.classList.remove('hidden'); }
      else phone.classList.add('hidden');
    }

    const email = $('#footerEmail');
    if (email) {
      if (b.EMAIL) { email.href = `mailto:${b.EMAIL}`; email.textContent = b.EMAIL; email.classList.remove('hidden'); }
      else email.classList.add('hidden');
    }

    // FSSAI is legally required on an Indian food business site; GST is optional.
    const licences = [b.FSSAI && `FSSAI registration no. ${b.FSSAI}`, b.GSTIN && `GSTIN ${b.GSTIN}`].filter(Boolean);
    fill('#footerLicences', licences.join(' · '));
  }

  function afterLoad() {
    applyBusiness();
    $('#landedText').textContent = `Today's catch landed at ${CONFIG.LANDED_AT}`;
    $('#footerLanded').textContent = CONFIG.LANDED_AT;
    restoreCart();
    updateCutoff();
    setInterval(updateCutoff, 30_000);
    renderProducts();
    renderCart();
    renderSlots();
    restoreCustomer();
  }

  /* -------------------------------------------------------------------
     5. BOOKING CUTOFF COUNTDOWN
     ------------------------------------------------------------------- */
  function updateCutoff() {
    const [h, m] = CONFIG.CUTOFF.split(':').map(Number);
    const now = new Date();
    const cutoff = new Date(now); cutoff.setHours(h, m, 0, 0);
    const label = cutoff.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' });
    const diff = cutoff - now;

    $('#cutoffLabel').textContent = `Bookings close at ${label}`;
    const footerCutoff = $('#footerCutoff');
    if (footerCutoff) footerCutoff.textContent = label;

    // The seller switched bookings off from /admin (or the server says it's past cutoff)
    if (!state.demo && CONFIG.BOOKING_OPEN === false) {
      $('#cutoffLabel').textContent = 'Closed for bookings';
      $('#cutoffTimer').textContent = CONFIG.CLOSED_REASON || 'See you tomorrow morning';
      $('#statusDot').classList.replace('bg-emerald-400', 'bg-coral');
      $('#statusPing').classList.add('hidden');
      state.bookingOpen = false;
      return;
    }

    if (diff > 0) {
      const hrs = Math.floor(diff / 3_600_000);
      const mins = Math.floor((diff % 3_600_000) / 60_000);
      $('#cutoffTimer').textContent = hrs ? `${hrs} hr ${mins} min left` : `Only ${mins} min left — hurry!`;
      state.bookingOpen = true;
    } else if (state.demo || CONFIG.IGNORE_CUTOFF) {
      // Preview, or the server's testing switch (shop.ignore_cutoff): keep ordering open
      $('#cutoffTimer').textContent = state.demo ? 'Closed for today (left open in preview)' : 'Past cutoff (left open: testing mode)';
      state.bookingOpen = true;
    } else {
      $('#cutoffTimer').textContent = 'Closed for today — see you tomorrow morning';
      $('#statusDot').classList.replace('bg-emerald-400', 'bg-coral');
      $('#statusPing').classList.add('hidden');
      state.bookingOpen = false;
    }
  }

  /* -------------------------------------------------------------------
     6. RENDER PRODUCT CARDS
     ------------------------------------------------------------------- */
  /** How much of a product is already in the basket (any cut) */
  const qtyInCart = (id, exceptKey) =>
    state.cart.filter((i) => i.id === id && i.key !== exceptKey).reduce((s, i) => s + i.qty, 0);

  function renderProducts() {
    const grid = $('#productGrid');
    const list = state.products.filter((p) => state.filter === 'all' || p.category === state.filter);

    $('#catchDate').textContent = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' });
    $('#emptyState').classList.toggle('hidden', list.length > 0);

    grid.innerHTML = list.map((p, i) => {
      const d = drafts[p.id] || (drafts[p.id] = { qty: p.min_qty, cut: p.cuts[0].label });
      const available = round2(p.stock_qty - qtyInCart(p.id));
      // Keep the stepper within what's still bookable
      if (d.qty > available) d.qty = Math.max(p.min_qty, available);
      const soldOut = p.stock_qty <= 0;
      const pctLeft = Math.max(0, Math.min(100, (p.stock_qty / (p.initial_stock || 1)) * 100));
      const low = pctLeft <= 25 && !soldOut;
      const disabled = soldOut || !state.bookingOpen || available < p.min_qty;
      const unitWord = p.unit === 'kg' ? 'kg' : 'piece';

      // A real photo fills the whole header area; without one we draw the fish.
      // Absolute positioning keeps every card the same height whatever shape
      // the seller's photo happens to be.
      const art = p.image_url
        ? `<img src="${esc(p.image_url)}" alt="${esc(p.name)}" loading="lazy" decoding="async"
                class="absolute inset-0 h-full w-full object-cover" />`
        : fishArt(p);
      const bg = ART_COLORS[(p.id - 1) % ART_COLORS.length].bg;

      return `
      <article class="rise flex flex-col overflow-hidden rounded-3xl bg-white shadow-card ${soldOut ? 'opacity-70' : ''}"
               style="animation-delay:${i * 50}ms" data-id="${p.id}">
        <!-- Art / photo -->
        <div class="relative grid h-40 place-items-center" style="background:${bg}">
          ${art}
          ${p.badge && !soldOut ? `<span class="absolute left-3 top-3 rounded-full bg-ink px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-sand">${esc(p.badge)}</span>` : ''}
          ${soldOut ? `<span class="absolute inset-0 grid place-items-center bg-white/60 font-display text-2xl font-bold text-ink">Sold out</span>` : ''}
        </div>

        <div class="flex flex-1 flex-col p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="font-display text-xl font-bold leading-tight">${esc(p.name)}</h3>
              ${p.local_name ? `<p class="text-sm font-medium text-sea">${esc(p.local_name)}</p>` : ''}
            </div>
            <p class="shrink-0 text-right">
              <span class="block text-lg font-bold">${money(p.price)}</span>
              <span class="block text-xs text-ink-soft">per ${unitWord}</span>
            </p>
          </div>
          ${p.description ? `<p class="mt-2 text-sm leading-snug text-ink-soft">${esc(p.description)}</p>` : ''}

          <!-- Stock bar -->
          <div class="mt-3">
            <div class="h-1.5 overflow-hidden rounded-full bg-sand-dark">
              <div class="h-full rounded-full ${low ? 'bg-coral' : 'bg-sea'}" style="width:${pctLeft}%"></div>
            </div>
            <p class="mt-1 text-xs font-medium ${low ? 'text-coral-dark' : 'text-ink-soft'}">
              ${soldOut ? 'All booked for today' : `${qtyLabel(p.stock_qty, p.unit)} left${low ? ' — going fast' : ''}`}
            </p>
          </div>

          ${soldOut ? '' : `
          <!-- Cut + quantity -->
          <div class="mt-4 grid grid-cols-[1fr_auto] gap-2">
            <label class="sr-only" for="cut-${p.id}">Cut for ${esc(p.name)}</label>
            <select id="cut-${p.id}" data-action="cut" data-id="${p.id}"
                    class="min-w-0 rounded-xl border-0 bg-sand py-2.5 pl-3 pr-8 text-sm font-medium ring-1 ring-ink/10 focus:ring-2 focus:ring-sea">
              ${p.cuts.map((c) => `<option value="${esc(c.label)}" ${c.label === d.cut ? 'selected' : ''}>${esc(c.label)}${c.fee > 0 ? ` (${cutFeeLabel(c.fee, p.unit)})` : ''}</option>`).join('')}
            </select>

            <div class="flex items-center rounded-xl bg-sand ring-1 ring-ink/10" role="group" aria-label="Quantity">
              <button type="button" data-action="dec" data-id="${p.id}" class="grid h-10 w-10 place-items-center rounded-l-xl text-lg font-bold hover:bg-sand-dark disabled:opacity-30" ${d.qty <= p.min_qty ? 'disabled' : ''} aria-label="Less">−</button>
              <span class="min-w-[64px] text-center text-sm font-bold tabular-nums">${qtyLabel(d.qty, p.unit)}</span>
              <button type="button" data-action="inc" data-id="${p.id}" class="grid h-10 w-10 place-items-center rounded-r-xl text-lg font-bold hover:bg-sand-dark disabled:opacity-30" ${round2(d.qty + p.step_qty) > available ? 'disabled' : ''} aria-label="More">+</button>
            </div>
          </div>

          <button type="button" data-action="add" data-id="${p.id}" ${disabled ? 'disabled' : ''}
                  class="mt-3 flex w-full items-center justify-between rounded-xl bg-ink px-4 py-3 text-sm font-semibold text-sand transition hover:bg-sea disabled:cursor-not-allowed disabled:bg-ink/30">
            <span>${!state.bookingOpen ? 'Bookings closed' : available < p.min_qty ? 'All in your basket' : 'Add to basket'}</span>
            <span data-role="price" class="tabular-nums">${money(round2(d.qty * (p.price + cutFee(p, d.cut))))}</span>
          </button>`}
        </div>
      </article>`;
    }).join('');
  }

  // One listener for every card (event delegation — cheap on old phones)
  $('#productGrid').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn || btn.tagName === 'SELECT') return;
    const p = state.products.find((x) => x.id === +btn.dataset.id);
    if (!p) return;
    const d = drafts[p.id];
    const available = round2(p.stock_qty - qtyInCart(p.id));

    if (btn.dataset.action === 'inc') d.qty = Math.min(round2(d.qty + p.step_qty), available);
    if (btn.dataset.action === 'dec') d.qty = Math.max(round2(d.qty - p.step_qty), p.min_qty);
    if (btn.dataset.action === 'add') addToCart(p, d.qty, d.cut);
    renderProducts();
  });

  $('#productGrid').addEventListener('change', (e) => {
    if (e.target.dataset.action !== 'cut') return;
    const id = +e.target.dataset.id;
    drafts[id].cut = e.target.value;

    // A cut with a preparation charge changes what the button costs, so
    // refresh that one figure. Re-rendering the whole grid here would shut
    // the dropdown the moment it was used.
    const p = state.products.find((x) => x.id === id);
    const priceEl = e.target.closest('article')?.querySelector('[data-role="price"]');
    if (p && priceEl) priceEl.textContent = money(round2(drafts[id].qty * (p.price + cutFee(p, drafts[id].cut))));
  });

  // Filter chips
  $$('.filter-chip').forEach((chip) => chip.addEventListener('click', () => {
    state.filter = chip.dataset.filter;
    $$('.filter-chip').forEach((c) => {
      const on = c === chip;
      c.setAttribute('aria-selected', on);
      c.className = `filter-chip rounded-full px-4 py-2 text-sm font-semibold ${on ? 'bg-ink text-sand' : 'bg-white text-ink shadow-card'}`;
    });
    renderProducts();
  }));

  /* -------------------------------------------------------------------
     7. BASKET
     ------------------------------------------------------------------- */
  function addToCart(p, qty, cut) {
    if (!state.bookingOpen) return toast('Bookings are closed for today');
    const key = `${p.id}|${cut}`;
    const existing = state.cart.find((i) => i.key === key);
    const allowed = round2(p.stock_qty - qtyInCart(p.id));
    if (qty > allowed) return toast(`Only ${qtyLabel(allowed, p.unit)} left`);

    if (existing) existing.qty = round2(existing.qty + qty);
    else state.cart.push({ key, id: p.id, name: p.name, local_name: p.local_name, unit: p.unit, price: p.price, fee: cutFee(p, cut), qty, cut });

    drafts[p.id].qty = p.min_qty;    // reset the card stepper
    saveCart();
    renderCart();
    toast(`${qtyLabel(qty, p.unit)} ${p.name} added`);
    const badge = $('#cartCount'); badge.classList.remove('pop'); void badge.offsetWidth; badge.classList.add('pop');
  }

  function changeCartQty(key, dir) {
    const item = state.cart.find((i) => i.key === key);
    const p = state.products.find((x) => x.id === item?.id);
    if (!item || !p) return;
    const next = round2(item.qty + dir * p.step_qty);
    if (next < p.min_qty) state.cart = state.cart.filter((i) => i.key !== key);
    else if (next > round2(p.stock_qty - qtyInCart(p.id, key))) return toast(`That's all the ${p.name} we have`);
    else item.qty = next;
    saveCart(); renderCart(); renderProducts();
  }

  const totals = () => {
    const subtotal = round2(state.cart.reduce((s, i) => s + i.qty * (i.price + (i.fee || 0)), 0));
    const freeOk = CONFIG.FREE_DELIVERY_ABOVE > 0 && subtotal >= CONFIG.FREE_DELIVERY_ABOVE;
    const delivery = state.fulfilment === 'pickup' || subtotal === 0 || freeOk ? 0 : CONFIG.DELIVERY_FEE;
    return { subtotal, delivery, total: round2(subtotal + delivery) };
  };

  function renderCart() {
    const count = state.cart.length;
    const { subtotal, delivery, total } = totals();

    // Header badge + sticky bar
    $('#cartCount').textContent = count;
    $('#cartCount').classList.toggle('hidden', count === 0);
    $('#cartBar').classList.toggle('translate-y-full', count === 0);
    $('#cartBarItems').textContent = `${count} ${count === 1 ? 'item' : 'items'} in basket`;
    $('#cartBarTotal').textContent = money(subtotal);

    // Sheet list
    $('#cartEmpty').classList.toggle('hidden', count > 0);
    $('#cartList').innerHTML = state.cart.map((i) => `
      <li class="flex items-center gap-3 rounded-2xl bg-white p-3 shadow-card">
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold">${esc(i.name)} <span class="font-normal text-ink-soft">· ${esc(i.cut)}${i.fee > 0 ? ` ${cutFeeLabel(i.fee, i.unit)}` : ''}</span></p>
          <p class="text-xs text-ink-soft">${money(i.price)} / ${i.unit === 'kg' ? 'kg' : 'pc'}</p>
        </div>
        <div class="flex items-center rounded-lg bg-sand ring-1 ring-ink/10">
          <button type="button" data-cart="-1" data-key="${esc(i.key)}" class="grid h-8 w-8 place-items-center font-bold" aria-label="Less">−</button>
          <span class="min-w-[54px] text-center text-xs font-bold tabular-nums">${qtyLabel(i.qty, i.unit)}</span>
          <button type="button" data-cart="1" data-key="${esc(i.key)}" class="grid h-8 w-8 place-items-center font-bold" aria-label="More">+</button>
        </div>
        <p class="w-16 text-right text-sm font-bold tabular-nums">${money(round2(i.qty * (i.price + (i.fee || 0))))}</p>
      </li>`).join('');

    // Bill summary
    $('#sumSubtotal').textContent = money(subtotal);
    $('#sumDelivery').textContent = state.fulfilment === 'pickup' ? 'Free (pickup)' : delivery === 0 && subtotal > 0 ? 'Free' : money(delivery);
    $('#sumTotal').textContent = money(total);
    $('#payBtnText').textContent = `Pay ${money(total)} securely`;
    $('#payBtn').disabled = count === 0;

    const hint = $('#freeDeliveryHint');
    const gap = round2(CONFIG.FREE_DELIVERY_ABOVE - subtotal);
    const showHint = state.fulfilment === 'delivery' && CONFIG.FREE_DELIVERY_ABOVE > 0 && subtotal > 0 && gap > 0;
    hint.classList.toggle('hidden', !showHint);
    if (showHint) hint.textContent = `Add ${money(gap)} more for free delivery`;
  }

  $('#cartList').addEventListener('click', (e) => {
    const b = e.target.closest('[data-cart]');
    if (b) changeCartQty(b.dataset.key, +b.dataset.cart);
  });

  /** Basket is saved for today only — tomorrow is a different catch */
  function saveCart() { store.set('mc_cart', { day: todayKey(), items: state.cart }); }

  function restoreCart() {
    const saved = store.get('mc_cart');
    if (!saved || saved.day !== todayKey()) return store.del('mc_cart');
    // Drop anything no longer on sale and refresh prices from today's list
    state.cart = saved.items
      .map((i) => { const p = state.products.find((x) => x.id === i.id); return p && p.stock_qty > 0 ? { ...i, price: p.price } : null; })
      .filter(Boolean);
  }

  /* -------------------------------------------------------------------
     8. CHECKOUT SHEET (open / close / delivery vs pickup / time slots)
     ------------------------------------------------------------------- */
  function openCheckout() {
    const wrap = $('#checkout'), panel = $('#checkoutPanel');
    wrap.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
      $('#checkoutBackdrop').style.opacity = '1';
      panel.classList.remove('translate-y-full', 'md:translate-x-full');
    });
  }

  function closeCheckout() {
    const panel = $('#checkoutPanel');
    $('#checkoutBackdrop').style.opacity = '0';
    panel.classList.add('translate-y-full', 'md:translate-x-full');
    document.body.style.overflow = '';
    setTimeout(() => $('#checkout').classList.add('hidden'), 300);
  }

  $('#cartBtn').addEventListener('click', openCheckout);
  $('#cartBarBtn').addEventListener('click', openCheckout);
  $('#closeCheckout').addEventListener('click', closeCheckout);
  $('#checkoutBackdrop').addEventListener('click', closeCheckout);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#checkout').classList.contains('hidden')) closeCheckout(); });

  function renderSlots() {
    const slots = CONFIG.SLOTS[state.fulfilment];
    if (!slots.includes(state.slot)) state.slot = null;
    $('#slotLegend').textContent = state.fulfilment === 'pickup' ? 'Pickup time' : 'Delivery time';
    $('#slotList').innerHTML = slots.map((s) => `
      <button type="button" data-slot="${esc(s)}"
              class="rounded-xl px-3 py-2.5 text-sm font-semibold ring-1 transition
                     ${s === state.slot ? 'bg-sea text-white ring-sea' : 'bg-white text-ink ring-ink/10 hover:ring-sea'}">
        ${esc(s)}
      </button>`).join('');
  }

  $('#slotList').addEventListener('click', (e) => {
    const b = e.target.closest('[data-slot]');
    if (!b) return;
    state.slot = b.dataset.slot;
    setError('slot', '');
    renderSlots();
  });

  $$('input[name="fulfilment"]').forEach((r) => r.addEventListener('change', () => {
    state.fulfilment = r.value;
    $('#addressWrap').classList.toggle('hidden', r.value === 'pickup');
    $('#sumDeliveryLabel').textContent = r.value === 'pickup' ? 'Pickup' : 'Delivery';
    renderSlots(); renderCart();
  }));

  /* -------------------------------------------------------------------
     9. FORM VALIDATION
     ------------------------------------------------------------------- */
  function setError(field, msg) {
    const el = $(`.field-error[data-for="${field}"]`);
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('hidden', !msg);
    const input = $(`#${field}`);
    if (input) input.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }

  /** Accepts "98765 43210", "+91 98765-43210", "098765..." → "9876543210" */
  function cleanPhone(raw) {
    let d = String(raw).replace(/\D/g, '');
    if (d.length === 12 && d.startsWith('91')) d = d.slice(2);
    if (d.length === 11 && d.startsWith('0')) d = d.slice(1);
    return d;
  }

  function validate() {
    const f = $('#checkoutForm');
    const data = {
      name: f.name.value.trim(),
      phone: cleanPhone(f.phone.value),
      email: f.email.value.trim(),
      address: f.address.value.trim(),
      notes: f.notes.value.trim(),
    };
    const errors = {};

    if (data.name.length < 2) errors.name = 'Please enter your name';
    if (!/^[6-9]\d{9}$/.test(data.phone)) errors.phone = 'Enter a valid 10-digit Indian mobile number';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(data.email)) errors.email = 'Enter a valid email so we can send your receipt';
    if (state.fulfilment === 'delivery' && data.address.length < 10) errors.address = 'Please add your full address with a landmark';
    if (!state.slot) errors.slot = 'Pick a time that suits you';

    ['name', 'phone', 'email', 'address', 'slot'].forEach((k) => setError(k, errors[k] || ''));

    const first = Object.keys(errors)[0];
    if (first) {
      const el = first === 'slot' ? $('#slotList') : $(`#${first}`);
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (el.focus && first !== 'slot') el.focus({ preventScroll: true });
      return null;
    }
    return data;
  }

  // Clear an error as soon as the user fixes the field
  ['name', 'phone', 'email', 'address'].forEach((id) => $(`#${id}`).addEventListener('input', () => setError(id, '')));
  // Digits only in the phone box
  $('#phone').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/[^\d ]/g, ''); });

  /* Remember customer details for tomorrow's order */
  function restoreCustomer() {
    const c = store.get('mc_customer');
    if (!c) return;
    const f = $('#checkoutForm');
    ['name', 'phone', 'email', 'address'].forEach((k) => { if (c[k]) f[k].value = c[k]; });
  }

  /* -------------------------------------------------------------------
     10. PAYMENT FLOW
     ------------------------------------------------------------------- */
  $('#checkoutForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!state.cart.length) return toast('Your basket is empty');
    if (!state.bookingOpen) return toast('Bookings are closed for today');

    const customer = validate();
    if (!customer) return;

    if ($('#remember').checked) store.set('mc_customer', { name: customer.name, phone: customer.phone, email: customer.email, address: customer.address });
    else store.del('mc_customer');

    // Only IDs, quantities and cuts go to the server — it works out the price.
    const payload = {
      customer: { ...customer, fulfilment: state.fulfilment, slot: state.slot },
      items: state.cart.map((i) => ({ product_id: i.id, qty: i.qty, cut: i.cut })),
    };

    if (state.demo) return demoPayment(payload);

    const payBtn = $('#payBtn');
    payBtn.disabled = true;
    showLoader('Reserving your fish…');

    try {
      // Step 1: server saves a pending order + creates the Razorpay order
      const order = await api('create_order.php', payload);
      hideLoader();

      if (typeof window.Razorpay !== 'function') throw new Error('Payment window could not load. Check your internet and try again.');

      // Step 2: open Razorpay overlay
      const rzp = new window.Razorpay({
        key: order.key_id,                         // public key, sent by the server
        amount: order.amount,                      // in paise
        currency: order.currency || 'INR',
        name: CONFIG.SHOP_NAME,
        description: `Order ${order.order_ref}`,
        order_id: order.razorpay_order_id,
        prefill: { name: customer.name, email: customer.email, contact: '+91' + customer.phone },
        notes: { order_ref: order.order_ref },
        theme: { color: CONFIG.THEME_COLOR },
        // Step 3: payment done → verify on server
        handler: (resp) => verifyPayment(resp),
        modal: {
          confirm_close: true,
          ondismiss: () => { payBtn.disabled = false; toast('Payment cancelled — your basket is saved'); },
        },
      });

      rzp.on('payment.failed', (r) => {
        payBtn.disabled = false;
        toast(r?.error?.description || 'Payment failed. Please try again.');
      });
      rzp.open();
    } catch (err) {
      hideLoader();
      payBtn.disabled = false;
      toast(err.message);
    }
  });

  async function verifyPayment(resp) {
    showLoader('Confirming your payment…');
    try {
      const result = await api('verify_payment.php', {
        razorpay_order_id: resp.razorpay_order_id,
        razorpay_payment_id: resp.razorpay_payment_id,
        razorpay_signature: resp.razorpay_signature,
      });
      hideLoader();
      showSuccess(result);
      // Refresh stock so the cards show what's left
      refreshStock();
    } catch (err) {
      hideLoader();
      $('#payBtn').disabled = false;
      // Money may have moved even if our server hiccuped — reassure the buyer
      toast('We received your payment but could not confirm it yet. Please WhatsApp us your payment ID: ' + resp.razorpay_payment_id);
      console.error(err);
    }
  }

  /** Preview mode: pretend to pay so the full flow can be seen */
  function demoPayment(payload) {
    showLoader('Simulating payment (preview)…');
    setTimeout(() => {
      hideLoader();
      const d = new Date();
      const ref = `MC-${String(d.getDate()).padStart(2, '0')}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getFullYear()).slice(2)}-${String(Math.floor(Math.random() * 9000) + 1000)}`;
      const { total } = totals();
      showSuccess({
        order_ref: ref,
        total_amount: total,
        slot: payload.customer.slot,
        fulfilment: payload.customer.fulfilment,
        items: state.cart.map((i) => ({ product_name: i.name, cut_type: i.cut, quantity: i.qty, unit: i.unit, line_total: round2(i.qty * (i.price + (i.fee || 0))) })),
        email_sent: true,
        whatsapp_sent: true,
        demo: true,
      });
    }, 1200);
  }

  /* -------------------------------------------------------------------
     11. SUCCESS SCREEN
     verify_payment.php should reply with:
       { success, order_ref, total_amount, slot, fulfilment,
         items:[{product_name, cut_type, quantity, unit, line_total}],
         email_sent, whatsapp_sent }
     ------------------------------------------------------------------- */
  function showSuccess(r) {
    closeCheckout();
    $('#successOrderId').textContent = r.order_ref;
    $('#successTotal').textContent = money(r.total_amount);
    $('#successItems').innerHTML = (r.items || []).map((i) => `
      <li class="flex justify-between gap-3">
        <span>${esc(i.product_name)} <span class="text-ink-soft">· ${esc(i.cut_type || '')} · ${qtyLabel(+i.quantity, i.unit)}</span></span>
        <span class="font-semibold tabular-nums">${money(i.line_total)}</span>
      </li>`).join('');
    $('#successSlot').textContent = r.slot ? `${r.fulfilment === 'pickup' ? 'Pickup' : 'Delivery'}: ${r.slot} today` : '';

    const channels = [r.whatsapp_sent && 'WhatsApp', r.email_sent && 'email'].filter(Boolean);
    $('#successNotify').textContent = r.demo
      ? 'Preview only: no real payment was taken and no WhatsApp or email was sent. Real receipts start once the site is live on Hostinger.'
      : channels.length
      ? `Receipt sent to your ${channels.join(' and ')}. Show the Order ID when you collect.`
      : 'Save your Order ID — show it when you collect. Your receipt will follow shortly.';

    $('#success').classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Empty the basket
    state.cart = [];
    saveCart();
    renderCart();
    renderProducts();
  }

  $('#successDone').addEventListener('click', () => {
    $('#success').classList.add('hidden');
    document.body.style.overflow = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  /* -------------------------------------------------------------------
     12. GO
     ------------------------------------------------------------------- */
  $('#year').textContent = new Date().getFullYear();
  loadProducts();
})();
