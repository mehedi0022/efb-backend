<?php

namespace App\Services;

use FacebookAds\Api;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Illuminate\Support\Facades\Log;

class FacebookCAPIService
{
    private string $pixelId;
    private string $accessToken;
    private ?string $testEventCode;

    public function __construct()
    {
        $this->pixelId       = config('services.facebook.pixel_id', '');
        $this->accessToken   = config('services.facebook.access_token', '');
        $this->testEventCode = config('services.facebook.test_event_code') ?: null;
    }

    public function sendPurchaseEvent(array $data): bool
    {
        if (!$this->pixelId || !$this->accessToken) {
            Log::warning('FacebookCAPI: missing pixel_id or access_token');
            return false;
        }

        try {
            Api::init(null, null, $this->accessToken);

            // --- User Data ---
            $userData = (new UserData())
                ->setClientIpAddress($data['ip'] ?? '')
                ->setClientUserAgent($data['user_agent'] ?? '');

            if (!empty($data['fbp'])) {
                $userData->setFbp($data['fbp']);
            }
            if (!empty($data['fbc'])) {
                $userData->setFbc($data['fbc']);
            }
            // হ্যাশ করে পাঠানো হবে — match rate বাড়ায়
            if (!empty($data['email'])) {
                $userData->setEmail($data['email']);
            }
            if (!empty($data['phone'])) {
                // শুধু digits রাখুন
                $userData->setPhone(preg_replace('/\D/', '', $data['phone']));
            }

            // --- Custom Data ---
            $customData = (new CustomData())
                ->setValue((float) ($data['value'] ?? 0))
                ->setCurrency(strtoupper($data['currency'] ?? 'BDT'));

            // Product IDs
            if (!empty($data['item_ids']) && is_array($data['item_ids'])) {
                $contents = array_map(function ($id) {
                    return (new Content())
                        ->setProductId((string) $id)
                        ->setQuantity(1);
                }, $data['item_ids']);

                $customData->setContents($contents);
                $customData->setContentType('product');
            }

            if (!empty($data['quantity'])) {
                $customData->setNumItems((int) $data['quantity']);
            }

            if (!empty($data['order_id'])) {
                $customData->setOrderId((string) $data['order_id']);
            }

            // --- Event ---
            $event = (new Event())
                ->setEventName('Purchase')
                ->setEventTime(time())
                ->setEventSourceUrl($data['url'] ?? '')
                ->setActionSource('website')
                ->setUserData($userData)
                ->setCustomData($customData);

            // Deduplication — browser এর সাথে একই event_id
            if (!empty($data['event_id'])) {
                $event->setEventId((string) $data['event_id']);
            }

            // --- Request ---
            $request = (new EventRequest($this->pixelId))
                ->setEvents([$event]);

            // Test mode — শুধু testing এর সময়
            if ($this->testEventCode) {
                $request->setTestEventCode($this->testEventCode);
            }

            $request->execute();

            Log::info('FacebookCAPI: Purchase sent', [
                'order_id' => $data['order_id'] ?? null,
                'event_id' => $data['event_id'] ?? null,
                'value'    => $data['value'] ?? null,
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('FacebookCAPI: Purchase failed', [
                'error'    => $e->getMessage(),
                'order_id' => $data['order_id'] ?? null,
            ]);
            return false;
        }
    }
}