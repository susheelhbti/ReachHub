# 📣 ReachHub v4 — Laravel Multi-Channel Marketing Automation

> The only open-source marketing automation package that gives you AI-powered content generation, multi-channel campaigns, GDPR compliance, and enterprise webhooks — completely free. Self-hosted. Zero per-contact pricing. Ever.

---

## ✨ Feature Overview

| Category | Features |
|---|---|
| 📡 **Channels** | Email (Laravel Mail), WhatsApp (Meta Cloud API), SMS (Twilio / Vonage), Push (Firebase FCM) |
| 🤖 **AI** | Subject line generation, channel adaptation, spam analysis, send-time optimisation — local Ollama (free) or OpenAI |
| 🔄 **Workflows** | Multi-step drip sequences, conditional branching, delays, tag/list actions, outbound webhooks |
| 🔐 **Privacy / GDPR** | AES-256-GCM field encryption, right to erasure, consent tracking with cryptographic proof, data export |
| 🚫 **Suppression** | Global per-channel suppression list, auto-suppress hard bounces, bulk import |
| 🔑 **API Auth** | Single token (simple) or multi-key with resource-level permission scopes |
| 📦 **Templates** | Email template library with 6 built-in presets + custom templates |
| ✅ **QA Tools** | Campaign preview, pre-send validation (spam, broken links, length), test sends |
| 🔌 **Webhooks (outbound)** | 19 events, HMAC-signed, bearer/basic/header auth, auto-retry, attempt logging |
| 📥 **Import / Migration** | Mailchimp API, CSV (with column-mapping preview), JSON bulk import |
| 📊 **Analytics** | Per-campaign stats, open/click/failure rates, contact timeline, calendar view |
| ⚙️ **Background Jobs** | Chunked queue delivery, exponential backoff retries, campaign archiving, GDPR cleanup |

---

## 🚀 Installation

### 1. Install the package

```bash
composer require susheelhbti/reachhub
```

Optional extras:
```bash
composer require league/csv          # advanced CSV import preview
```

### 2. Publish config & migrate

```bash
php artisan vendor:publish --tag=reachhub-config
php artisan migrate
```

### 3. Configure `.env`

```env
# ── Auth ───────────────────────────────────────────────
REACHHUB_API_TOKEN=your-secret-token   # simple mode
# REACHHUB_AUTH_MODE=api_keys          # switch to multi-key mode

# ── Queue ──────────────────────────────────────────────
REACHHUB_QUEUE=campaigns
REACHHUB_QUEUE_CONNECTION=redis

# ── AI (pick one) ──────────────────────────────────────
REACHHUB_AI_DRIVER=ollama              # local, free, private
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=mistral

# REACHHUB_AI_DRIVER=openai
# OPENAI_API_KEY=sk-...

# ── Email ───────────────────────────────────────────────
REACHHUB_EMAIL_ENABLED=true
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="My App"

# ── WhatsApp (Meta Cloud API) ───────────────────────────
REACHHUB_WHATSAPP_ENABLED=true
WHATSAPP_PHONE_NUMBER_ID=xxx
WHATSAPP_ACCESS_TOKEN=xxx
WHATSAPP_WEBHOOK_VERIFY_TOKEN=my-verify-secret

# ── SMS ─────────────────────────────────────────────────
REACHHUB_SMS_ENABLED=true
REACHHUB_SMS_PROVIDER=twilio           # or vonage
TWILIO_ACCOUNT_SID=ACxxxxxxxx
TWILIO_AUTH_TOKEN=xxxxxxxx
TWILIO_FROM_NUMBER=+1xxxxxxxxxx

# ── Push (FCM) ──────────────────────────────────────────
REACHHUB_PUSH_ENABLED=true
FCM_SERVER_KEY=xxx

# ── Privacy ─────────────────────────────────────────────
REACHHUB_GDPR_YEARS=2                  # auto-erase after N years inactive
REACHHUB_ENCRYPT_PII=false             # set true to encrypt email/phone at rest
```

### 4. Register the scheduler (required for automation)

**Laravel 11+** — `routes/console.php`:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reachhub:dispatch-scheduled')->everyMinute();
Schedule::command('reachhub:workflow-tick')->everyMinute();
Schedule::command('reachhub:retry-failed')->everyFiveMinutes();
Schedule::command('reachhub:gdpr-cleanup')->daily();
Schedule::command('reachhub:archive-campaigns --days=90')->weekly();
```

**Laravel 10** — `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('reachhub:dispatch-scheduled')->everyMinute();
    $schedule->command('reachhub:workflow-tick')->everyMinute();
    $schedule->command('reachhub:retry-failed')->everyFiveMinutes();
    $schedule->command('reachhub:gdpr-cleanup')->daily();
    $schedule->command('reachhub:archive-campaigns')->weekly();
}
```

Then enable the cron:
```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

