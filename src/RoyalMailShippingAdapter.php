<?php

declare(strict_types=1);

namespace Liberu\Shipping\RoyalMail;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Liberu\Shipping\Contracts\Contracts\RateQuotingContract;
use Liberu\Shipping\Contracts\Contracts\ShipmentCreationContract;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderAdapterContract;
use Liberu\Shipping\Contracts\Contracts\TrackingContract;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\CancelShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Shipping\Contracts\DTOs\CreateShipmentCommand;
use Liberu\Shipping\Contracts\DTOs\GetRatesCommand;
use Liberu\Shipping\Contracts\DTOs\GetTrackingCommand;
use Liberu\Shipping\Contracts\DTOs\MoneyAmount;
use Liberu\Shipping\Contracts\DTOs\ShipmentResultDto;
use Liberu\Shipping\Contracts\DTOs\ShippingAccountContextDto;
use Liberu\Shipping\Contracts\DTOs\ShippingCapabilityDescriptorDto;
use Liberu\Shipping\Contracts\DTOs\ShippingRateDto;
use Liberu\Shipping\Contracts\DTOs\TrackingEventDto;
use Liberu\Shipping\Contracts\DTOs\TrackingResultDto;
use Liberu\Shipping\Contracts\Enums\ShipmentLifecycleState;
use RuntimeException;

