# FridgeStare

<p align="center">
  <img src="apple-touch-icon.png" alt="FridgeStare" width="128">
</p>

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

## Requirements

- PHP 7.4+ with `php-sqlite3` extension
- Apache with `mod_rewrite` (or nginx with equivalent rewrite rules)

## Deployment

### Option 1: Drop files on any PHP host (simplest)

```bash
# Upload all files to your web root
scp -r . user@host:/var/www/fridgestare/

# Copy the example config and edit it
cp config.example.php config.php
# Edit config.php with your settings (timezone, API keys, etc.)

# On first visit, the app auto-creates "Default user" / PIN 1234
```

The app is self-contained — no database server, no package manager, no build step.

### Option 2: Run the deploy script

The deploy script checks that PHP, SQLite, and Apache mod_rewrite are available, then sets file permissions:

```bash
ssh user@host "cd /var/www/fridgestare && bash deploy.sh"
```

Run this after uploading files. It doesn't overwrite `config.php` or `fridgestare.db`.

### Option 3: Docker

```bash
docker-compose up -d
# App available at http://localhost:8420
```

The Docker image:
- Uses PHP 8.2 Apache with SQLite and zbarimg pre-installed
- Strips any local API keys from `config.php` and writes clean defaults
- Stores the SQLite database in a named Docker volume (survives restarts)
- Exposes port 8420

To stop: `docker-compose down`

### Option 4: Self-contained ZIP

No build step, no package manager. Just download the ZIP from GitHub Releases, unzip, and deploy.

## Configuration

Copy `config.example.php` to `config.php` and edit:

| Setting | Description |
|---------|-------------|
| `timezone` | Display timezone for ledger timestamps (e.g. `America/New_York`) |
| `session_timeout_days` | How long a PIN login lasts (default 30) |
| `pin_max_attempts` | Failed attempts before lockout (default 5) |
| `pin_lockout_hours` | Lockout duration after max attempts (default 1) |
| `default_qty` | Starting quantity for manual adds (default 1) |
| `debug` | Set to `true` to show scanner debug overlay |
| `upcitemdb_key` | Optional API key for UPCItemDB product lookup |
| `turnstile_site_key` | Cloudflare Turnstile site key (free) |
| `turnstile_secret_key` | Cloudflare Turnstile secret key |

**Note:** `config.php` is excluded from version control. The repo only tracks `config.example.php` (with empty keys) so you never accidentally commit live API keys.

## First Run

**Important: Change the default PIN immediately.**

1. Log in with `Default user` / PIN `1234`
2. Go to **Settings → Users**
3. Create a new user with a name and PIN of your choice
4. Log out (tap your name in the top bar → Switch User) and log in as your new user
5. Go back to **Settings → Users** and delete `Default user`

That's it. The default user is just a starting key — not meant for daily use.

## Database Reset

To wipe all data and restore the factory default user:

```bash
php reset-db.php
```

This is a **CLI-only** script — it refuses to run via the web. It clears inventory, ledger, products, rate limits, sessions, and users, then recreates `Default user` / PIN `1234`.

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