---

## 📡 API Reference

All endpoints require:
```
Authorization: Bearer <token>
Content-Type: application/json
```

Base URL: `https://your-app.com/api/reachhub`

---

### 👥 Contacts

| Method | Endpoint | Description |
|---|---|---|
| GET | `/contacts` | List contacts (`?search=&subscribed=`) |
| POST | `/contacts` | Create contact |
| GET | `/contacts/{id}` | Get contact |
| PUT | `/contacts/{id}` | Update contact |
| DELETE | `/contacts/{id}` | Delete contact |
| POST | `/contacts/import` | Bulk import (up to 5 000) |
| POST | `/contacts/import/preview` | Preview CSV + auto-map columns |
| POST | `/contacts/import/validate-mapping` | Validate mapping before import |
| POST | `/contacts/{id}/unsubscribe` | Unsubscribe (adds to suppression) |
| GET | `/contacts/{id}/timeline` | Full activity timeline |

**Create contact**
```json
{
  "name": "Ravi Kumar",
  "email": "ravi@example.com",
  "phone": "+919876543210",
  "whatsapp": "+919876543210",
  "fcm_token": "firebase-token",
  "tags": ["vip", "india"],
  "custom_fields": { "city": "Gorakhpur" }
}
```

---

### 📋 Contact Lists

| Method | Endpoint | Description |
|---|---|---|
| GET/POST | `/lists` | CRUD |
| GET/PUT/DELETE | `/lists/{id}` | Single list |
| POST | `/lists/{id}/contacts/attach` | Attach contacts |
| POST | `/lists/{id}/contacts/detach` | Detach contacts |

---

### 📣 Campaigns

| Method | Endpoint | Description |
|---|---|---|
| GET | `/campaigns` | List (`?channel=&status=&search=`) |
| POST | `/campaigns` | Create campaign |
| GET | `/campaigns/{id}` | Get campaign |
| PUT | `/campaigns/{id}` | Update campaign |
| DELETE | `/campaigns/{id}` | Delete campaign |
| POST | `/campaigns/{id}/send` | Dispatch immediately |
| POST | `/campaigns/{id}/schedule` | Schedule for later |
| POST | `/campaigns/{id}/cancel` | Cancel |
| POST | `/campaigns/{id}/pause` | Pause active send |
| POST | `/campaigns/{id}/resume` | Resume from where paused |
| POST | `/campaigns/{id}/duplicate` | Duplicate as draft |
| POST | `/campaigns/{id}/submit-approval` | Submit for review |
| POST | `/campaigns/{id}/approve` | Approve campaign |
| POST | `/campaigns/{id}/reject` | Reject with notes |
| GET | `/campaigns/{id}/preview` | Rendered preview |
| POST | `/campaigns/{id}/validate` | Pre-send QA check |
| POST | `/campaigns/{id}/test-send` | Send test to email(s) |
| POST | `/campaigns/{id}/apply-template/{tid}` | Apply email template |
| GET | `/campaigns/calendar` | Month calendar view |

**Create Email campaign**
```json
{
  "name": "May Newsletter",
  "channel": "email",
  "subject": "Hello {{name}}, here's what's new!",
  "body": "<h1>Hi {{name}}</h1><p>Exclusive offers inside.</p>",
  "from_name": "My Brand",
  "from_address": "news@mybrand.com",
  "contact_list_ids": [1, 2],
  "tags": ["newsletter", "may-2024"],
  "category": "newsletter",
  "template_vars": { "{{promo_code}}": "MAY20" }
}
```

**Create WhatsApp campaign (approved template)**
```json
{
  "name": "Diwali Offer",
  "channel": "whatsapp",
  "body": "",
  "contact_list_ids": [1],
  "metadata": {
    "whatsapp_template": "diwali_offer",
    "whatsapp_language": "en",
    "whatsapp_body_params": ["{{name}}", "DIWALI30"]
  }
}
```

**Create SMS campaign**
```json
{
  "name": "Flash Sale SMS",
  "channel": "sms",
  "body": "Hi {{name}}! 50% off today. Shop: https://example.com",
  "contact_list_ids": [1],
  "rate_limit_per_minute": 60
}
```

**Schedule a campaign**
```json
{ "scheduled_at": "2024-12-25 10:00:00" }
```

