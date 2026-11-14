(() => {
  const script = document.currentScript;
  const root = new URL('../', script.src).pathname;
  const key = 'ekshan-demo-cart-v1';
  const price = 4499;
  const clamp = value => Math.min(99, Math.max(0, Math.floor(Number(value) || 0)));
  const read = () => { try { return clamp(localStorage.getItem(key)); } catch { return 0; } };
  const write = qty => { try { localStorage.setItem(key, String(clamp(qty))); } catch {} updateCount(); };
  const updateCount = () => {
    const qty = read();
    document.querySelectorAll('.cart-count').forEach(el => { el.textContent = String(qty); el.hidden = qty === 0; });
    document.querySelectorAll('.cart-link').forEach(el => el.setAttribute('aria-label', `View demo cart, ${qty} item${qty === 1 ? '' : 's'}`));
  };
  const money = value => '₹' + value.toLocaleString('en-IN');
  const isCart = location.pathname.replace(/index\.html$/, '').endsWith('/cart/');
  if (isCart) {
    const params = new URLSearchParams(location.search);
    if (params.get('add-to-cart') === '516') {
      write(read() + clamp(params.get('quantity') || 1));
      history.replaceState({}, '', root + 'cart/');
    }
    const item = document.getElementById('cart-item');
    const empty = document.getElementById('cart-empty');
    const input = document.getElementById('cart-quantity');
    const subtotal = document.getElementById('cart-subtotal');
    const total = document.getElementById('cart-total');
    const render = () => {
      const qty = read();
      item.hidden = qty === 0;
      empty.hidden = qty !== 0;
      input.value = String(qty || 1);
      subtotal.textContent = money(qty * price);
      total.textContent = money(qty * price);
      updateCount();
    };
    input.addEventListener('change', () => { write(Math.max(1, clamp(input.value))); render(); });
    document.getElementById('cart-remove').addEventListener('click', () => { write(0); render(); });
    render();
  } else {
    updateCount();
  }
})();
