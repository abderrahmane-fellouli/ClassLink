<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * F-PAR-01 / F-PAR-04 (Must) / RG-17.
 * Ne contient jamais `email`.
 */
class PartnerProfileResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'opt_in' => (bool) $this->opt_in,
            'skills' => $this->skills ?? [],
            'availability' => $this->availability ?? [],
        ];
    }
}