final readonly class RoyalMailShippingAdapter implements RateQuotingContract, ShipmentCreationContract, ShippingProviderAdapterContract, TrackingContract
{
    private const BASE_URL = 'https://api.parcel.royalmail.com/api/v1';

    public function providerKey(): string
    {
        return 'royalmail';
    }

    public function descriptor(): ShippingCapabilityDescriptorDto
    {
        return new ShippingCapabilityDescriptorDto(
            providerKey: 'royalmail',
            displayName: 'Royal Mail',
            supportedCarriers: ['Royal Mail'],
            supportedCountries: ['GB', 'IE', 'EU', 'US', 'CA', 'AU'],
            isAggregator: false,
            supportsRateQuoting: true,
            supportsLabelPurchase: true,
            supportsTracking: true,
            supportsAddressValidation: false,
            supportsWebhooks: false,
            supportsPickupScheduling: false,
        );
    }

    public function testConnection(ShippingAccountContextDto $account): ConnectionHealthDto
    {
        if ($this->apiKey($account) === '') {
            return new ConnectionHealthDto('royalmail', false, 'Royal Mail API key is missing.');
        }

        $response = $this->client($account)->get(self::BASE_URL.'/version');

        return new ConnectionHealthDto(
            providerKey: 'royalmail',
            healthy: $response->successful(),
            message: $response->successful() ? 'Royal Mail API connection verified.' : 'Royal Mail API rejected credentials.',
            details: ['status' => $response->status(), 'sandbox' => $account->sandbox],
        );
    }

    public function getRates(GetRatesCommand $command): array
    {
        if ($this->apiKey($command->account) === '') {
            return [];
        }

        $response = $this->client($command->account)->post(self::BASE_URL.'/rates', [
            'originPostcode' => $command->from->postalCode ?? '',
            'destinationPostcode' => $command->to->postalCode ?? '',
            'destinationCountryCode' => $command->to->country,
            'weightInGrams' => max(1, $command->parcel->weightInGrams()),
        ]);

        if (! $response->successful()) {
            return [];
        }

        $services = $response->json('rates', []);
        if (! is_array($services)) {
            return [];
        }

        $mapped = [];
        foreach ($services as $rate) {
            if (! is_array($rate) || ! isset($rate['price'])) {
                continue;
            }
            $code = (string) ($rate['serviceCode'] ?? 'TPN24');
            $mapped[] = new ShippingRateDto(
                providerKey: 'royalmail',
                carrier: 'Royal Mail',
                service: (string) ($rate['serviceName'] ?? $code),
                amount: MoneyAmount::fromMajor($rate['price'], (string) ($rate['currency'] ?? 'GBP')),
                deliveryDays: isset($rate['estimatedDeliveryDays']) ? (int) $rate['estimatedDeliveryDays'] : null,
                rateId: "royalmail_{$code}",
            );
        }

        return $mapped;
    }

    public function createShipment(CreateShipmentCommand $command): ShipmentResultDto
    {
        $serviceCode = $command->service ?? 'TPN24';
        $response = $this->client($command->account)->post(self::BASE_URL.'/orders', [
            'items' => [[
                'orderReference' => $command->idempotencyKey,
                'recipient' => [
                    'address' => [
                        'fullName' => $command->to->name ?? 'Recipient',
                        'addressLine1' => $command->to->street1 ?? '',
                        'addressLine2' => $command->to->street2,
                        'city' => $command->to->city ?? '',
                        'county' => $command->to->state,
                        'postcode' => $command->to->postalCode ?? '',
                        'countryCode' => $command->to->country,
                    ],
                    'phoneNumber' => $command->to->phone,
                    'emailAddress' => $command->to->email,
                ],
                'packages' => [[
                    'weightInGrams' => max(1, $command->parcel->weightInGrams()),
                    'packageFormatIdentifier' => 'parcel',
                ]],
                'postageDetails' => [
                    'serviceCode' => $serviceCode,
                ],
            ]],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Royal Mail order creation failed: '.$response->status());
        }

        $created = $response->json('createdOrders.0', []);
        $orderId = (string) ($created['orderIdentifier'] ?? $command->idempotencyKey);
        $tracking = isset($created['trackingNumber']) ? (string) $created['trackingNumber'] : null;

        return new ShipmentResultDto(
            providerKey: 'royalmail',
            providerShipmentId: $orderId,
            state: ShipmentLifecycleState::LabelPurchased,
            carrier: 'Royal Mail',
            service: $serviceCode,
            trackingNumber: $tracking,
            labelFormat: strtoupper($command->labelFormat),
        );
    }

    public function cancelShipment(CancelShipmentCommand $command): CancelShipmentResultDto
    {
        $response = $this->client($command->account)->delete(
            self::BASE_URL."/orders/{$command->providerShipmentId}"
        );

        if (! $response->successful()) {
            throw new RuntimeException('Royal Mail order cancellation failed: '.$response->status());
        }

        return new CancelShipmentResultDto(
            providerKey: 'royalmail',
            providerShipmentId: $command->providerShipmentId,
            state: ShipmentLifecycleState::Cancelled,
            refunded: true,
            message: 'Royal Mail order cancelled.',
        );
    }

    public function getTracking(GetTrackingCommand $command): TrackingResultDto
    {
        $response = $this->client($command->account)->get(
            self::BASE_URL."/orders/{$command->trackingNumber}/tracking"
        );

        if (! $response->successful()) {
            throw new RuntimeException('Royal Mail tracking lookup failed: '.$response->status());
        }

        $data = $response->json();
        $events = [];
        foreach ((array) ($data['events'] ?? []) as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $events[] = new TrackingEventDto(
                description: (string) ($ev['eventName'] ?? ''),
                state: $this->mapStatus((string) ($ev['eventCode'] ?? '')),
                occurredAtIso8601: isset($ev['eventDateTime']) ? (string) $ev['eventDateTime'] : null,
                location: isset($ev['locationName']) ? (string) $ev['locationName'] : null,
                rawStatus: isset($ev['eventCode']) ? (string) $ev['eventCode'] : null,
            );
        }

        return new TrackingResultDto(
            providerKey: 'royalmail',
            trackingNumber: $command->trackingNumber,
            state: $this->mapStatus((string) ($data['status'] ?? '')),
            carrier: 'Royal Mail',
            events: $events,
        );
    }

    private function client(ShippingAccountContextDto $account): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey($account),
        ])->timeout($account->timeoutSeconds)->acceptJson();
    }

    private function apiKey(ShippingAccountContextDto $account): string
    {
        return (string) ($account->credentials['api_key'] ?? '');
    }

    private function mapStatus(string $status): ShipmentLifecycleState
    {
        return match (strtoupper($status)) {
            'ACCEPTED', 'DESPATCHED' => ShipmentLifecycleState::PreTransit,
            'IN_TRANSIT', 'AT_MC' => ShipmentLifecycleState::InTransit,
            'OUT_FOR_DELIVERY', 'EVKDA' => ShipmentLifecycleState::OutForDelivery,
            'DELIVERED', 'EVNDE' => ShipmentLifecycleState::Delivered,
            'RETURNED' => ShipmentLifecycleState::ReturnToSender,
            'FAILED', 'EXCEPTION' => ShipmentLifecycleState::Exception,
            default => ShipmentLifecycleState::Unknown,
        };
    }
}
