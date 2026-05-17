# Changelog

## [4.0.0] — 2024-01-01

### Added
- Suppression list (`ck_suppression_list`) — cross-channel, per-channel or global, auto-suppress hard bounces, bulk import endpoint
- API key management (`ck_api_keys`) — multiple keys, resource-level permission scopes, last-used tracking, auto-expiry
- Outbound webhook subscriptions (`ck_webhook_subscriptions`) — 19 events, HMAC-SHA256 signed, bearer/basic/header auth, auto-retry, attempt logging, auto-disable after 10 failures
- Email template library (`ck_email_templates`) — 6 built-in presets (welcome, newsletter, promotion, abandoned cart, win-back, order confirmation), custom templates, usage tracking
- Campaign approval workflow — submit → approve/reject flow, approval notes
- Campaign tags & category — `tags` (JSON), `category` string, filter scopes
- Campaign per-minute rate limit override (`rate_limit_per_minute` column)
- `Campaign::body_hash` auto-populated on every save via model `booted()` hook
- `CampaignService::isDuplicate()` — prevents resending identical body to same contact within 7 days
- Campaign archive system (`reachhub:archive-campaigns` + `ck_campaign_logs_archive`)
- `reachhub:retry-failed` — retries failed messages with exponential backoff (5/10/20 min)
- CSV import preview with auto column mapping (`POST /contacts/import/preview`)
- Calendar view (`GET /campaigns/calendar`)
- Contact activity timeline (`GET /contacts/{id}/timeline`)
- Multi-key auth mode (`REACHHUB_AUTH_MODE=api_keys`) with per-route permission enforcement
- `suggest` block in `composer.json` for optional dependencies

### Fixed
- Auth middleware rewritten to cleanly support both `single_token` and `api_keys` modes
- `CampaignService::sendToContact()` now checks suppression list before every send
- Webhook events fired at key lifecycle points (campaign sent, completed, paused, contact suppressed)

## [3.0.0] — 2024-01-01

### Added
- AI engine (`ContentEngine`) — Ollama local LLM + OpenAI fallback
- Send-time optimiser (`SendTimeOptimizer`)
- GDPR privacy service — AES-256-GCM encryption, right to erasure, consent tracking, data export
- Workflow engine — 11 step types, conditional branching, drip sequences
- Migration wizard — Mailchimp API, CSV, JSON
- Campaign preview, pre-send validation, test sends
- `reachhub:workflow-tick` command
- `reachhub:gdpr-cleanup` command

## [2.0.0] — 2024-01-01

### Added
- Scheduled campaign dispatcher (`reachhub:dispatch-scheduled`)
- Inbound webhook handlers (Email/WhatsApp/SMS delivery tracking)
- SMS GSM-7 / UCS-2 encoding detection + concatenated SMS support
- Channel rate limiter (`ChannelRateLimiter`) with per-channel config
- Campaign pause/resume
- Global contact unsubscribe / suppression
- Chunk failure bug fix — single chunk failure no longer fails entire campaign
- Exponential backoff on job retries (1m → 5m → 15m → 30m → 60m)
- `getRecipientIds()` replaces double `getRecipients()` call (performance fix)
- Cache-based completion tracking avoids re-querying all recipients

## [1.0.0] — 2024-01-01

### Initial release
- Multi-channel campaigns: Email, WhatsApp (Meta Cloud API), SMS (Twilio/Vonage), Push (FCM)
- Contact & contact list management
- Bulk contact import
- Campaign CRUD, schedule, cancel, duplicate
- Analytics: open rate, click rate, failure rate
- Bearer token auth
- Chunked queue delivery
- Configurable batch sizes per channel
