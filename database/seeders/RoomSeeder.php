<?php

/**
 * RoomSeeder
 *
 * Seeds 30 rooms across Main Building and Annex Building
 * with varying capacities and room types.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Room;

class RoomSeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        $rooms = [];

        // Lecture halls (6 rooms, larger capacity)
        for ($i = 1; $i <= 6; $i++) {
            $rooms[] = [
                'code'       => 'LH' . sprintf('%02d', $i),
                'name'       => 'Lecture Hall ' . sprintf('%02d', $i),
                'capacity'   => 200 + ($i * 30),
                'building'   => 'Main Building',
                'room_type'  => 'lecture',
                'floor'      => $i,
            ];
        }

        // Standard classrooms (12 rooms, medium capacity)
        for ($i = 1; $i <= 12; $i++) {
            $rooms[] = [
                'code'       => 'CLA' . sprintf('%02d', $i),
                'name'       => 'Classroom ' . sprintf('%02d', $i),
                'capacity'   => 40 + ($i * 2),
                'building'   => 'Main Building',
                'room_type'  => 'classroom',
                'floor'      => ceil($i / 3),
            ];
        }

        // Small rooms (12 rooms, smaller capacity)
        for ($i = 1; $i <= 12; $i++) {
            // Only take first 12 to get exactly 30 total (6 + 12 + 12 = 30)
            if ($i <= 12) {
                $rooms[] = [
                    'code'       => 'SM' . sprintf('%02d', $i),
                    'name'       => 'Small Room ' . sprintf('%02d', $i),
                    'capacity'   => 20 + $i,
                    'building'   => 'Annex Building',
                    'room_type'  => 'small',
                    'floor'      => $i,
                ];
            }
        }

        // Trim to exactly 30 rooms
        $rooms = array_slice($rooms, 0, 30);

        foreach ($rooms as $room) {
            Room::firstOrCreate(
                ['code' => $room['code']],
                $room
            );
        }

        echo "30 rooms seeded successfully (Main Building + Annex Building)\n";
    }
}