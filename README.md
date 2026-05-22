# FridgeStare  v1.01

<p align="center">
  <img src="apple-touch-icon.png" alt="FridgeStare" width="128">
</p>

## Because 6pm comes every single day.

There are a hundred of these apps. I built my own because I wanted exactly what I wanted — scan a UPC as you unpack groceries, tag it as a protein, side, or dessert, and when dinner comes around the planner gives you options based on what's actually in your kitchen. Because we've all stood in front of the fridge at 6pm, staring, with absolutely no plan, even though you just went shopping.

No accounts, no huge dependencies, no bloat.

The scanning system is solid enough that I'm pulling it out for other projects. The rest is just a thing that works for my house. YMMV.

A lightweight, self-contained grocery inventory and meal planning app. Scan UPC barcodes with your phone camera, look up product info, track your stock, and get randomized meal suggestions from what you have on hand.

## Design Philosophy

Everything in this app is intentionally simple — sometimes to the point where it might look like a corner was cut. To the contrary...

- **PINs are 4–8 digits, no email, no passwords.** That's the point. You type 4 numbers and you're in. The only real threat is brute-force, which Turnstile and rate limiting handle.
- **No permissions, no roles, no admin accounts.** Every user is the same. The ledger tracks who did what, but nobody has special powers. If you can log in, you can use everything.
- **No undo, no confirmation dialogs for adds/takes.** ADD adds one, TAKE removes one. If you took one too many, just ADD it back. Over-engineering undo is the opposite of simple.
- **Manual entries create internal barcodes, not names.** If you type "Milk" twice, you get two rows. That's not a bug — it means one might be whole and the other skim. But if you meant the same item, the autocomplete is right there.
- **Weak PINs like 1234 are rejected, but you can still set 9284.** The goal isn't bank-grade security, it's keeping the neighbor's kid from messing with your inventory.
- **Users can change anyone's PIN.** Again — no roles. If you're logged in, you can manage users. It's a family tool, not an enterprise.

The rule: if making it more complex doesn't make it noticeably better for a family kitchen, it stays simple.

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
- **Self-upgrading** — `php upgrade.php` checks GitHub, backs up, and applies new releases

## Requirements

- PHP 7.4+ with `php-sqlite3` extension
- Apache with `mod_rewrite` (or nginx with equivalent rewrite rules)
- `php-zip` extension (only needed for the upgrade script — the app itself doesn't require it)

## Deployment

### Option 1: Manual (any PHP host)

```bash
# Upload files to your web root (scp, FTP, rsync, or unzip a release)
scp -r . user@host:/var/www/fridgestare/

# Copy the example config and edit it
cp config.example.php config.php
# Edit config.php with your settings (timezone, API keys, etc.)

# On first visit, the app auto-creates "Default user" / PIN 1234
```

Requirements: PHP 7.4+ with `php-sqlite3`, Apache with `mod_rewrite`.

### Upgrading

Existing users don't need to download full releases. `upgrade.php` is attached to every release on GitHub — grab just that one file:

```bash
# 1. Make sure php-zip is installed (only needed for upgrades)
#    Debian/Ubuntu: sudo apt install php-zip
#    RHEL/Fedora:   sudo dnf install php-zip

# 2. Download upgrade.php from the latest release and put it in your FridgeStare directory:
#    https://github.com/gmwnet/fridgestare/releases/latest

# 3. Run it — it backs up, downloads the latest release, and upgrades everything
php upgrade.php
```

The script creates a full-site zip snapshot in `_version_backups/` before making any changes. All user data (`config.php`, `fridgestare.db`) is preserved.

### Option 2: Docker

```bash
docker-compose up -d
# App available at http://localhost:8420
```

The Docker image uses PHP 8.2 Apache with SQLite, zbarimg, and `php-zip` pre-installed. It strips any local API keys and writes clean defaults.

To upgrade, either rebuild the image or run the upgrade script inside the container:

```bash
# Option A: Rebuild image
docker-compose build && docker-compose up -d

# Option B: Upgrade in-place (preserves snapshot history)
docker exec -it <container> php /var/www/html/upgrade.php
```

> **Note:** Your database (`fridgestare.db`) lives inside the container by default and will be lost on container rebuild unless you mount a volume. To persist data across rebuilds, add a volume to `docker-compose.yml`:
> ```yaml
> volumes:
>   - fridgestare_data:/var/www/html/fridgestare.db
> ```
> Or mount the host directory directly:
> ```yaml
> volumes:
>   - ./data:/var/www/html
> ```

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

## ModSecurity / OWASP CRS

If you run this app behind ModSecurity with OWASP CRS, you may need to exclude a few rules for the app's path:

```apache
# Content-Type on POST requests with body
SecRuleRemoveById 920340 920640
# Generic header-name policy  
SecRuleRemoveById 920450
```

The app sends `Content-Type: application/json` on every POST with a body, but ModSecurity may still flag requests depending on your paranoia level and proxy configuration. The above exclusions are safe — the app doesn't accept file uploads or form data on authenticated endpoints.

## License

MIT

## Statement on AI use

AI was used to generate portions of the code and for security reviews and bug chase/fixing.  The architecture and scaffolding was done by a real human (me!)
