<?php

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO REGISTER THE SCHEDULED CAMPAIGN DISPATCHER
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ReachHub ships an Artisan command: reachhub:dispatch-scheduled
 * This command checks for any campaigns with status='scheduled' whose
 * scheduled_at <= now() and dispatches them automatically.
 *
 * Add the following to your application's scheduler so it runs every minute.
 *
 * ── Laravel 10 and below ──────────────────────────────────────────────────
 * In app/Console/Kernel.php, inside the schedule() method:
 *
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('reachhub:dispatch-scheduled')->everyMinute();
 *   }
 *
 * Then ensure your cron is running:
 *   * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
 *
 * ── Laravel 11+ ───────────────────────────────────────────────────────────
 * In routes/console.php:
 *
 *   use Illuminate\Support\Facades\Schedule;
 *
 *   Schedule::command('reachhub:dispatch-scheduled')->everyMinute();
 *
 * ── Manual dispatch (testing / CI) ───────────────────────────────────────
 *   php artisan reachhub:dispatch-scheduled
 *   php artisan reachhub:dispatch-scheduled --dry-run   # preview only
 *
 * ── Webhook URLs to register with providers ───────────────────────────────
 * Register these in your provider dashboards:
 *
 *   Email (Mailgun/SendGrid/Postmark):
 *     POST https://your-app.com/api/reachhub/webhooks/email
 *
 *   WhatsApp (Meta Developer Console):
 *     POST https://your-app.com/api/reachhub/webhooks/whatsapp
 *     GET  https://your-app.com/api/reachhub/webhooks/whatsapp  ← verify URL
 *     Set WHATSAPP_WEBHOOK_VERIFY_TOKEN in .env to match Meta's dashboard
 *
 *   SMS (Twilio Status Callback / Vonage DLR):
 *     POST https://your-app.com/api/reachhub/webhooks/sms
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */
