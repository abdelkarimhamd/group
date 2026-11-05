<?php

namespace Tests\Feature\Fulfillment;

use App\Modules\Fulfillment\Couriers\AramexCourier;
use App\Modules\Fulfillment\Couriers\BookingRequest;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\CourierRefused;
use App\Modules\Fulfillment\Couriers\CourierUnavailable;
use App\Modules\Fulfillment\Couriers\FakeCourier;
use App\Modules\Fulfillment\Couriers\ManualCourier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Aramex driver against Aramex's documented JSON answers (no network).
 * Check it against the Aramex sandbox before launch.
 */
class AramexCourierTest extends TestCase
{
    private const BASE = 'https://ws.sbx.aramex.net';

    #[Test]
    public function a_booking_sends_our_reference_and_reads_the_waybill_and_label(): void
    {
        Http::fake([self::BASE.'/ShippingAPI.V2/Shipping/Service_1_0.svc/json/CreateShipments' => Http::response([
            'HasErrors' => false,
            'Notifications' => [],
            'Shipments' => [[
                'ID' => '44120099881',
                'HasErrors' => false,
                'ShipmentLabel' => ['LabelURL' => null, 'LabelFileContents' => array_map('ord', str_split('%PDF-1.4 label'))],
            ]],
        ])]);

        $booking = $this->courier()->book($this->request());

        $this->assertSame('44120099881', $booking->trackingNumber);
        $this->assertSame('%PDF-1.4 label', $booking->labelPdf);
        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $shipment = $body['Shipments'][0];

            return $body['ClientInfo']['UserName'] === 'api@example.sa'
                && $body['ClientInfo']['AccountPin'] === '331421'
                && $body['ClientInfo']['Source'] === 24
                && $body['Transaction']['Reference1'] === 'ref-123'
                && $shipment['Reference1'] === 'ORD-2611-000001'
                && $shipment['Reference2'] === 'ref-123'
                && $shipment['Consignee']['PartyAddress']['Line1'] === '1234 King Fahd Road'
                && $shipment['Consignee']['PartyAddress']['City'] === 'Riyadh'
                && $shipment['Consignee']['Contact']['CellPhone'] === '+966512345678'
                && $shipment['Details']['ActualWeight'] === ['Unit' => 'KG', 'Value' => 2.4]
                && $shipment['Details']['NumberOfPieces'] === 1
                && $shipment['Details']['ProductGroup'] === 'DOM'
                && $shipment['Details']['PaymentType'] === 'P'
                && preg_match('#^/Date\(\d+\)/$#', $shipment['ShippingDateTime']) === 1;
        });
    }

    #[Test]
    public function errors_in_the_answer_are_refusals_and_anything_unclear_is_unavailable(): void
    {
        Http::fakeSequence()
            ->push(['HasErrors' => true, 'Notifications' => [['Code' => 'ERR52', 'Message' => 'Consignee city is invalid']], 'Shipments' => []])
            ->push(['HasErrors' => false, 'Shipments' => [['ID' => null, 'HasErrors' => true, 'Notifications' => [['Code' => 'ERR01', 'Message' => 'Bad weight']]]]])
            ->push('Bad request', 400)
            ->push('Server error', 500)
            ->push('not json', 200)
            ->push('Too many', 429);

        foreach ([
            [CourierRefused::class, 'ERR52 Consignee city is invalid'],
            [CourierRefused::class, 'ERR01 Bad weight'],
            [CourierRefused::class, '(400)'],
            [CourierUnavailable::class, '(500)'],
            [CourierUnavailable::class, '(200)'],
            [CourierUnavailable::class, '(429)'],
        ] as [$class, $message]) {
            try {
                $this->courier()->book($this->request());
                $this->fail("Expected {$class}.");
            } catch (CourierRefused|CourierUnavailable $e) {
                $this->assertInstanceOf($class, $e);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $this->expectException(CourierUnavailable::class);
        $this->courier()->book($this->request());
    }

    #[Test]
    public function tracking_reads_every_update_with_its_time_in_utc(): void
    {
        Http::fake([self::BASE.'/ShippingAPI.V2/Tracking/Service_1_0.svc/json/TrackShipments' => Http::response([
            'HasErrors' => false,
            'NonExistingWaybills' => ['999'],
            'TrackingResults' => [[
                'Key' => '44120099881',
                'Value' => [
                    ['WaybillNumber' => '44120099881', 'UpdateCode' => 'SH014', 'UpdateDescription' => 'Record created', 'UpdateDateTime' => '/Date(1763625600000+0300)/', 'UpdateLocation' => 'Riyadh, Saudi Arabia'],
                    ['WaybillNumber' => '44120099881', 'UpdateCode' => 'SH005', 'UpdateDescription' => 'Delivered', 'UpdateDateTime' => '/Date(1763712000000+0300)/', 'UpdateLocation' => ''],
                    ['WaybillNumber' => '44120099881', 'UpdateCode' => 'SH003', 'UpdateDateTime' => 'yesterday'],
                ],
            ]],
        ])]);

        $updates = $this->courier()->track(['44120099881', '999']);

        $this->assertCount(2, $updates);
        $this->assertSame(['SH014', 'Record created', 'Riyadh, Saudi Arabia', '2025-11-20T08:00:00Z'], [$updates[0]->code, $updates[0]->description, $updates[0]->location, $updates[0]->occurredAt->toIso8601ZuluString()]);
        $this->assertSame(['SH005', null, '2025-11-21T08:00:00Z'], [$updates[1]->code, $updates[1]->location, $updates[1]->occurredAt->toIso8601ZuluString()]);
        $this->assertNotSame($updates[0]->key(), $updates[1]->key());
        Http::assertSent(fn (Request $request) => $request->data()['Shipments'] === ['44120099881', '999'] && $request->data()['GetLastTrackingUpdateOnly'] === false);
        $this->assertSame([], $this->courier()->track([]));

        // An answer without results is no answer.
        Http::fake(['https://ws.aramex.net/*' => Http::response(['HasErrors' => true, 'Notifications' => [['Code' => 'ERR', 'Message' => 'Down']]])]);
        $this->expectException(CourierUnavailable::class);
        $this->courier('https://ws.aramex.net')->track(['44120099881']);
    }

    #[Test]
    public function the_driver_is_picked_by_configuration_and_needs_its_credentials(): void
    {
        $this->assertInstanceOf(ManualCourier::class, app(CourierGateway::class));

        config(['services.courier.driver' => 'fake']);
        $this->app->forgetInstance(CourierGateway::class);
        $this->assertInstanceOf(FakeCourier::class, app(CourierGateway::class));

        config(['services.courier.driver' => 'aramex']);
        $this->app->forgetInstance(CourierGateway::class);

        try {
            app(CourierGateway::class);
            $this->fail('Aramex without credentials.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('ARAMEX_USERNAME', $e->getMessage());
        }

        config(['services.courier.aramex' => $this->account()]);
        $this->app->forgetInstance(CourierGateway::class);
        $this->assertSame('Aramex', app(CourierGateway::class)->name());

        $this->expectException(LogicException::class);
        new FakeCourier(production: true);
    }

    private function courier(string $base = self::BASE): AramexCourier
    {
        return new AramexCourier($base, $this->account(), config('groupbuy.couriers.shipper'), 5);
    }

    /**
     * @return array{username: string, password: string, account_number: string, account_pin: string, account_entity: string, account_country_code: string, version: string, product_group: string, product_type: string}
     */
    private function account(): array
    {
        return [
            'base_url' => self::BASE,
            'username' => 'api@example.sa',
            'password' => 'secret',
            'account_number' => '20016',
            'account_pin' => '331421',
            'account_entity' => 'RUH',
            'account_country_code' => 'SA',
            'version' => 'v1.0',
            'product_group' => 'DOM',
            'product_type' => 'ONP',
        ];
    }

    private function request(): BookingRequest
    {
        return new BookingRequest(
            reference: 'ref-123',
            orderNumber: 'ORD-2611-000001',
            consignee: [
                'name' => 'Noura Ahmed',
                'phone' => '+966512345678',
                'city' => 'Riyadh',
                'district' => 'Al Olaya',
                'street' => 'King Fahd Road',
                'building_no' => '1234',
                'postal_code' => '12214',
                'short_address' => 'RRRD2929',
            ],
            pieces: 1,
            weightGrams: 2400,
            description: '2 x Desk lamp',
        );
    }
}
