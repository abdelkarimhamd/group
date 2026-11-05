<?php

namespace App\Modules\Fulfillment\Couriers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Aramex's Shipping and Tracking services (JSON flavour of the SOAP API,
 * `ShippingAPI.V2/.../Service_1_0.svc/json`). Built from Aramex's published
 * developer guide; it could not be called from where it was written, so
 * check a booking and a tracking read against the Aramex sandbox
 * (ARAMEX_BASE_URL=https://ws.sbx.aramex.net) before launch.
 *
 * Aramex documents no idempotency for CreateShipments. Our booking key goes
 * out as Reference2 and the transaction reference, so a booking sent twice
 * after a timeout can be found and voided in the Aramex portal; the caller
 * never resends on its own after an unclear answer.
 */
final class AramexCourier implements CourierGateway
{
    private const SHIPPING = '/ShippingAPI.V2/Shipping/Service_1_0.svc/json/CreateShipments';

    private const TRACKING = '/ShippingAPI.V2/Tracking/Service_1_0.svc/json/TrackShipments';

    /**
     * @param  array{username: string, password: string, account_number: string, account_pin: string, account_entity: string, account_country_code: string, version: string, product_group: string, product_type: string}  $account
     * @param  array{name: string, company: string, phone: string, email: string, line1: string, line2: string, city: string, postal_code: string}  $shipper
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $account,
        private readonly array $shipper,
        private readonly int $timeoutSeconds,
    ) {
        foreach (['username', 'password', 'account_number', 'account_pin'] as $key) {
            if (($account[$key] ?? '') === '') {
                throw new LogicException('Set ARAMEX_USERNAME, ARAMEX_PASSWORD, ARAMEX_ACCOUNT_NUMBER and ARAMEX_ACCOUNT_PIN to use Aramex.');
            }
        }
    }

    public function name(): ?string
    {
        return 'Aramex';
    }

    public function books(): bool
    {
        return true;
    }

    public function book(BookingRequest $request): Booking
    {
        $now = CarbonImmutable::now();
        $consignee = $request->consignee;
        $body = [
            'ClientInfo' => $this->clientInfo(),
            'LabelInfo' => ['ReportID' => 9201, 'ReportType' => 'RPT'],
            'Shipments' => [[
                'Reference1' => $request->orderNumber,
                'Reference2' => $request->reference,
                'Shipper' => [
                    'Reference1' => $request->orderNumber,
                    'AccountNumber' => $this->account['account_number'],
                    'PartyAddress' => [
                        'Line1' => $this->shipper['line1'],
                        'Line2' => $this->shipper['line2'],
                        'City' => $this->shipper['city'],
                        'PostCode' => $this->shipper['postal_code'],
                        'CountryCode' => 'SA',
                    ],
                    'Contact' => [
                        'PersonName' => $this->shipper['name'],
                        'CompanyName' => $this->shipper['company'],
                        'PhoneNumber1' => $this->shipper['phone'],
                        'CellPhone' => $this->shipper['phone'],
                        'EmailAddress' => $this->shipper['email'],
                    ],
                ],
                'Consignee' => [
                    'Reference1' => $request->orderNumber,
                    'PartyAddress' => [
                        'Line1' => trim(implode(' ', array_filter([$consignee['building_no'], $consignee['street']]))),
                        'Line2' => (string) $consignee['district'],
                        'Line3' => (string) $consignee['short_address'],
                        'City' => $consignee['city'],
                        'PostCode' => (string) $consignee['postal_code'],
                        'CountryCode' => 'SA',
                    ],
                    'Contact' => [
                        'PersonName' => $consignee['name'],
                        'CompanyName' => $consignee['name'],
                        'PhoneNumber1' => $consignee['phone'],
                        'CellPhone' => $consignee['phone'],
                        'EmailAddress' => '',
                    ],
                ],
                'ShippingDateTime' => self::date($now),
                'DueDate' => self::date($now->addDays(3)),
                'Details' => [
                    'ActualWeight' => ['Unit' => 'KG', 'Value' => max(0.1, round($request->weightGrams / 1000, 3))],
                    'NumberOfPieces' => $request->pieces,
                    'ProductGroup' => $this->account['product_group'],
                    'ProductType' => $this->account['product_type'],
                    // Prepaid by us: the customer paid delivery with the order.
                    'PaymentType' => 'P',
                    'DescriptionOfGoods' => mb_substr($request->description, 0, 100),
                    'GoodsOriginCountry' => 'CN',
                ],
            ]],
            'Transaction' => ['Reference1' => $request->reference],
        ];

        $response = $this->post(self::SHIPPING, $body);
        $json = self::json($response);

        // A 4xx other than throttling is Aramex refusing what we sent.
        if ($response->clientError() && $response->status() !== 429) {
            throw new CourierRefused('Aramex refused the booking ('.$response->status().'): '.self::notifications($json));
        }

        if (! $response->successful() || $json === null) {
            throw new CourierUnavailable('Aramex gave no readable answer to the booking ('.$response->status().').');
        }

        $shipment = $json['Shipments'][0] ?? null;

        if (($json['HasErrors'] ?? true) === true || ! is_array($shipment) || ($shipment['HasErrors'] ?? false) === true || ! is_scalar($shipment['ID'] ?? null) || (string) $shipment['ID'] === '') {
            throw new CourierRefused('Aramex refused the booking: '.self::notifications($json + (is_array($shipment) ? ['Shipment' => $shipment] : [])));
        }

        return new Booking((string) $shipment['ID'], $this->label(is_array($shipment['ShipmentLabel'] ?? null) ? $shipment['ShipmentLabel'] : []));
    }

    public function track(array $trackingNumbers): array
    {
        if ($trackingNumbers === []) {
            return [];
        }

        $response = $this->post(self::TRACKING, [
            'ClientInfo' => $this->clientInfo(),
            'GetLastTrackingUpdateOnly' => false,
            'Shipments' => array_values($trackingNumbers),
            'Transaction' => ['Reference1' => ''],
        ]);
        $json = self::json($response);

        if (! $response->successful() || $json === null || ! is_array($json['TrackingResults'] ?? null)) {
            throw new CourierUnavailable('Aramex gave no readable tracking answer ('.$response->status().'): '.self::notifications($json));
        }

        $updates = [];

        foreach ($json['TrackingResults'] as $result) {
            foreach (is_array($result['Value'] ?? null) ? $result['Value'] : [] as $row) {
                $number = $row['WaybillNumber'] ?? $result['Key'] ?? null;
                $code = $row['UpdateCode'] ?? null;
                $at = self::parseDate($row['UpdateDateTime'] ?? null);

                if (! is_scalar($number) || ! is_string($code) || $at === null) {
                    Log::warning('courier.aramex.unreadable_update', ['row' => $row]);

                    continue;
                }

                $updates[] = new TrackingUpdate(
                    (string) $number,
                    $code,
                    self::text($row['UpdateDescription'] ?? null, 500),
                    self::text($row['UpdateLocation'] ?? null, 200),
                    $at,
                    $row,
                );
            }
        }

        return $updates;
    }

    /**
     * Aramex's .NET JSON dates: "/Date(1700000000000+0300)/", milliseconds
     * since the epoch in UTC; the offset is only the local zone.
     */
    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('#^/Date\((-?\d+)([+-]\d{4})?\)/$#', $value, $match) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC((int) $match[1]);
    }

    private static function date(CarbonImmutable $at): string
    {
        return '/Date('.$at->getTimestampMs().')/';
    }

    /**
     * @return array<string, mixed>
     */
    private function clientInfo(): array
    {
        return [
            'UserName' => $this->account['username'],
            'Password' => $this->account['password'],
            'Version' => $this->account['version'],
            'AccountNumber' => $this->account['account_number'],
            'AccountPin' => $this->account['account_pin'],
            'AccountEntity' => $this->account['account_entity'],
            'AccountCountryCode' => $this->account['account_country_code'],
            'Source' => 24,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): Response
    {
        try {
            return Http::acceptJson()->asJson()->timeout($this->timeoutSeconds)->post(rtrim($this->baseUrl, '/').$path, $body);
        } catch (Throwable $e) {
            throw new CourierUnavailable('Aramex could not be reached: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * The label as PDF bytes: sent inline as a byte array or base64, or as a
     * link. Null when neither can be read; the label can then be printed
     * from the Aramex portal.
     *
     * @param  array<string, mixed>  $label
     */
    private function label(array $label): ?string
    {
        $contents = $label['LabelFileContents'] ?? null;

        if (is_array($contents) && $contents !== []) {
            return pack('C*', ...array_map('intval', $contents));
        }

        if (is_string($contents) && $contents !== '' && ($bytes = base64_decode($contents, true)) !== false) {
            return $bytes;
        }

        if (is_string($label['LabelURL'] ?? null) && str_starts_with($label['LabelURL'], 'https://')) {
            try {
                $response = Http::timeout($this->timeoutSeconds)->get($label['LabelURL']);

                return $response->successful() ? $response->body() : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function json(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private static function notifications(?array $json): string
    {
        $messages = [];

        foreach ([$json['Notifications'] ?? [], $json['Shipment']['Notifications'] ?? []] as $list) {
            foreach (is_array($list) ? $list : [] as $note) {
                if (is_array($note)) {
                    $messages[] = trim(($note['Code'] ?? '').' '.($note['Message'] ?? ''));
                }
            }
        }

        return $messages === [] ? 'no reason given' : mb_substr(implode('; ', $messages), 0, 400);
    }

    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
