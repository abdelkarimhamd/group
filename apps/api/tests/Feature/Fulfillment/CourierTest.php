<?php

namespace Tests\Feature\Fulfillment;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\TrackingUpdate;
use App\Modules\Fulfillment\Jobs\BookShipment;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentEvent;
use App\Modules\Fulfillment\Services\CourierBookings;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Refunds;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FulfilsOrders;
use Tests\Fakes\ScriptedCourier;
use Tests\TestCase;

/**
 * Booking parcels through a courier's API and reading their tracking
 * (spec, E13; open question Q11), and parcels the courier brings back.
 */
class CourierTest extends TestCase
{
    use FulfilsOrders, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function without_a_courier_api_parcels_are_handed_over_by_hand(): void
    {
        [$order] = $this->readyOrders($this->campaign, [3]);

        $this->asAdmin('get', '/api/v1/admin/courier', role: AdminRole::Support)
            ->assertOk()
            ->assertExactJson(['data' => ['name' => null, 'books' => false, 'tracks' => false]]);
        $this->book($order)->assertStatus(409)->assertJsonPath('code', 'courier_api_off');
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 0')->assertSuccessful();

        // A link is built for a courier with a known pattern.
        $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'Aramex', 'tracking_number' => '4412 99'])
            ->assertCreated()
            ->assertJsonPath('data.tracking_url', 'https://www.aramex.com/sa/en/track/results?ShipmentNumber=4412%2099')
            ->assertJsonPath('data.booking', null)
            ->assertJsonPath('data.events.0.source', 'admin');
    }

    #[Test]
    public function a_booked_parcel_is_tracked_from_the_courier_until_delivered(): void
    {
        $sms = $this->fakeSms();
        $this->useCourier('fake');
        [$order] = $this->readyOrders($this->campaign, [3]);
        $phone = $order->user()->sole()->phone_e164;

        $this->asAdmin('get', '/api/v1/admin/courier')->assertOk()->assertJsonPath('data', ['name' => 'Fake courier', 'books' => true, 'tracks' => true]);
        $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment/booking", [], AdminRole::Support)->assertForbidden();

        // Asked for; the booking went out after the commit (the test queue is sync).
        $booked = $this->book($order, ['shipping_cost_halalas' => 2500])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.courier', 'Fake courier')
            ->assertJsonPath('data.tracking_number', null)
            ->assertJsonPath('data.shipped_at', null)
            ->assertJsonPath('data.booking.status', 'requested')
            ->json('data');
        $this->book($order)->assertStatus(409)->assertJsonPath('code', 'shipment_exists');

        $shipment = Shipment::query()->sole();
        $this->assertSame(['booked', $booked['booking']['reference'], 2500], [$shipment->booking_status->value, $shipment->booking_key, $shipment->shipping_cost_halalas]);
        $this->assertMatchesRegularExpression('/^FAKE\d{10}[0-9A-F]{4}$/', (string) $shipment->tracking_number);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk((string) config('groupbuy.media.documents_disk'))->get((string) $shipment->label_path));
        $this->asAdmin('get', "/api/v1/admin/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_to_ship')
            ->assertJsonPath('data.shipments.0.booking.status', 'booked')
            ->assertJsonPath('data.shipments.0.booking.label_url', fn (string $url) => str_contains($url, (string) $shipment->label_path))
            ->assertJsonPath('data.shipments.0.events', []);
        // Not collected yet: the customer sees no parcel.
        $this->asCustomer($order)->assertOk()->assertJsonPath('data.shipment', null);

        // Two hours on the courier collects it: the order is shipped.
        $this->travel(3)->hours();
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 1, new updates: 1')->assertSuccessful();
        $shipment->refresh();
        $this->assertSame(['picked_up', $shipment->booking_requested_at->addHours(2)->getTimestamp()], [$shipment->status->value, $shipment->shipped_at->getTimestamp()]);
        $this->assertSame('shipped', $order->refresh()->status->value);
        $this->assertSame("طلبك {$order->order_number} في الطريق مع Fake courier. رقم التتبع: {$shipment->tracking_number}.", last($sms->to($phone)));
        $this->asCustomer($order)->assertOk()->assertJsonPath('data.shipment.tracking_number', $shipment->tracking_number);

        // Reading again records nothing twice.
        $this->artisan('shipments:track')->expectsOutputToContain('new updates: 0')->assertSuccessful();

        // A day on, it is delivered: the return window starts from the courier's time.
        $this->travel(1)->days();
        $this->artisan('shipments:track')->expectsOutputToContain('new updates: 3')->assertSuccessful();
        $deliveredAt = $shipment->booking_requested_at->addHours(26);
        $order->refresh();
        $this->assertSame(['delivered', $deliveredAt->getTimestamp(), $deliveredAt->addDays(7)->getTimestamp()], [$order->status->value, $order->delivered_at->getTimestamp(), $order->return_window_ends_at->getTimestamp()]);
        $this->assertSame(
            [['picked_up', 'courier_api'], ['in_transit', 'courier_api'], ['out_for_delivery', 'courier_api'], ['delivered', 'courier_api']],
            ShipmentEvent::query()->orderBy('occurred_at')->get()->map(fn (ShipmentEvent $event) => [$event->status->value, $event->source])->all(),
        );
        $this->assertSame(
            [[null, 'pending'], ['requested', 'booked'], ['pending', 'picked_up'], ['picked_up', 'in_transit'], ['in_transit', 'out_for_delivery'], ['out_for_delivery', 'delivered']],
            StatusTransition::query()->where('subject_type', 'shipment')->orderBy('id')->get()->map(fn (StatusTransition $row) => [$row->from_status, $row->to_status])->all(),
        );
        // Delivered parcels are not read again; the campaign is complete.
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 0')->assertSuccessful();
        $this->assertSame('completed', $this->campaign->refresh()->status->value);
    }

    #[Test]
    public function a_refused_or_unclear_booking_cancels_the_shipment_and_is_never_resent(): void
    {
        $courier = $this->useScripted();
        $courier->onBook = 'refuse';
        [$order] = $this->readyOrders($this->campaign, [3]);

        $this->book($order)->assertStatus(202);
        $refused = Shipment::query()->sole();
        $this->assertSame(['cancelled', 'failed', 'Consignee city is not served (fake).'], [$refused->status->value, $refused->booking_status->value, $refused->booking_error]);
        $this->assertSame('ready_to_ship', $order->refresh()->status->value);
        $this->assertSame('booking_failed', StatusTransition::query()->where('subject_type', 'shipment')->latest('id')->value('reason'));

        // What the courier was asked for.
        $request = $courier->booked[0];
        $weight = 3 * ($order->variant()->sole()->weight_grams ?? (int) config('groupbuy.couriers.default_unit_weight_grams'));
        $this->assertSame([$refused->booking_key, $order->order_number, 1, $weight], [$request->reference, $request->orderNumber, $request->pieces, $request->weightGrams]);
        $this->assertSame($order->shipping_address['recipient_name'], $request->consignee['name']);

        // No clear answer: cancelled with the reference to look for, never sent again.
        $courier->onBook = 'quiet';
        $this->book($order)->assertStatus(202);
        $quiet = Shipment::query()->latest('id')->firstOrFail();
        $this->assertSame('cancelled', $quiet->status->value);
        $this->assertStringContainsString("Check its portal for reference {$quiet->booking_key}", (string) $quiet->booking_error);
        (new BookShipment($quiet->id))->handle(app(CourierBookings::class));
        $this->assertCount(2, $courier->booked);

        // The order still ships, by hand this time.
        $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'SMSA-1'])->assertCreated();
        $this->assertSame('shipped', $order->refresh()->status->value);
        $this->asAdmin('get', "/api/v1/admin/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonCount(3, 'data.shipments')
            ->assertJsonPath('data.shipments.0.courier', 'SMSA')
            ->assertJsonPath('data.shipments.2.booking.error', 'Consignee city is not served (fake).');
    }

    #[Test]
    public function a_booking_whose_job_was_lost_is_sent_again_only_if_it_never_went_out(): void
    {
        $courier = $this->useScripted();
        [$first, $second] = $this->readyOrders($this->campaign, [2, 1]);
        Bus::fake([BookShipment::class]);
        $this->book($first)->assertStatus(202);
        $this->book($second)->assertStatus(202);
        [$lost, $sent] = Shipment::query()->orderBy('id')->get()->all();
        // The second went out but its answer was never recorded.
        DB::table('shipments')->where('id', $sent->id)->update(['booking_sent_at' => now()]);

        $this->travel(10)->minutes();
        $this->artisan('shipments:track')->expectsOutputToContain('Bookings queued again: 0, cancelled for checking: 0')->assertSuccessful();
        $this->travel(25)->minutes();
        Bus::fake([BookShipment::class]);
        $this->artisan('shipments:track')->expectsOutputToContain('Bookings queued again: 1, cancelled for checking: 1')->assertSuccessful();
        Bus::assertDispatchedTimes(BookShipment::class, 1);
        Bus::assertDispatched(BookShipment::class, fn (BookShipment $job) => $job->shipmentId === $lost->id);
        $this->assertSame(['cancelled', 'failed'], [$sent->refresh()->status->value, $sent->booking_status->value]);
        $this->assertStringContainsString('No answer from the courier was recorded', (string) $sent->booking_error);

        // The requeued one goes out once; a second run of its job sends nothing.
        (new BookShipment($lost->id))->handle(app(CourierBookings::class));
        (new BookShipment($lost->id))->handle(app(CourierBookings::class));
        $this->assertCount(1, $courier->booked);
        $this->assertSame(['booked', 'SCR1'], [$lost->refresh()->booking_status->value, $lost->tracking_number]);
    }

    #[Test]
    public function only_a_parcel_the_courier_has_not_collected_is_called_off(): void
    {
        $this->useScripted();
        [$first, $second] = $this->readyOrders($this->campaign, [2, 1]);
        Bus::fake([BookShipment::class]);
        $shipment = $this->book($first)->assertStatus(202)->json('data.id');

        $this->cancel($shipment)->assertStatus(409)->assertJsonPath('code', 'booking_in_progress');
        $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment}/events", ['status' => 'picked_up', 'occurred_at' => now()->toIso8601ZuluString()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'booking_in_progress');
        (new BookShipment(Shipment::query()->where('uuid', $shipment)->value('id')))->handle(app(CourierBookings::class));

        $this->cancel($shipment, AdminRole::Support)->assertForbidden();
        $this->cancel($shipment, body: ['reason' => 'Customer asked to collect it'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('ready_to_ship', $first->refresh()->status->value);
        $this->assertSame('cancelled_by_admin', StatusTransition::query()->where('subject_type', 'shipment')->latest('id')->value('reason'));
        $this->cancel($shipment)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment}/events", ['status' => 'in_transit', 'occurred_at' => now()->toIso8601ZuluString()])->assertStatus(409);

        // Collected parcels are not called off.
        $handed = $this->asAdmin('post', "/api/v1/admin/orders/{$second->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'SMSA-2'])->json('data.id');
        $this->cancel($handed)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->cancel('00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    #[Test]
    public function a_parcel_the_courier_brings_back_is_ready_to_ship_again(): void
    {
        $sms = $this->fakeSms();
        $courier = $this->useScripted();
        [$order] = $this->readyOrders($this->campaign, [3]);
        $phone = $order->user()->sole()->phone_e164;
        $this->book($order)->assertStatus(202);
        $shipment = Shipment::query()->sole();

        // Tracking read from the courier; a quiet tracking service changes nothing.
        $courier->updates = [
            new TrackingUpdate('SCR1', 'CR', 'Record created', null, now()->subMinute()),
            new TrackingUpdate('SCR1', 'PU', 'Picked up', 'Riyadh', now()),
            new TrackingUpdate('SCR1', 'ZZ9', 'Customer not available', 'Riyadh', now()->addHours(5)),
        ];
        $courier->trackingDown = true;
        $this->travel(6)->hours();
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 0')->assertSuccessful();
        $courier->trackingDown = false;
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 1, new updates: 2')->assertSuccessful();
        // An unknown code after the pickup reads as in transit.
        $this->assertSame(['in_transit', 'shipped'], [$shipment->refresh()->status->value, $order->refresh()->status->value]);

        // The courier brought it back; staff record it.
        $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment->uuid}/events", ['status' => 'returned', 'description' => 'Refused at the door', 'occurred_at' => now()->toIso8601ZuluString()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'returned');
        $this->assertSame('ready_to_ship', $order->refresh()->status->value);
        $last = StatusTransition::query()->where('subject_type', 'customer_order')->where('subject_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(['shipped', 'ready_to_ship', 'returned_to_sender'], [$last->from_status, $last->to_status, $last->reason]);
        $this->assertSame("تعذر على شركة الشحن توصيل الطلب {$order->order_number} وأعادته إلينا. سنتواصل معك لإعادة توصيله.", last($sms->to($phone)));
        $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment->uuid}/events", ['status' => 'delivered', 'occurred_at' => now()->toIso8601ZuluString()])->assertStatus(409);
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 0')->assertSuccessful();

        // Shipped again; the customer follows the new parcel and is told again.
        $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'SMSA-9'])->assertCreated();
        $this->asCustomer($order)->assertOk()->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.shipment.tracking_number', 'SMSA-9');
        $this->assertStringContainsString('SMSA-9', last($sms->to($phone)));
        $this->assertSame(2, CustomerOrder::query()->whereKey($order->id)->firstOrFail()->shipments()->count());
    }

    #[Test]
    public function a_booking_sent_late_by_its_job_is_given_time_to_answer(): void
    {
        $this->useScripted();
        [$order] = $this->readyOrders($this->campaign, [3]);
        Bus::fake([BookShipment::class]);
        $this->book($order)->assertStatus(202);
        $shipment = Shipment::query()->sole();

        // The queue was slow: the job sent it just before the sweep came by.
        $this->travel(29)->minutes();
        DB::table('shipments')->where('id', $shipment->id)->update(['booking_sent_at' => now()]);
        $this->travel(2)->minutes();
        $this->artisan('shipments:track')->expectsOutputToContain('Bookings queued again: 0, cancelled for checking: 0')->assertSuccessful();
        $this->assertSame('requested', $shipment->refresh()->booking_status->value);

        // Still no answer long after any job could run.
        $this->travel(15)->minutes();
        $this->artisan('shipments:track')->expectsOutputToContain('Bookings queued again: 0, cancelled for checking: 1')->assertSuccessful();
        $this->assertSame(['cancelled', 'failed'], [$shipment->refresh()->status->value, $shipment->booking_status->value]);
        $this->assertSame(
            [[null, 'pending', 'booking_requested'], ['requested', 'failed', 'booking_failed'], ['pending', 'cancelled', 'booking_failed']],
            $this->shipmentHistory($shipment),
        );
    }

    #[Test]
    public function a_full_refund_waits_for_the_parcel_to_be_called_off(): void
    {
        $courier = $this->useScripted();
        [$order] = $this->readyOrders($this->campaign, [3]);
        Bus::fake([BookShipment::class]);
        $first = $this->book($order)->assertStatus(202)->json('data.id');

        // Refunding everything closes the order, so the parcel is called off first.
        $this->refund($order)->assertStatus(409)->assertJsonPath('code', 'shipment_open');
        (new BookShipment(Shipment::query()->where('uuid', $first)->value('id')))->handle(app(CourierBookings::class));
        $this->refund($order)->assertStatus(409)->assertJsonPath('code', 'shipment_open');
        $this->cancel($first, body: ['reason' => 'Customer cancelled'])->assertOk();
        $approval = $this->refund($order)->assertCreated()->json('data.approval_request_id');

        // Booked again before finance decided: the refund calls it off before it goes out.
        $second = Shipment::query()->where('uuid', $this->book($order)->assertStatus(202)->json('data.id'))->sole();
        $this->asAdmin('post', "/api/v1/admin/approval-requests/{$approval}/approve", role: AdminRole::Finance)->assertOk();
        $this->assertSame('cancelled', $order->refresh()->status->value);
        $second->refresh();
        $this->assertSame(['cancelled', 'failed'], [$second->status->value, $second->booking_status->value]);
        $this->assertStringContainsString('the order was refunded', (string) $second->booking_error);
        $this->assertSame(
            [[null, 'pending', 'booking_requested'], ['requested', 'failed', 'order_closed'], ['pending', 'cancelled', 'order_closed']],
            $this->shipmentHistory($second),
        );
        (new BookShipment($second->id))->handle(app(CourierBookings::class));
        $this->assertCount(1, $courier->booked);
    }

    #[Test]
    public function a_booking_answered_after_the_order_closed_is_called_off(): void
    {
        $courier = $this->useScripted();
        [$order, $other] = $this->readyOrders($this->campaign, [3, 1]);
        $approval = $this->refund($order)->assertCreated()->json('data.approval_request_id');
        Bus::fake([BookShipment::class]);
        $this->book($order)->assertStatus(202);
        $shipment = Shipment::query()->where('customer_order_id', $order->id)->sole();

        // Finance approves while the booking is out with the courier: the
        // refund leaves it, and its answer calls it off.
        $courier->whileBooking = function () use ($approval, $shipment): void {
            Log::spy();
            $this->asAdmin('post', "/api/v1/admin/approval-requests/{$approval}/approve", role: AdminRole::Finance)->assertOk();
            $this->assertSame('pending', $shipment->refresh()->status->value);
            Log::shouldHaveReceived('critical')->with('courier.booking_out_for_closed_order', \Mockery::any())->once();
        };
        (new BookShipment($shipment->id))->handle(app(CourierBookings::class));

        $this->assertSame('cancelled', $order->refresh()->status->value);
        $shipment->refresh();
        $this->assertSame(['cancelled', 'booked', 'SCR1'], [$shipment->status->value, $shipment->booking_status->value, $shipment->tracking_number]);
        $this->assertSame(
            [[null, 'pending', 'booking_requested'], ['requested', 'booked', 'booking_booked'], ['pending', 'cancelled', 'order_closed']],
            $this->shipmentHistory($shipment),
        );
        Log::shouldHaveReceived('critical')->with('courier.cancel_in_portal', \Mockery::any())->once();

        // A booking whose order closed some other way before its job ran is never sent.
        $this->book($other)->assertStatus(202);
        DB::table('customer_orders')->where('id', $other->id)->update(['status' => 'cancelled']);
        $waiting = Shipment::query()->where('customer_order_id', $other->id)->sole();
        (new BookShipment($waiting->id))->handle(app(CourierBookings::class));
        $this->assertCount(1, $courier->booked);
        $this->assertSame(['cancelled', 'failed'], [$waiting->refresh()->status->value, $waiting->booking_status->value]);
    }

    #[Test]
    public function a_parcel_brought_back_after_a_full_refund_closes_the_order(): void
    {
        [$order] = $this->readyOrders($this->campaign, [3]);
        $shipment = $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => 'SMSA-7'])->assertCreated()->json('data.id');

        // Refunded while on its way: the order stays with the courier.
        $approval = $this->refund($order)->assertCreated()->json('data.approval_request_id');
        $this->asAdmin('post', "/api/v1/admin/approval-requests/{$approval}/approve", role: AdminRole::Finance)->assertOk();
        $this->assertSame(['shipped', 'refunded'], [$order->refresh()->status->value, PaymentAuthorization::query()->whereKey($order->payment_authorization_id)->value('status')->value]);

        $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment}/events", ['status' => 'returned', 'occurred_at' => now()->toIso8601ZuluString()])->assertCreated();
        $last = StatusTransition::query()->where('subject_type', 'customer_order')->where('subject_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(['shipped', 'cancelled', 'returned_after_refund'], [$last->from_status, $last->to_status, $last->reason]);
        $this->assertSame('completed', $this->campaign->refresh()->status->value);
    }

    #[Test]
    public function a_scan_before_the_courier_collects_the_parcel_is_not_a_pickup(): void
    {
        $courier = $this->useScripted();
        [$order] = $this->readyOrders($this->campaign, [3]);
        $this->book($order)->assertStatus(202);
        $shipment = Shipment::query()->sole();

        $courier->updates = [new TrackingUpdate('SCR1', 'ZZ1', 'Label scanned at hub', 'Riyadh', now()->addMinutes(30))];
        $this->travel(1)->hours();
        $this->artisan('shipments:track')->expectsOutputToContain('Parcels read: 1, new updates: 0')->assertSuccessful();
        $this->assertSame(['pending', 'ready_to_ship'], [$shipment->refresh()->status->value, $order->refresh()->status->value]);

        // Once collected, the earlier scan is still not a movement.
        $courier->updates[] = new TrackingUpdate('SCR1', 'PU', 'Picked up', 'Riyadh', now());
        $this->travel(1)->hours();
        $this->artisan('shipments:track')->expectsOutputToContain('new updates: 1')->assertSuccessful();
        $this->artisan('shipments:track')->expectsOutputToContain('new updates: 0')->assertSuccessful();
        $this->assertSame(['picked_up', 'shipped'], [$shipment->refresh()->status->value, $order->refresh()->status->value]);
    }

    #[Test]
    public function a_courier_set_up_wrongly_does_not_stop_refunds(): void
    {
        config(['services.courier.driver' => 'aramex', 'services.courier.aramex' => []]);
        $this->app->forgetInstance(CourierGateway::class);

        $this->assertInstanceOf(Refunds::class, app(Refunds::class));
        [$order] = $this->readyOrders($this->campaign, [3]);
        $this->refund($order)->assertCreated();
    }

    private function useCourier(string $driver): void
    {
        config(['services.courier.driver' => $driver]);
        $this->app->forgetInstance(CourierGateway::class);
    }

    private function useScripted(): ScriptedCourier
    {
        config(['services.courier.driver' => 'fake']);
        $courier = new ScriptedCourier;
        $this->app->instance(CourierGateway::class, $courier);

        return $courier;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function book(CustomerOrder $order, array $body = []): TestResponse
    {
        return $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment/booking", $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function cancel(string $shipment, AdminRole $role = AdminRole::Operations, array $body = []): TestResponse
    {
        return $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment}/cancel", $body, $role);
    }

    private function refund(CustomerOrder $order, ?int $halalas = null): TestResponse
    {
        $payment = PaymentAuthorization::query()->whereKey($order->payment_authorization_id)->sole();

        return $this->asAdmin('post', '/api/v1/admin/refunds', [
            'payment_id' => $payment->uuid,
            'amount_halalas' => $halalas ?? $payment->captured_amount_halalas,
            'reason' => 'customer_cancellation',
        ], AdminRole::Support);
    }

    /**
     * @return list<array{string|null, string, string|null}>
     */
    private function shipmentHistory(Shipment $shipment): array
    {
        return StatusTransition::query()
            ->where('subject_type', 'shipment')
            ->where('subject_id', $shipment->id)
            ->orderBy('id')
            ->get()
            ->map(fn (StatusTransition $row) => [$row->from_status, $row->to_status, $row->reason])
            ->all();
    }
}
