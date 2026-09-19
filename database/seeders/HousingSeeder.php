<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\RepairReport;
use App\Models\Trade;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HousingSeeder extends Seeder
{
    protected array $trades = [
        ['Plumbing', 'Leaks, burst pipes, taps, toilets, drainage, water ingress from plumbing', 24],
        ['Heating', 'Boilers, radiators, hot water, gas appliances, heating controls', 18],
        ['Electrical', 'Circuits, sockets, lighting, consumer units, extractor fans, smoke alarms', 16],
        ['Roofing', 'Roof coverings, gutters, chimneys, water entering from above', 9],
        ['Joinery', 'Doors, windows, floors, stairs, bannisters, kitchen units, locks', 21],
        ['Damp & mould', 'Condensation, black mould, penetrating and rising damp, ventilation', 7],
        ['Groundworks', 'Paths, boundary walls, drainage outside the building, fencing', 6],
        ['Surveyor', 'Needs inspecting before any trade is sent — cause unclear or structural', 4],
    ];

    /**
     * Written the way tenants actually write.
     *
     * The interesting ones are deliberately mismatched: a burst described as
     * "a bit of a drip", a dripping tap reported in capitals as an emergency.
     * Keyword rules get both backwards, which is the entire argument.
     */
    protected array $reports = [
        // Understated but serious
        ['kitchen', 'bit of a drip coming through the ceiling under the bathroom, put a bucket under it for now'],
        ['hallway', 'the light keeps flickering and theres a burning smell when i turn it on, probably just the bulb'],
        ['bedroom', 'small damp patch in the corner, its been there a while, black bits on the wallpaper now'],
        ['bathroom', 'water coming up through the floor when the washing machine drains, only a little bit'],
        ['living room', 'ceiling has a bulge in it and feels soft, nothing has come down yet'],
        ['kitchen', 'gas hob makes a popping noise and theres a smell sometimes, we open the window'],
        // Overstated but minor
        ['bathroom', 'EMERGENCY!!! tap in the bathroom will not stop dripping, this is urgent please send someone TODAY'],
        ['bedroom', 'URGENT the handle on the wardrobe door has come off, need this fixed immediately'],
        ['kitchen', 'this is an absolute disgrace, the cupboard door is not closing properly and no one has come out'],
        // Genuine emergencies
        ['bathroom', 'pipe has burst under the sink, water going everywhere, cant turn it off'],
        ['living room', 'no heating and no hot water since yesterday, elderly mother lives here and its freezing'],
        ['kitchen', 'water pouring through the light fitting from the flat above, ive turned the electric off at the box'],
        ['hallway', 'front door lock has broken and the door will not shut properly, cant secure the flat tonight'],
        ['bedroom', 'part of the ceiling has come down in the night, plaster all over the bed'],
        ['bathroom', 'sewage coming back up through the shower drain, whole flat smells'],
        // Genuinely urgent
        ['living room', 'boiler making a loud banging noise and the radiators are cold'],
        ['kitchen', 'extractor fan stopped working and the kitchen fills with steam, windows running with water'],
        ['bathroom', 'toilet will not flush at all, its the only toilet in the flat'],
        ['bedroom', 'window will not close and the catch has snapped, rain is coming in'],
        ['hallway', 'smoke alarm keeps going off for no reason, ive taken the battery out'],
        ['living room', 'radiator leaking from the bottom, put a towel down but its still going'],
        // Qualifying
        ['bathroom', 'extractor fan in the bathroom stopped working about a week ago'],
        ['kitchen', 'one of the sockets by the worktop has stopped working'],
        ['hallway', 'light in the hall not working, changed the bulb and still nothing'],
        ['bedroom', 'radiator in the back bedroom is cold at the bottom, rest of the house is fine'],
        ['bathroom', 'toilet seat is cracked and wobbling'],
        // Routine
        ['living room', 'skirting board has come away from the wall by the window'],
        ['kitchen', 'cupboard door hinge has gone, door hangs at an angle'],
        ['hallway', 'carpet gripper coming up at the edge of the stairs'],
        ['bedroom', 'small crack in the plaster above the door, been there since we moved in'],
        ['bathroom', 'silicone round the bath has gone mouldy and needs redoing'],
        ['living room', 'curtain rail has come out of the wall on one side'],
        ['kitchen', 'tap is stiff to turn, been getting worse'],
        // Ambiguous — the ones worth a person
        ['bedroom', 'condensation on the windows every morning and the curtains are going mouldy'],
        ['living room', 'theres a damp smell but i cant see where its coming from'],
        ['hallway', 'crack appeared above the door frame and seems bigger than last month'],
        ['kitchen', 'floor feels springy in front of the sink'],
        ['bathroom', 'brown stain spreading on the ceiling, upstairs neighbour says they have no leak'],
        ['bedroom', 'draught coming from somewhere near the window, room never gets warm'],
        // Access
        ['kitchen', 'boiler needs looking at but i work shifts and can only do saturdays'],
        ['bathroom', 'leak under the sink, please ring first as i have a dog in the house'],
        ['living room', 'radiator not heating, no one home during the day, key is with my neighbour'],
    ];

    public function run(): void
    {
        foreach ($this->trades as [$name, $description, $operatives]) {
            Trade::create(['name' => $name, 'description' => $description, 'operatives' => $operatives]);
        }

        $this->seedProperties();
        $this->seedReports();
    }

    /** 50,000 homes, inserted in chunks rather than 50,000 model saves. */
    protected function seedProperties(): void
    {
        $archetypes = ['Tenement flat', 'Low-rise block', 'Tower block', 'Semi-detached', 'Terraced', 'Bungalow', 'Four-in-a-block'];
        $blocks = ['Wester Hailes', 'Craigmillar', 'Muirhouse', 'Sighthill', 'Gracemount', 'Pilton', 'Niddrie', 'Broomhouse', 'Oxgangs', 'Granton'];
        $streets = ['Loanhead', 'Kirkgate', 'Calder', 'Ferry', 'Harvesters', 'Clovenstone', 'Murrayburn', 'Hailesland', 'Dumbryden', 'Westburn'];

        $rows = [];

        for ($i = 1; $i <= 50_000; $i++) {
            $block = $blocks[$i % count($blocks)];
            $built = 1890 + ($i * 7) % 130;

            $rows[] = [
                'uprn' => '9060'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'address' => sprintf('%d/%d %s %s', ($i % 9) + 1, ($i % 60) + 1, $streets[$i % count($streets)], ['Road', 'Street', 'Gardens', 'Crescent', 'Place'][$i % 5]),
                'postcode' => sprintf('EH%d %dL%s', ($i % 17) + 1, ($i % 9) + 1, chr(65 + $i % 26)),
                'block' => $block,
                'archetype' => $archetypes[$i % count($archetypes)],
                'built' => $built,
                // Pre-1945 stock in the older blocks carries most of the damp
                // history, which is roughly how it sits in the real world.
                'damp_history' => $built < 1945 && $i % 3 === 0,
                'household_vulnerable' => $i % 11 === 0,
                'floor' => $i % 9,
            ];

            if (count($rows) === 2_000) {
                DB::table('properties')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('properties')->insert($rows);
        }
    }

    /**
     * 40 reports, roughly one per written situation.
     *
     * Enough to show the whole range in one screen and cheap enough to re-run
     * against the live API while tuning. Each report is a template plus the way
     * a different person would have phoned it in, so no two rows read alike.
     */
    protected array $openers = [
        '', '', '', 'hi, ', 'hello ', 'reporting again - ', 'sorry to bother you but ',
        'not sure who to tell about this but ', 'second time asking about this, ',
    ];

    protected array $details = [
        '', '', '',
        ' started on tuesday',
        ' been like this a few days now',
        ' happened overnight',
        ' getting worse since the weekend',
        ' my neighbour has the same problem',
        ' i have tried turning it off at the mains',
        ' there are two young children in the house',
        ' i am 78 and cannot manage this myself',
        ' please ring before you come round',
        ' i work shifts so evenings are better',
        ' last person out said it was fixed',
        ' i have photos if you need them',
    ];

    protected function seedReports(): void
    {
        $channels = ['phone', 'portal', 'email', 'phone', 'portal'];
        $propertyIds = Property::query()->inRandomOrder()->limit(40)->pluck('id')->all();

        foreach ($propertyIds as $index => $propertyId) {
            [$room, $summary] = $this->reports[$index % count($this->reports)];

            $opener = $this->openers[($index * 5) % count($this->openers)];
            $detail = $this->details[($index * 7) % count($this->details)];

            RepairReport::create([
                'property_id' => $propertyId,
                'channel' => $channels[$index % count($channels)],
                'room' => $room,
                'summary' => $opener.$summary.$detail,
                'reported_at' => now()->subMinutes((int) ($index * 4320 / 40) + ($index * 13) % 90),
                'status' => 'open',
            ]);
        }
    }
}
