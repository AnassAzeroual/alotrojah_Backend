<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $digits = preg_replace('/\D/', '', (string) $this->recipient_phone);

        return [
            'id' => $this->id, 'recipient_phone' => $this->recipient_phone,
            'wa_link' => 'https://wa.me/'.$digits.'?text='.urlencode((string) $this->message),
            'channel' => $this->channel, 'status' => $this->status,
            'sent_at' => $this->sent_at?->toDateTimeString(),
        ];
    }
}
