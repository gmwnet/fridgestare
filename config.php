<?php
return [
    // --- External API Keys ---
    'upcitemdb_key' => '',

    // Cloudflare Turnstile — https://dash.cloudflare.com/ → Turnstile
    // Leave blank to disable captcha entirely
    'turnstile_site_key' => '0x4AAAAAADSo1Ej-yaGGYQ1C',
    'turnstile_secret_key' => '0x4AAAAAADSo1OzMcRiKS1_n7B-GeaOQ2Y4',

    // --- App Settings ---

    // Server timezone for timestamps (ledger, inventory updated_at, etc.)
    // Common values: 'UTC', 'America/New_York', 'America/Chicago', 'America/Denver',
    // 'America/Los_Angeles', 'Europe/London', 'Europe/Paris', 'Asia/Tokyo', 'Australia/Sydney'
    // Full list: https://www.php.net/manual/en/timezones.php
    'timezone' => 'UTC',

    // PIN session length in days (how long the browser remembers the login)
    'session_timeout_days' => 30,

    // How many bad PIN attempts before IP lockout
    'pin_max_attempts' => 3,

    // How long the IP lockout lasts (hours)
    'pin_lockout_hours' => 1,

    // Default quantity for manual add without barcode
    'default_qty' => 1,

    // --- Emergency ---

    // Set to true to clear all PIN lockouts. Hit /api/emergency-unlock after changing this,
    // then set back to false. Prevents the whole family from being locked out permanently.
    'emergency_unlock' => false,

    // --- Developer ---

    // Show scanner debug overlay (frame info, decoder status)
    'debug' => false,
];
