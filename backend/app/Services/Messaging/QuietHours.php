<?php

namespace App\Services\Messaging;

use App\Support\WeekDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Nothing automatic goes out between quiet_start and quiet_end (default 22:00–07:00, Asia/Bahrain),
 * nor on a quiet day; a message due then is moved to the next quiet_end on an allowed day.
 */
class QuietHours
{
    public function __construct(private MessagingRules $rules) {}

    public function isQuiet(CarbonInterface $at): bool
    {
        return ! $this->nextAllowed($at)->equalTo(Carbon::instance($at)->utc());
    }

    /** The first moment at or after $at when sending is allowed (returned in UTC). */
    public function nextAllowed(CarbonInterface $at): Carbon
    {
        $tz = $this->rules->timezone();
        $local = Carbon::instance($at)->setTimezone($tz);
        [$start, $end] = [$this->minutes((string) $this->rules->get('messaging.quiet_start')), $this->minutes((string) $this->rules->get('messaging.quiet_end'))];
        $endText = sprintf('%02d:%02d', intdiv($end, 60), $end % 60);
        $quietDays = array_map('strval', (array) $this->rules->get('messaging.quiet_days'));

        for ($i = 0; $i < 10; $i++) {
            if (in_array(WeekDays::keyFor($local), $quietDays, true)) {
                $local = $local->copy()->addDay()->setTimeFromTimeString($endText);

                continue;
            }

            $t = $local->hour * 60 + $local->minute;
            if ($start > $end) { // overnight window, e.g. 22:00–07:00
                if ($t >= $start) {
                    $local = $local->copy()->addDay()->setTimeFromTimeString($endText);

                    continue;
                }
                if ($t < $end) {
                    $local = $local->copy()->setTimeFromTimeString($endText);

                    continue;
                }
            } elseif ($start < $end && $t >= $start && $t < $end) {
                $local = $local->copy()->setTimeFromTimeString($endText);

                continue;
            }

            break;
        }

        return $local->utc();
    }

    private function minutes(string $hhmm): int
    {
        $parts = array_map('intval', explode(':', WeekDays::time($hhmm ?: '00:00')));

        return ($parts[0] % 24) * 60 + $parts[1];
    }
}
