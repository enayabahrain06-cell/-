<?php

namespace App\Services\Lottery;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Pure allocation (no database): deterministic for a given seed.
 *
 * Input students: [id, age, level, family] ; slots: [key, capacity].
 * 1. Units: one per student, or one per family when keep_siblings (siblings share a guardian).
 * 2. Units are shuffled with the seeded RNG, then placed largest first (so sibling groups still fit).
 * 3. Each unit goes to the fitting slot with the lowest cost:
 *      fill ratio (load / capacity)  — spreads students evenly relative to capacity
 *    + same-age share   (when balance_ages)   — mixes ages across circles
 *    + same-level share (when balance_levels) — mixes memorization levels across circles
 *    Ties are broken with the seeded RNG. (Section 21 will add smart-placement factors to this cost.)
 * 4. A family that fits nowhere as a whole is split (reported); students that fit nowhere are unassigned.
 *
 * @return array{assignments: array<int, string|int>, unassigned: list<int>, split_families: list<string>}
 */
final class LotteryAllocator
{
    /**
     * @param  list<array{id:int, age:int, level:string, family:string}>  $students
     * @param  list<array{key:string|int, capacity:int}>  $slots
     */
    public function allocate(array $students, array $slots, string $seed, bool $keepSiblings, bool $balanceAges, bool $balanceLevels): array
    {
        $rng = new Randomizer(new Mt19937(crc32($seed)));

        $units = [];
        foreach ($students as $s) {
            $key = $keepSiblings && $s['family'] !== '' ? 'f:'.$s['family'] : 's:'.$s['id'];
            $units[$key][] = $s;
        }
        // Stable input order first (by key), then a seeded shuffle, then largest units first (stable sort keeps the shuffle).
        ksort($units);
        $units = $rng->shuffleArray(array_values($units));
        usort($units, fn ($a, $b) => count($b) <=> count($a));

        $state = [];
        foreach ($slots as $slot) {
            $state[(string) $slot['key']] = ['capacity' => max(0, (int) $slot['capacity']), 'load' => 0, 'ages' => [], 'levels' => []];
        }
        $slotOrder = $rng->shuffleArray(array_keys($state));

        $assignments = [];
        $unassigned = [];
        $split = [];

        $place = function (array $members) use (&$state, &$assignments, $slotOrder, $balanceAges, $balanceLevels, $rng): bool {
            $size = count($members);
            $best = null;
            $bestCost = INF;
            $ties = [];
            foreach ($slotOrder as $key) {
                $s = $state[$key];
                if ($s['capacity'] - $s['load'] < $size || $s['capacity'] === 0) {
                    continue;
                }
                $cost = $s['load'] / $s['capacity'];
                foreach ($members as $m) {
                    if ($balanceAges) {
                        $cost += ($s['ages'][$m['age']] ?? 0) / $s['capacity'];
                    }
                    if ($balanceLevels) {
                        $cost += ($s['levels'][$m['level']] ?? 0) / $s['capacity'];
                    }
                }
                if ($cost < $bestCost - 1e-9) {
                    $bestCost = $cost;
                    $ties = [$key];
                } elseif (abs($cost - $bestCost) <= 1e-9) {
                    $ties[] = $key;
                }
            }
            if ($ties === []) {
                return false;
            }
            // Equal-cost slots: pick one with the seeded RNG (keeps runs reproducible yet varied across seeds).
            $best = $ties[$rng->getInt(0, count($ties) - 1)];
            foreach ($members as $m) {
                $assignments[$m['id']] = $best;
                $state[$best]['load']++;
                $state[$best]['ages'][$m['age']] = ($state[$best]['ages'][$m['age']] ?? 0) + 1;
                $state[$best]['levels'][$m['level']] = ($state[$best]['levels'][$m['level']] ?? 0) + 1;
            }

            return true;
        };

        foreach ($units as $unit) {
            if ($place($unit)) {
                continue;
            }
            if (count($unit) > 1) {
                $split[] = $unit[0]['family'];
                foreach ($unit as $m) {
                    if (! $place([$m])) {
                        $unassigned[] = $m['id'];
                    }
                }
            } else {
                $unassigned[] = $unit[0]['id'];
            }
        }

        // Keys come back as strings from the state array; restore int keys where they were ints.
        $assignments = array_map(fn ($k) => ctype_digit((string) $k) ? (int) $k : $k, $assignments);

        return ['assignments' => $assignments, 'unassigned' => $unassigned, 'split_families' => $split];
    }
}
