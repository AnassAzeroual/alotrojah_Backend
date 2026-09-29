<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'audience' => $this->audience, 'group_id' => $this->group_id,
            'title' => $this->title, 'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id, 'full_name' => $this->author->full_name,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
