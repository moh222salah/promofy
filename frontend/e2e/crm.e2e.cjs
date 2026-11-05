// Browser E2E: public project request -> Kanban drag/drop -> lead detail (timeline, follow-up) -> convert to customer.
const { chromium, request } = require('playwright')
;(async () => {
  const api = await request.newContext({ baseURL: 'http://localhost:8000', extraHTTPHeaders: { Accept: 'application/json' } })
  const r = await api.post('/api/v1/public/project-requests', { data: { name: 'Omar Hassan', company: 'Nile Foods', email: 'omar@nile.example', phone: '+966500000000', country: 'SA', description: 'نحتاج حملة إعلانية على ميتا', consent: true, attribution: { utm_source: 'instagram', utm_campaign: 'ramadan' } } })
  console.log(r.status() === 201 ? '✔ lead created via public Start-a-Project form' : 'FAIL form ' + r.status() + await r.text())
  const browser = await chromium.launch(); const page = await browser.newPage({ viewport: { width: 1500, height: 900 } })
  const bad = []; page.on('pageerror', (e) => bad.push(e.message)); page.on('response', (x) => { if (x.url().includes('/api/v1/admin') && x.status() >= 400) bad.push(x.status() + ' ' + x.url().replace(/.*v1/, '')) })
  await page.goto('http://localhost:5173/admin/login'); await page.fill('input[type=email]', 'admin@promofy.test'); await page.fill('input[type=password]', 'E2e-Pass-12345!'); await page.click('button[type=submit]'); await page.waitForURL('**/admin')
  await page.click('a[href="/admin/leads"]'); await page.waitForSelector('article'); console.log('✔ Kanban board shows the lead in column:', await page.locator('[role=listitem]:has(article) >> h3').first().innerText())
  // drag to "Contacted"
  await page.locator('article').first().dragTo(page.locator('[role=listitem][aria-label="تم التواصل"]'))
  await page.waitForSelector('[role=listitem][aria-label="تم التواصل"] article'); console.log('✔ drag & drop moved the card to "تم التواصل" (persisted via API)')
  await page.screenshot({ path: 'board.png' })
  // keyboard-accessible move
  await page.locator('article select').selectOption('qualified'); await page.waitForSelector('[role=listitem][aria-label="مؤهَّل"] article'); console.log('✔ select-based move works')
  // table view
  await page.click('button[role=tab]:has-text("جدول")'); await page.waitForSelector('tbody tr'); console.log('✔ table view rows:', await page.locator('tbody tr').count())
  await page.click('tbody tr a'); await page.waitForSelector('h1:has-text("Omar Hassan")'); console.log('✔ lead detail; attribution shown:', await page.locator('text=utm_source=instagram').count() > 0)
  await page.selectOption('select[aria-label="النوع"]', 'call'); await page.fill('input[aria-label^="سجّل"]', 'اتصال أولي: مهتم بالحملة'); await page.click('button:has-text("إضافة")'); await page.waitForSelector('li:has-text("اتصال أولي")'); console.log('✔ call logged to timeline')
  const d = new Date(Date.now() + 2 * 864e5).toISOString().slice(0, 16)
  await page.fill('input[type=datetime-local]', d); await page.click('button:has-text("جدولة متابعة")'); await page.waitForSelector('li:has-text("متابعة")'); console.log('✔ follow-up scheduled (timeline entry created)')
  await page.click('button:has-text("تحويل إلى عميل")'); await page.waitForSelector('span:has-text("عميل")'); console.log('✔ converted to customer')
  await page.goto('http://localhost:5173/admin/customers'); await page.waitForSelector('tbody tr'); console.log('✔ customer now listed:', (await page.locator('tbody tr').first().innerText()).replace(/\s+/g, ' ').slice(0, 60))
  console.log(bad.length ? 'PROBLEMS:\n' + bad.join('\n') : 'no failed admin requests / js errors')
  await browser.close()
})().catch((e) => { console.error('FAIL', e.message.split('\n').slice(0, 3).join(' | ')); process.exit(1) })
