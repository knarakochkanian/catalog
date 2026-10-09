import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = dirname(fileURLToPath(import.meta.url));
const env = Object.fromEntries(
  (await readFile(resolve(here, '../.env'), 'utf8'))
    .split(/\r?\n/)
    .filter(line => /^[A-Z_]+=/.test(line))
    .map(line => {
      const index = line.indexOf('=');
      return [line.slice(0, index), line.slice(index + 1)];
    }),
);
const username = process.env.ADMIN_USERNAME ?? env.ADMIN_USERNAME;
const password = process.env.ADMIN_PASSWORD ?? env.ADMIN_PASSWORD;
assert.ok(username && password, 'Admin credentials must be configured');
const baseURL = process.env.E2E_BASE_URL ?? env.APP_ORIGIN;
assert.ok(baseURL, 'Application URL must be configured');
const output = process.env.E2E_OUTPUT_DIR ?? resolve(here, '../backend/var/e2e');
const browser = await chromium.launch(
  process.env.E2E_CHROMIUM_EXECUTABLE
    ? { executablePath: process.env.E2E_CHROMIUM_EXECUTABLE }
    : {},
);
const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const browserErrors = [];
page.on('pageerror', error => browserErrors.push(error.message));
page.on('console', message => {
  if (message.type() === 'error') browserErrors.push(message.text());
});

function productsResponse(query = {}) {
  const expected = { page: '1', limit: '20', name: '', min_price: '', max_price: '', ...query };
  return page.waitForResponse(response => {
    const url = new URL(response.url());
    return response.request().method() === 'GET'
      && url.pathname === '/api/products'
      && Object.entries(expected).every(([key, value]) => (url.searchParams.get(key) ?? '') === value);
  });
}

async function settledProducts(responsePromise) {
  const response = await responsePromise;
  assert.equal(response.status(), 200, 'Product query must succeed');
  assert.equal(await response.finished(), null, 'Product response must finish successfully');
  const result = await response.json();
  assert.ok(Array.isArray(result.items), 'Product response must contain a product list');
  await page.waitForFunction(({ names, total }) => {
    const panel = document.querySelector('.catalog-panel');
    const badge = document.querySelector('.count-badge');
    const renderedNames = Array.from(document.querySelectorAll('.product-table tbody .product-title > span:last-child'))
      .map(element => element.textContent.trim());
    const emptyState = document.querySelector('.catalog-panel .state-box h3')?.textContent.trim();
    return panel?.getAttribute('aria-busy') === 'false'
      && !panel.querySelector('.loading-bar')
      && Number(badge?.textContent.replace(/\D/g, '')) === total
      && JSON.stringify(renderedNames) === JSON.stringify(names)
      && (names.length > 0 || emptyState === 'Товары не найдены');
  }, { names: result.items.map(product => product.name), total: result.total });
  assert.equal(await page.locator('.product-table tbody tr').count(), result.items.length);
  return result;
}

async function search(name, minPrice = '', maxPrice = '') {
  const response = productsResponse({ name: name.trim(), min_price: minPrice, max_price: maxPrice });
  await page.getByLabel('Поиск по названию', { exact: true }).fill(name);
  await page.getByLabel('Цена от', { exact: true }).fill(minPrice);
  await page.getByLabel('Цена до', { exact: true }).fill(maxPrice);
  return settledProducts(response);
}

function ids(products) {
  return products.map(product => product.id).sort((a, b) => a - b);
}

try {
  await mkdir(output, { recursive: true });
  await page.goto('/products');
  await page.getByLabel('Имя пользователя', { exact: true }).fill(username);
  await page.getByLabel('Пароль', { exact: true }).fill(password);
  const initialResponse = productsResponse();
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.waitForURL('**/products');
  const initial = await settledProducts(initialResponse);
  assert.ok(initial.total >= 2, 'Regression check requires the existing imported catalog');

  const matched = await search('бермуды omsa');
  assert.equal(matched.total, 2, 'Lowercase multiword search must find both imported OMSA bermudas');
  assert.equal(matched.items.length, 2);
  for (const product of matched.items) {
    assert.match(product.name, /^Бермуды мужские,.*OMSA/i);
    assert.ok(!product.name.toLowerCase().includes('бермуды omsa'), 'Terms are separated in the imported product name');
  }
  const matchedIds = ids(matched.items);
  for (const query of ['БеРмУдЫ OmSa', 'omsa бермуды', '  бермуды   omsa  ']) {
    const result = await search(query);
    assert.equal(result.total, 2, 'Case, order, and extra whitespace must preserve both matches');
    assert.deepEqual(ids(result.items), matchedIds);
  }

  const absent = await search('бермуды omsa отсутствующийтерм');
  assert.equal(absent.total, 0, 'Every search term must match the product name');
  assert.deepEqual(absent.items, []);
  await page.getByRole('heading', { name: 'Товары не найдены', exact: true }).waitFor();

  const prices = matched.items.map(product => Math.round(Number(product.price) * 100));
  assert.ok(prices.every(price => Number.isFinite(price) && price >= 0), 'Matched product prices must be valid');
  const lowest = Math.min(...prices);
  const highest = Math.max(...prices);
  const exactPrice = (lowest / 100).toFixed(2);
  const priceMatched = await search('бермуды omsa', exactPrice, exactPrice);
  const expectedAtPrice = matched.items.filter((_, index) => prices[index] === lowest);
  assert.equal(priceMatched.total, expectedAtPrice.length, 'Exact price bounds must combine with all search terms');
  assert.deepEqual(ids(priceMatched.items), ids(expectedAtPrice));
  const excludedPrice = ((highest + 1) / 100).toFixed(2);
  const excluded = await search('бермуды omsa', excludedPrice, excludedPrice);
  assert.equal(excluded.total, 0, 'A price outside both matches must exclude them');
  assert.deepEqual(excluded.items, []);

  const preview = await search('бермуды omsa');
  assert.equal(preview.total, 2);
  assert.deepEqual(ids(preview.items), matchedIds);
  assert.equal(await page.getByLabel('Поиск по названию', { exact: true }).inputValue(), 'бермуды omsa');
  assert.ok(await page.getByLabel('Поиск по названию', { exact: true }).isVisible());
  await page.evaluate(() => document.fonts.ready);
  await page.waitForFunction(() => Array.from(document.querySelectorAll('.product-table img')).every(image => image.complete));
  await page.screenshot({ path: resolve(output, 'search-preview.png'), fullPage: true });
  assert.deepEqual(browserErrors, [], 'No browser errors');
  console.log('Search UI passed: existing catalog, two OMSA bermudas across case/order/whitespace variants, all terms required, combined price bounds, desktop preview; no browser errors.');
} finally {
  await browser.close();
}
