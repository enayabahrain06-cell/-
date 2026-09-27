<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Location> */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        static $i = 0;
        $i++;

        return [
            'name' => "قاعة {$i}",
            'code' => 'H'.fake()->unique()->numerify('###'),
            'address' => 'مركز أهل القرآن، سار',
            'map_link' => 'https://maps.google.com/?q=26.2,50.5',
            'capacity' => 20,
            'is_active' => true,
        ];
    }
}
