<?php

namespace App\Services\Commerce\Data;

final readonly class RemoteLocation
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active = true,
        public bool $fulfillsOnlineOrders = true,
    ) {}

    /** @return array{id:string,name:string,active:bool,fulfills_online_orders:bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active' => $this->active,
            'fulfills_online_orders' => $this->fulfillsOnlineOrders,
        ];
    }
}