**Pre-send validation response**
```json
{
  "valid": true,
  "errors": [],
  "warnings": ["Unresolved template variable: {{promo_code}}"],
  "ai_analysis": {
    "spam_score": 0.08,
    "readability_score": 0.82,
    "sentiment": "positive",
    "estimated_open_rate": "22-28%",
    "improvements": ["Add more urgency in subject", "Include a clear CTA button"]
  }
}
```

---

### 🤖 AI Endpoints

| Method | Endpoint | Description |
|---|---|---|
| POST | `/ai/subject-lines` | Generate subject line variants |
| POST | `/ai/adapt-channel` | Rewrite content for another channel |
| POST | `/ai/analyse` | Spam/quality analysis |
| GET | `/ai/send-time/{contact_id}` | Optimal send time for a contact |

**Generate subject lines**
```json
{
  "topic": "50% off summer sale, ends Friday",
  "tone": "urgent",
  "count": 5,
  "audience": "e-commerce shoppers"
}
```
Response:
```json
{
  "data": [
    "⏰ 48 hours left — 50% off everything",
    "Your summer sale access expires Friday",
    "Last chance: half price ends at midnight",
    "Summer sale closing — grab yours now",
    "Friday deadline: 50% off still available"
  ]
}
```

**Adapt content for SMS**
```json
{
  "content": "<h1>Big summer sale</h1><p>Get 50% off all products this weekend only. Click the button below to shop now and save big on your favourite items.</p>",
  "from_channel": "email",
  "to_channel": "sms"
}
```

---

### 🔄 Workflows

| Method | Endpoint | Description |
|---|---|---|
| GET | `/workflows` | List workflows |
| POST | `/workflows` | Create workflow |
| GET | `/workflows/{id}` | Get workflow + enrolled count |
| POST | `/workflows/{id}/enrol` | Enrol contacts |
| POST | `/workflows/{id}/unenrol` | Remove contacts |

**Create a drip workflow**
```json
{
  "name": "Welcome Sequence",
  "trigger": "manual",
  "steps": [
    { "id": "s1", "type": "email",     "campaign_id": 5,  "delay_hours": 0 },
    { "id": "s2", "type": "wait",      "delay_hours": 48 },
    { "id": "s3", "type": "condition", "field": "opened_campaign", "value": 5,
      "yes": "s4", "no": "s5" },
    { "id": "s4", "type": "email",     "campaign_id": 6,  "delay_hours": 0 },
    { "id": "s5", "type": "sms",       "campaign_id": 7,  "delay_hours": 0 },
    { "id": "s6", "type": "tag",       "tag": "engaged" },
    { "id": "s7", "type": "end" }
  ]
}
```

**Supported step types:** `email`, `sms`, `whatsapp`, `push`, `wait`, `condition`, `tag`, `untag`, `list_add`, `list_remove`, `webhook`, `end`

**Condition fields:** `opened_campaign`, `clicked_campaign`, `has_tag`, `custom_field`

---

### 🔐 Privacy / GDPR

| Method | Endpoint | Description |
|---|---|---|
| DELETE | `/privacy/contacts/{id}/erase` | Right to erasure (Art. 17) |
| GET | `/privacy/contacts/{id}/export` | Data export (Art. 15) |
| POST | `/privacy/contacts/{id}/consent` | Record consent with proof |
| DELETE | `/privacy/contacts/{id}/consent` | Withdraw consent |
| GET | `/privacy/contacts/{id}/consent` | Check consent status |

**Record consent**
```json
{
  "channel": "email",
  "purpose": "marketing",
  "source_url": "https://example.com/signup"
}
```

---

### 🚫 Suppression List

| Method | Endpoint | Description |
|---|---|---|
| GET | `/suppression` | List suppressions |
| POST | `/suppression` | Add single entry |
| POST | `/suppression/bulk` | Bulk suppress (e.g. bounce list) |
| GET | `/suppression/check` | Check if recipient is suppressed |
| DELETE | `/suppression/{id}` | Remove from suppression |

**Bulk suppress bounced emails**
```json
{
  "recipients": ["bounce1@example.com", "bounce2@example.com"],
  "channel": "email",
  "reason": "bounce"
}
```

---

### 📦 Email Templates

| Method | Endpoint | Description |
|---|---|---|
| GET | `/templates` | List templates (`?category=`) |
| GET | `/templates/presets` | View built-in preset definitions |
| POST | `/templates` | Create custom template |
| POST | `/templates/presets/{key}/import` | Import a preset into your library |
| DELETE | `/templates/{id}` | Delete template |
| POST | `/campaigns/{id}/apply-template/{tid}` | Apply to campaign |

