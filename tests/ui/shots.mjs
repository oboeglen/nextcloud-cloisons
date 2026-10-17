// Captures de l'écran d'administration (banc) : clair/sombre, plusieurs largeurs
import puppeteer from 'puppeteer'
import fs from 'node:fs'

const BASE = process.env.CLOISONS_BASE || 'http://127.0.0.1:18980'
const USER = process.env.UI_USER
const PASS = process.env.UI_PASS
const OUT = process.env.OUT || '/out'
const widths = (process.env.WIDTHS || '1440,390').split(',').map(Number)
const themes = (process.env.THEMES || 'light,dark').split(',')

const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] })
const page = await browser.newPage()
const errors = []
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()) })
await page.setViewport({ width: 1440, height: 900 })
await page.goto(BASE + '/index.php/login', { waitUntil: 'networkidle2' })
await page.type('#user', USER)
await page.type('#password', PASS)
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.keyboard.press('Enter')])

for (const theme of themes) {
	await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }])
	for (const w of widths) {
		await page.setViewport({ width: w, height: 900 })
		await page.goto(BASE + '/index.php/settings/admin/cloisons', { waitUntil: 'networkidle2' })
		await page.waitForSelector('#cloisons-admin .status, #cloisons-admin .empty-content', { timeout: 15000 }).catch(() => {})
		await new Promise((r) => setTimeout(r, 800))
		await page.screenshot({ path: `${OUT}/admin-${theme}-${w}.png`, fullPage: true })
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)
		if (overflow) errors.push(`débordement horizontal à ${w}px (${theme})`)
	}
}
fs.writeFileSync(`${OUT}/errors.json`, JSON.stringify(errors, null, 1))
console.log('erreurs:', errors.length, errors.slice(0, 5))
await browser.close()
