### AT composer.json add

```php
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/A7my/payment-hub"
        }
    ],
```

### RUN

composer require a7my/payment-hub
composer update a7my/payment-hub
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=payment-migrations --force

### 1. Implement `Payable` on your model

```php
<?php
namespace App\Models;

use App\Traits\HasMedia;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\Contracts\CapturesCheckoutContext;


class User extends Authenticatable implements JWTSubject, Payable , CapturesCheckoutContext
{
    use HasFactory, Notifiable, HasRoles, HasMedia;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public $mediaKeys = ['image'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function wallet()
    {
        return $this->hasOneThrough(Wallet::class, Store::class, 'id', 'store_id', 'store_id', 'id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    // -------------------------------------------------------------------------
    // Payable — amount/currency have no dedicated columns on User, so we
    // implement the interface methods directly (skipping IsPayable trait) as
    // the README instructs when the amount isn't a single stored column.
    // Fixed 10.00 SAR for testing.
    // -------------------------------------------------------------------------

    public function getPaymentAmount(): Money
    {
        // Test only: strips non-digits from phone and uses as amount in halalas.
        // e.g. phone '0555044444' → 555044444 halalas → 5,550,444.44 SAR
        // Make sure the test user has a non-null phone set in the database.
        $amount = (int) preg_replace('/\D/', '', (string) $this->phone);

        if ($amount < 1) {
            $amount = 1000; // fallback: 10.00 SAR if phone is null/empty
        }

        return Money::ofMinor($amount, Currency::SAR);
    }

    public function getPaymentAmount(): Money
    {
        // price is stored in major units (e.g. 40.00 SAR).
        // Money::ofMinor() expects the smallest unit (halalas), so multiply by 100.
        $amount = (int) round((float) $this->price * 100);

        if ($amount < 1) {
            $amount = 1000; // fallback: 10.00 SAR
        }

        return Money::ofMinor($amount, Currency::SAR);
    }

    public function getPaymentCurrency(): Currency
    {
        return Currency::SAR;
    }

    public function getSupportedPaymentDrivers(): array
    {
        return ['stripe', 'paymob'];
    }

    public function authorizePayment(?AuthenticatableContract $payer): bool
    {
        // return true;
        // return $payer?->id === $this->id;
        return $payer !== null;
    }

   public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
    {
        if (! $status->isSuccessful() || $status->getStatus() !== PaymentStatus::Captured) return;

        $transactionId = $status->getTransactionId()->toString();
        if (Transaction::where('transaction_id', $transactionId)->exists()) return;

        $payer = $context->payer();
        if ($payer === null) return;

        $duration     = $this->duration ?? 0;
        $durationType = $this->duration_type ?? 'day';

        $subscription = Subscription::create([
            'user_id'                  => $payer->getAuthIdentifier(),
            'package_id'               => $this->id,
            'remaining_ad'             => $this->ad ?? 0,
            'remaining_premium_ad'     => $this->premium_ad ?? 0,
            'ad_duration'              => $duration,
            'ad_duration_type'         => $durationType,
            'premium_ad_duration'      => $this->premium_ad_duration ?? 0,
            'premium_ad_duration_type' => $this->premium_ad_duration_type ?? 'day',
            'start_date'               => now(),
            'end_date'                 => now()->add($durationType, $duration),
        ]);

        Transaction::create([
            'transaction_id'  => $transactionId,
            'user_id'         => $payer->getAuthIdentifier(),
            'package_id'      => $this->id,
            'subscription_id' => $subscription->id,
            'amount'          => $context->get('price', $this->price),
            'payment_method'  => $context->driver,
        ]);
    }

    public function captureCheckoutContext(): array
    {
        return [
            'price'    => $this->price,
            'payer' => auth()->user(),
        ];
    }
}
```

### 2. Register it in config

```php
// config/payment.php
'payables' => [
    'order' => \App\Models\Order::class,
],

'checkout' => [
    'enabled'    => env('PAYMENT_CHECKOUT_ENABLED', true),
    'route'      => env('PAYMENT_CHECKOUT_ROUTE', 'payment/checkout'), // you can change the route here.
    'middleware' => ['api', 'auth:api'],
],

```

### RUN THIS

http://127.0.0.1:8000/payment/checkout

{
    "model_type": "package",
    "model_id": "3",
    "driver": "stripe",
    "driver_type": "webview",
    "return_url": "https://github.com/A7my/payment-hub",
    "cancel_url": "https://www.google.com/",
    "os" : "web"
}
