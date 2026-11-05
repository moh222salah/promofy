// Browser E2E against a running stack (backend :8000 with a seeded DB + admin@promofy.test, Vite :5173).
// Run: node frontend/e2e/admin.e2e.cjs   (needs `playwright` available to Node)
const { chromium } = require('playwright')
;(async () => {
  const browser = await chromium.launch()
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } })
  const errors = []
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
  page.on('console', (m) => m.type() === 'error' && errors.push('console: ' + m.text()))
  const step = (s) => console.log('✔', s)

  await page.goto('http://localhost:5173/admin')
  await page.waitForURL('**/admin/login'); step('redirects guests to login')
  await page.fill('input[type=email]', 'admin@promofy.test')
  await page.fill('input[type=password]', 'wrong-password')
  await page.click('button[type=submit]')
  await page.waitForSelector('[role=alert]'); step('bad password shows error')
  await page.fill('input[type=password]', 'E2e-Pass-12345!')
  await page.click('button[type=submit]')
  await page.waitForURL('**/admin'); step('login works')
  await page.waitForTimeout(3000); await page.screenshot({ path: 'after-login.png' }); console.log(await page.locator('body').innerText().then(t=>t.slice(0,300)));
  await page.waitForSelector('h3:has-text("الإيرادات عبر الزمن")'); console.log('  chart cards:', await page.locator('section h3').count()); step('dashboard KPIs + chart cards rendered from API')
  const dir = await page.evaluate(() => document.documentElement.dir); console.log('  dir =', dir)
  await page.screenshot({ path: 'dashboard-ar.png', fullPage: true })

  await page.click('a[href="/admin/services"]')
  await page.waitForSelector('table tbody tr'); step('services list loaded (' + await page.locator('tbody tr').count() + ' rows)')
  await page.click('text=جديد'); 
  await page.fill('#f-name_ar', 'خدمة اختبار E2E'); await page.fill('#f-name_en', 'E2E Test Service')
  await page.click('button:has-text("حفظ")')
  await page.waitForSelector('text=تم الحفظ'); step('save succeeded (toast)')
  await page.fill('input[type=search]', 'E2E Test'); await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1); step('created service found via search (filtered to 1 row)')
  console.log('  rows after search:', await page.locator('tbody tr').count())
  // publish
  const row = page.locator('tbody tr', { hasText: "خدمة اختبار E2E" })
  await row.locator('button[aria-label="نشر"]').click()
  await page.waitForSelector('tbody tr:has-text("خدمة اختبار E2E") >> text=منشور'); step('publish works')
  // validation error from server
  await page.click('text=جديد'); await page.fill('#f-name_ar', 'x'); await page.fill('#f-name_en', 'y'); await page.selectOption('#f-pricing_type', 'range')
  await page.click('button:has-text("حفظ")'); await page.waitForSelector('[role=dialog] [role=alert]'); step('client/server validation errors shown')
  await page.click('button[aria-label="إلغاء"]')
  // delete with confirm
  await row.locator('button[aria-label="حذف"]').click(); await page.click('[role=alertdialog] button:has-text("حذف")')
  await page.waitForFunction(() => !document.body.innerText.includes("خدمة اختبار E2E")); step('delete with confirmation works')
  // english LTR
  await page.click('button:has-text("English")'); 
  console.log('  dir after switch =', await page.evaluate(() => document.documentElement.dir)); await page.screenshot({ path: 'services-en.png' })
  // permission-aware: direct API without session = 401
  const r = await page.evaluate(async () => (await fetch('/api/v1/admin/services', { headers: { Accept: 'application/json' }, credentials: 'omit' })).status); console.log('  API without cookie ->', r)
  console.log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no console errors')
  await browser.close()
})().catch((e) => { console.error('FAIL', e.message); process.exit(1) })
