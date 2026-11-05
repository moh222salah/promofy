// Browser E2E: every registered admin module loads; product creation; consent rule on testimonials. See admin.e2e.cjs for setup.
const { chromium } = require('playwright')
;(async () => {
  const browser = await chromium.launch()
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } })
  const bad = []
  page.on('pageerror', (e) => bad.push('pageerror: ' + e.message))
  page.on('response', (r) => { if (r.url().includes('/api/v1/admin') && r.status() >= 400) bad.push(r.status() + ' ' + r.url()) })
  await page.goto('http://localhost:5173/admin/login')
  await page.fill('input[type=email]', 'admin@promofy.test'); await page.fill('input[type=password]', 'E2e-Pass-12345!'); await page.click('button[type=submit]')
  await page.waitForURL('**/admin')
  for (const m of ['services','service-categories','products','product-categories','portfolio','articles','article-categories','testimonials','team']) {
    await page.goto('http://localhost:5173/admin/' + m)
    await page.waitForSelector('h1'); await page.waitForTimeout(800)
    const rows = await page.locator('tbody tr').count(); const err = await page.locator('[role=alert]').count()
    console.log(err ? '✘' : '✔', m, 'rows=' + rows)
  }
  // create product through the UI
  await page.goto('http://localhost:5173/admin/products'); await page.click('text=جديد')
  await page.fill('#f-sku', 'E2E-1'); await page.fill('#f-name_ar', 'قالب اختبار'); await page.fill('#f-name_en', 'E2E Template'); await page.fill('#f-price', '150')
  await page.selectOption('#f-currency_code', 'EGP'); await page.check('text=تتبّع المخزون'); await page.fill('#f-stock_quantity', '5')
  await page.click('button:has-text("حفظ")'); await page.waitForSelector('text=تم الحفظ'); console.log('✔ product created (with stock)')
  await page.fill('input[type=search]', 'E2E-1'); await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1)
  console.log('  row:', (await page.locator('tbody tr').innerText()).replace(/\s+/g, ' '))
  // testimonial: cannot publish without consent -> server rule surfaces as error toast
  await page.goto('http://localhost:5173/admin/testimonials'); await page.click('text=جديد'); await page.fill('#f-author_name', 'Test Author'); await page.fill('#f-quote_en', 'Great'); await page.click('button:has-text("حفظ")'); await page.waitForSelector('text=تم الحفظ')
  await page.locator('tbody tr').first().locator('button[aria-label="نشر"]').click(); await page.waitForSelector('[role=alert]'); console.log('✔ publishing without consent is rejected:', await page.locator('[role=alert]').first().innerText())
  console.log(bad.length ? 'HTTP/JS problems:\n' + bad.join('\n') : 'no failed admin requests')
  await browser.close()
})().catch((e) => { console.error('FAIL', e.message); process.exit(1) })
