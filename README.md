# FridgeStare

A lightweight, self-contained grocery inventory and meal planning app. Scan UPC barcodes with your phone camera, look up product info, track your stock, and get randomized meal suggestions from what you have on hand.

## Features

- **Barcode scanning** — Snap a photo of any UPC barcode; client-side ZBar WASM decodes it instantly
- **Product lookup** — Auto-fetches name, brand, and category from Open Food Facts (with local cache)
- **Manual entry** — Add items without barcodes (produce, bulk, etc.) with internal IDs
- **Inventory management** — Live +/- quantity buttons, search/filter, tag-based organization
- **Meal planning** — "What's for Dinner?" picks randomized meal combos from your tagged inventory
- **Multi-user** — PIN-based login for each family member with rate limiting and session persistence
- **Audit ledger** — Every add, take, and admin action is logged with timestamps and usernames
- **Configurable** — Timezone, session timeout, rate limits, Cloudflare Turnstile captcha, API keys
- **Mobile-first** — Dark theme, touch-friendly UI, native camera integration

## Quick Start

### Option 1: Drop files on any PHP host

```bash
# Upload all files to your web root
scp -r . user@host:/var/www/fridgestare/
# App auto-creates default user "default user" / PIN 1234 on first visit
```

Requirements: PHP 7.4+ with `php-sqlite3`, Apache with `mod_rewrite`.

### Option 2: Docker

```bash
docker-compose up -d
# App available at http://localhost:8080
```

### Option 3: Deploy script

```bash
ssh user@host "cd /var/www/fridgestare && bash deploy.sh"
```

## First Run

1. Log in with the default user: PIN `1234`
2. Visit **Users** (via Settings → Manage Users) to add family members and change your PIN
3. Head to the **Scanner** to start adding items

## Tech Stack

- PHP (single-file backend with SQLite)
- ZBar WASM (client-side barcode decoding)
- Open Food Facts API (product lookup)
- Cloudflare Turnstile (optional captcha)

## License

MIT