**Built-in presets:** `welcome`, `newsletter`, `promotion`, `abandoned_cart`, `win_back`, `order_confirmation`

```bash
# Import the welcome preset
POST /api/reachhub/templates/presets/welcome/import
```

---

### 🔌 Outbound Webhooks

| Method | Endpoint | Description |
|---|---|---|
| GET | `/webhooks/subscriptions` | List subscriptions |
| POST | `/webhooks/subscriptions` | Create subscription |
| PUT | `/webhooks/subscriptions/{id}` | Update |
| DELETE | `/webhooks/subscriptions/{id}` | Delete |
| POST | `/webhooks/subscriptions/{id}/test` | Send test payload |
| GET | `/webhooks/events` | List all available events |

**Create subscription**
```json
{
  "name": "My Zapier Hook",
  "url": "https://hooks.zapier.com/hooks/catch/xxx/yyy/",
  "events": ["campaign.completed", "contact.unsubscribed", "bounce.received"],
  "auth_type": "bearer",
  "auth_config": { "bearer_token": "zapier-token-here" }
}
```

**Available events (19):**
`campaign.created`, `campaign.updated`, `campaign.sent`, `campaign.completed`, `campaign.failed`, `campaign.paused`, `campaign.cancelled`, `contact.created`, `contact.updated`, `contact.unsubscribed`, `contact.suppressed`, `workflow.enrolled`, `workflow.completed`, `workflow.errored`, `bounce.received`, `complaint.received`, `open.tracked`, `click.tracked`, `delivery.confirmed`

All payloads are HMAC-SHA256 signed via `X-ReachHub-Signature` header.

---

### 🔑 API Key Management

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api-keys` | List all keys |
| POST | `/api-keys` | Create key with permissions |
| DELETE | `/api-keys/{id}` | Revoke key |

**Switch to multi-key mode** in `.env`:
```env
REACHHUB_AUTH_MODE=api_keys
```

**Create a read-only analytics key**
```json
{
  "name": "Dashboard Read-Only",
  "permissions": ["analytics.read", "campaigns.read", "contacts.read"],
  "expires_in_days": 365
}
```

**Available permission scopes:**
`campaigns.read`, `campaigns.write`, `contacts.read`, `contacts.write`, `analytics.read`, `workflows.read`, `workflows.write`, `privacy.read`, `privacy.write`, `webhooks.read`, `webhooks.write`, `*` (super)

---

### 📊 Analytics

| Method | Endpoint | Description |
|---|---|---|
| GET | `/analytics/campaigns/{id}` | Campaign stats |
| GET | `/analytics/overview` | Cross-channel summary |
| GET | `/campaigns/calendar?month=2024-12` | Scheduled/sent calendar |
| GET | `/contacts/{id}/timeline` | Contact activity history |

**Campaign stats response**
```json
{
  "stats": {
    "total_recipients": 1000,
    "sent": 998,
    "delivered": 985,
    "opened": 412,
    "clicked": 89,
    "failed": 2,
    "bounced": 13,
    "open_rate": 41.28,
    "click_rate": 21.60,
    "failure_rate": 0.20
  }
}
```

---

### 📥 Migration / Import

| Method | Endpoint | Description |
|---|---|---|
| POST | `/migrate/mailchimp` | Import from Mailchimp API |
| POST | `/migrate/csv` | Import CSV file |
| POST | `/migrate/json` | Import JSON array |

**Mailchimp import**
```json
{ "api_key": "xxx-us1", "datacenter": "us1" }
```

**CSV import with mapping**
```json
{
  "mapping": { "name": "Full Name", "email": "Email Address", "phone": "Mobile" },
  "list_id": 1
}
```

---

### 📬 Inbound Webhooks (provider callbacks)

Register these URLs in your provider dashboards (no auth required):

| Provider | URL |
|---|---|
| Email (Mailgun/SendGrid/Postmark) | `POST /api/reachhub/webhooks/email` |
| WhatsApp (Meta) | `POST /api/reachhub/webhooks/whatsapp` |
| WhatsApp verify | `GET /api/reachhub/webhooks/whatsapp` |
| SMS (Twilio/Vonage) | `POST /api/reachhub/webhooks/sms` |

---

## 🗂️ Package Structure

```
packages/reachhub/
├── composer.json
├── Config/reachhub.php
├── Database/migrations/
│   ├── ..._001_create_reachhub_tables.php          (core)
│   ├── ..._002_create_reachhub_v2_tables.php       (GDPR, workflows)
│   └── ..._003_create_reachhub_v3_tables.php       (suppression, API keys, webhooks, templates)
├── Routes/api.php                                      (40+ endpoints)
└── src/
    ├── AI/
    │   ├── ContentEngine.php                           (Ollama / OpenAI)
    │   └── SendTimeOptimizer.php
    ├── Console/Commands/
    │   ├── DispatchScheduledCampaigns.php
    │   ├── WorkflowTick.php
    │   ├── RetryFailedMessages.php
    │   ├── GdprCleanup.php
    │   └── ArchiveOldCampaigns.php
    ├── Http/
    │   ├── Controllers/ (14 controllers)
    │   ├── Middleware/ReachHubAuth.php              (single-token + multi-key)
    │   └── Requests/
    ├── Jobs/DispatchCampaignChunk.php                  (chunked queue, exp. backoff)
    ├── Migration/MigrationWizard.php                   (Mailchimp, CSV, JSON)
    ├── Models/
    │   ├── Campaign.php                                (auto body_hash, rate limit)
    │   ├── Contact.php / ContactList.php / CampaignLog.php
    │   ├── SuppressionList.php                        (cross-channel suppression)
    │   ├── ApiKey.php                                  (scoped permissions)
    │   ├── WebhookSubscription.php                     (19 events, HMAC-signed)
    │   └── EmailTemplate.php                           (6 built-in presets)
    ├── Privacy/PrivacyService.php                      (AES-256-GCM, GDPR)
    ├── Providers/ReachHubServiceProvider.php
    ├── Services/
    │   ├── CampaignService.php                         (suppression + duplicate check)
    │   ├── ChannelRateLimiter.php
    │   ├── CSV/CSVPreviewService.php
    │   ├── Preview/CampaignPreviewService.php
    │   └── Channels/
    │       ├── EmailChannel.php / WhatsAppChannel.php
    │       ├── SmsChannel.php (GSM-7/UCS-2)
    │       └── PushChannel.php (FCM)
    └── Workflow/WorkflowEngine.php                     (11 step types)
