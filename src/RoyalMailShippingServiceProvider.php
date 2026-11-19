<?php

declare(strict_types=1);

namespace Liberu\Shipping\RoyalMail;

use Illuminate\Support\ServiceProvider;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderRegistryContract;

final class RoyalMailShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RoyalMailShippingAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(ShippingProviderRegistryContract::class)) {
            $this->app->make(ShippingProviderRegistryContract::class)->register(
                $this->app->make(RoyalMailShippingAdapter::class),
            );
        }
    }
}
