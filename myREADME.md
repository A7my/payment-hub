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

class User extends Authenticatable implements JWTSubject, Payable
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
        return $payer?->id === $this->id;
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
"model_type": "user",
"model_id": "2",
"driver": "paymob",
"driver_type": "webview",
"return_url": "https://yourapp.com/payment/success",
"cancel_url": "https://yourapp.com/payment/cancel"
}