```

---

## 🔌 Adding a Custom Channel

Implement `ChannelContract` and register in `CampaignService::$channelMap`:

```php
// src/Services/Channels/TelegramChannel.php
class TelegramChannel implements ChannelContract
{
    public function channelName(): string { return 'telegram'; }
    public function validateConfig(): void { /* check bot token */ }
    public function send(Campaign $campaign, Contact $contact): array
    {
        // Telegram Bot API call
        return ['success' => true, 'message_id' => 'msg_123', 'error' => null];
    }
}
```

Then add `'telegram' => TelegramChannel::class` to `$channelMap` in `CampaignService`.

---

## ⚖️ Competitor Comparison

| Feature | **ReachHub v4** | Mautic | Mailchimp | HubSpot | Marketo |
|---|---|---|---|---|---|
| Price | **Free** | Free | $20–350/mo | $50–3,200/mo | $2k–10k/mo |
| Self-hosted | ✅ | ✅ | ❌ | ❌ | ❌ |
| Multi-channel | ✅ Email/WA/SMS/Push | ⚠️ Email+plugins | ⚠️ Email-first | ✅ paid | ✅ |
| Local LLM AI | ✅ Free (Ollama) | ❌ | ❌ | ❌ API only | ❌ |
| Workflow engine | ✅ Conditional branching | ✅ | ❌ | ✅ paid | ✅ |
| GDPR suite | ✅ Encrypt+erase+consent | ⚠️ Basic | ⚠️ | ✅ | ✅ |
| Suppression list | ✅ Per-channel | ❌ | ✅ | ✅ | ✅ |
| API key scopes | ✅ | ❌ | ✅ | ✅ | ✅ |
| Outbound webhooks | ✅ 19 events | ✅ | ✅ | ✅ | ✅ |
| Email templates | ✅ 6+ presets | ❌ | ✅ | ✅ | ✅ |
| Campaign approval | ✅ | ❌ | ❌ | ✅ | ✅ |
| Migration tools | ✅ Mailchimp/CSV/JSON | ❌ | ❌ | ❌ | ❌ |
| Contact timeline | ✅ | ❌ | ✅ | ✅ | ✅ |
| Send-time optimisation | ✅ Per-contact | ❌ | ✅ paid | ✅ paid | ✅ |

---

## 📜 License

MIT © Susheel Kumar susheelhbti@gmail.com
