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

**Important: Change the default PIN immediately.**

1. Log in with `Default user` / PIN `1234`
2. Go to **Settings → Manage Users**
3. Create a new user with a name and PIN of your choice, then save
4. Log out (top-right name → Switch User) and log in as your new user
5. Go back to **Settings → Manage Users** and delete `Default user`

That's it — you now have your own account. The default user is just a starting key.

## Database Reset

To wipe all data and restore the factory default user:

```bash
php reset-db.php
```

This is a **CLI-only** script — it refuses to run via the web. It clears inventory, ledger, products, rate limits, and users, then recreates `Default user` / PIN `1234`.

## Highly Recommended: Cloudflare Turnstile (CAPTCHA)

PIN auth is intentionally kept simple — no email, no password managers. That also means a 4-digit PIN is not strong on its own. Adding Turnstile blocks automated brute-force attempts, which is the real threat.

It's **free** for any amount of traffic. Takes 2 minutes:

1. Go to [dash.cloudflare.com](https://dash.cloudflare.com) → **Turnstile** → **Add Site**
2. Enter your domain → **Widget type: Non-interactive** → **Create**
3. Copy the **Site Key** and **Secret Key**
4. In **Settings** → **Danger Zone**, paste both keys and save

If you skip it, the CAPTCHA simply doesn't appear — the app works fine either way. But for any public-facing install, it's strongly advised.

## Tech Stack

- PHP (single-file backend with SQLite)
- ZBar WASM (client-side barcode decoding)
- Open Food Facts API (product lookup)
- Cloudflare Turnstile (optional captcha)

## License

MIT
