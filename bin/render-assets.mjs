// Render brand SVGs to the raster PNGs WordPress.org needs, using headless
// Chromium so the bundled Sora face is loaded via @font-face and the wordmark
// rasterizes correctly (WP.org banners must be PNG/JPG; SVG isn't accepted).
//
// Usage: node bin/render-assets.mjs
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const img = resolve(root, 'assets/img');
const out = resolve(root, '.wordpress-org');
const fontUrl = 'file://' + resolve(img, '../fonts/sora-latin.woff2');
mkdirSync(out, { recursive: true });

// [source svg, output png, width, height]
const jobs = [
	['icon.svg', 'icon-256x256.png', 256, 256],
	['icon.svg', 'icon-128x128.png', 128, 128],
	['banner.svg', 'banner-1544x500.png', 1544, 500],
	['banner.svg', 'banner-772x250.png', 772, 250],
];

// Prefer a Playwright-managed Chromium; fall back to the system Chrome install
// so this runs without downloading a browser.
let browser;
try {
	browser = await chromium.launch();
} catch {
	browser = await chromium.launch({ channel: 'chrome' });
}
const page = await browser.newPage();

for (const [src, dest, w, h] of jobs) {
	let svg = readFileSync(resolve(img, src), 'utf8');
	// Force the SVG to the exact pixel box; viewBox handles the scaling.
	svg = svg.replace(/<svg([^>]*)>/, (m, attrs) => {
		const cleaned = attrs
			.replace(/\swidth="[^"]*"/, '')
			.replace(/\sheight="[^"]*"/, '');
		return `<svg width="${w}" height="${h}"${cleaned}>`;
	});
	const html = `<!DOCTYPE html><html><head><meta charset="utf-8">
	<style>
	@font-face{font-family:"Tagbridge Sora";font-weight:600 700;font-display:block;src:url("${fontUrl}") format("woff2");}
	html,body{margin:0;padding:0;}
	</style></head><body>${svg}</body></html>`;
	await page.setViewportSize({ width: w, height: h });
	await page.setContent(html, { waitUntil: 'networkidle' });
	await page.evaluate(() => document.fonts.ready);
	const el = await page.$('svg');
	await el.screenshot({ path: resolve(out, dest) });
	console.log('wrote', dest, `${w}x${h}`);
}

await browser.close();
