<?php

namespace App\Interfaces\Http\Resources;

use App\Interfaces\Http\Resources\CustomerResource;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Domain\Vehicule\Entites\VehiculeEntity;

/**
 * @property-read VehiculeEntity $resource
 */
class VehiculeResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var VehiculeEntity $vehicule */
        $vehicule = $this->resource;

        return [
            'id'          => $vehicule->getId(),
            'name'        => $vehicule->getName(),
            'plate'       => $vehicule->getPlate(),
            'model'       => $vehicule->getModel(),
            'brand'       => $vehicule->getBrand(),
            'years'       => $vehicule->getYears(),
            'status'      => (bool) $vehicule->getStatus(),
            'customer_id' => $vehicule->getCustomerId(),
            'created_at'  => $vehicule->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at'  => $vehicule->getModifiedDate()?->format('Y-m-d H:i:s'),
            'customer'    => $vehicule->getCustomer() ? CustomerResource::make($vehicule->getCustomer()) : null,
        ];
    }
}
