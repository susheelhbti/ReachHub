<?php

namespace ReachHub\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $table = 'ck_email_templates';

    protected $fillable = [
        'name',
        'description',
        'category',      // welcome | newsletter | promotion | transactional | win-back | event
        'subject',
        'html',
        'thumbnail_url',
        'is_public',
        'usage_count',
        'created_by',
    ];

    protected $casts = [
        'is_public'   => 'boolean',
        'usage_count' => 'integer',
    ];

    // ── Built-in Presets ──────────────────────────────────────────────────

    public static function presets(): array
    {
        return [
            'welcome' => [
                'name'        => 'Welcome Email',
                'category'    => 'welcome',
                'subject'     => 'Welcome to {{brand_name}}, {{name}}!',
                'description' => 'A warm welcome message for new subscribers.',
                'html'        => <<<HTML
<!DOCTYPE html>
<html><body style="font-family:sans-serif;max-width:600px;margin:auto;padding:20px;color:#333">
  <h1 style="color:#4F46E5">Welcome, {{name}}! 👋</h1>
  <p>We're thrilled to have you. Here's what you can expect:</p>
  <ul>
    <li>{{benefit_1}}</li>
    <li>{{benefit_2}}</li>
    <li>{{benefit_3}}</li>
  </ul>
  <a href="{{cta_url}}" style="display:inline-block;background:#4F46E5;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;margin-top:16px">{{cta_text}}</a>
  <p style="margin-top:32px;font-size:13px;color:#888">You're receiving this because you signed up at {{brand_name}}. <a href="{{unsubscribe_url}}">Unsubscribe</a></p>
</body></html>
HTML,
            ],

            'newsletter' => [
                'name'        => 'Monthly Newsletter',
                'category'    => 'newsletter',
                'subject'     => '{{brand_name}} Newsletter — {{month_year}}',
                'description' => 'Clean newsletter layout with article blocks.',
                'html'        => <<<HTML
<!DOCTYPE html>
<html><body style="font-family:sans-serif;max-width:600px;margin:auto;padding:20px;color:#333">
  <div style="border-bottom:3px solid #4F46E5;padding-bottom:16px;margin-bottom:24px">
    <h1 style="margin:0;font-size:22px">{{brand_name}} Newsletter</h1>
    <p style="margin:4px 0 0;color:#888;font-size:13px">{{month_year}}</p>
  </div>
  <h2>{{headline_1}}</h2>
  <p>{{body_1}}</p>
  <a href="{{link_1}}">Read more →</a>
  <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
  <h2>{{headline_2}}</h2>
  <p>{{body_2}}</p>
  <a href="{{link_2}}">Read more →</a>
  <p style="margin-top:40px;font-size:12px;color:#aaa">© {{brand_name}} · <a href="{{unsubscribe_url}}">Unsubscribe</a></p>
</body></html>
HTML,
            ],

            'promotion' => [
                'name'        => 'Promotional Offer',
                'category'    => 'promotion',
                'subject'     => '{{discount}}% off — Ends {{end_date}}',
                'description' => 'Time-limited offer with CTA button.',
                'html'        => <<<HTML
<!DOCTYPE html>
<html><body style="font-family:sans-serif;max-width:600px;margin:auto;padding:0;color:#333">
  <div style="background:#4F46E5;padding:40px 24px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:48px;font-weight:700">{{discount}}% OFF</h1>
    <p style="color:#c7d2fe;margin:8px 0 0">Use code <strong style="color:#fff">{{promo_code}}</strong></p>
  </div>
  <div style="padding:32px 24px;text-align:center">
    <p style="font-size:18px">Hi {{name}}, this offer is just for you.</p>
    <p>{{offer_description}}</p>
    <a href="{{shop_url}}" style="display:inline-block;background:#4F46E5;color:#fff;padding:14px 36px;border-radius:6px;text-decoration:none;font-size:16px;margin:16px 0">Shop Now</a>
    <p style="font-size:13px;color:#888">Offer ends {{end_date}}. Cannot be combined with other offers.</p>
  </div>
  <div style="background:#f9fafb;padding:16px 24px;text-align:center">
    <p style="font-size:12px;color:#aaa">© {{brand_name}} · <a href="{{unsubscribe_url}}">Unsubscribe</a></p>
  </div>
</body></html>
HTML,
            ],

            'abandoned_cart' => [
                'name'        => 'Abandoned Cart Recovery',
                'category'    => 'transactional',
                'subject'     => 'You left something behind, {{name}}...',
                'description' => 'Recover abandoned carts with product summary.',
                'html'        => <<<HTML
<!DOCTYPE html>
<html><body style="font-family:sans-serif;max-width:600px;margin:auto;padding:20px;color:#333">
  <h2>You forgot something! 🛒</h2>
  <p>Hi {{name}}, you left items in your cart:</p>
  <div style="background:#f9fafb;border-radius:8px;padding:16px;margin:16px 0">
    <p><strong>{{product_name}}</strong></p>
    <p style="color:#888">{{product_description}}</p>
    <p style="font-size:22px;font-weight:700;color:#4F46E5">{{price}}</p>
  </div>
  <a href="{{cart_url}}" style="display:inline-block;background:#4F46E5;color:#fff;padding:14px 36px;border-radius:6px;text-decoration:none">Complete My Purchase</a>
  <p style="margin-top:24px;font-size:13px;color:#888">This cart expires in {{expiry_hours}} hours. <a href="{{unsubscribe_url}}">Unsubscribe</a></p>
</body></html>
HTML,
            ],

            'win_back' => [
                'name'        => 'Win-Back Campaign',
                'category'    => 'win-back',
                'subject'     => 'We miss you, {{name}} — here\'s {{discount}}% off',
                'description' => 'Re-engage inactive subscribers.',
                'html'        => <<<HTML
<!DOCTYPE html>
<html><body style="font-family:sans-serif;max-width:600px;margin:auto;padding:20px;color:#333;text-align:center">
  <div style="font-size:56px">💌</div>
  <h2>We miss you, {{name}}!</h2>
  <p>It's been a while. To welcome you back, here's <strong>{{discount}}% off</strong> your next order.</p>
  <p style="font-size:22px;font-weight:700;letter-spacing:2px;background:#f3f4f6;display:inline-block;padding:8px 24px;border-radius:8px">{{promo_code}}</p>
  <br>
  <a href="{{shop_url}}" style="display:inline-block;background:#4F46E5;color:#fff;padding:14px 36px;border-radius:6px;text-decoration:none;margin-top:16px">Come Back</a>
  <p style="margin-top:32px;font-size:13px;color:#888">Offer valid until {{end_date}}. <a href="{{unsubscribe_url}}">Unsubscribe</a></p>
</body></html>
HTML,
            ],

            'order_confirmation' => [
                'name'        => 'Order Confirmation',
                'category'    => 'transactional',
                'subject'     => 'Your order #{{order_id}} is confirmed!',
                'description' => 'Transactional order confirmation with summary.',
                'html'        => <<<HTML
<!DOCTYPE html>
<html><body style="font-family:sans-serif;max-width:600px;margin:auto;padding:20px;color:#333">
  <h2 style="color:#16a34a">✓ Order Confirmed!</h2>
  <p>Hi {{name}}, thank you for your order.</p>
  <div style="background:#f9fafb;border-radius:8px;padding:16px;margin:16px 0">
    <p><strong>Order #{{order_id}}</strong></p>
    <p>{{product_summary}}</p>
    <hr style="border:none;border-top:1px solid #e5e7eb">
    <p><strong>Total: {{total}}</strong></p>
  </div>
  <p>Expected delivery: <strong>{{delivery_date}}</strong></p>
  <a href="{{tracking_url}}" style="display:inline-block;background:#16a34a;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none">Track My Order</a>
</body></html>
HTML,
            ],
        ];
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }
}
