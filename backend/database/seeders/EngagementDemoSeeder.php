<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\Competition;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\Student;
use App\Models\User;
use App\Services\Engagement\ChallengeService;
use App\Services\Engagement\CompetitionService;
use App\Services\Engagement\HonorService;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Demo only (called from DemoSeeder; same local/testing + log-only rule): this month's honor boards for both tracks
 * (published), one boys' competition in judging with scores, one girls' competition open for registration,
 * and one challenge per track with participants. Idempotent.
 */
class EngagementDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Same rule as DemoSeeder when run on its own with --class=EngagementDemoSeeder.
        if ($reason = DemoSeeder::refusal()) {
            $this->command?->error("EngagementDemoSeeder refused: {$reason}");

            return;
        }
        $honor = app(HonorService::class);
        $competitions = app(CompetitionService::class);
        $challenges = app(ChallengeService::class);
        app(SettingsService::class)->set('honor.display_key', 'demo-tv-2026', 'honor', 'string');

        $period = HonorService::currentPeriod();
        foreach (HonorService::GENDERS as $g) {
            $honor->compute($period, $g)->update(['published_to_students' => true]);
        }

        $winner = Badge::where('key', 'competition_winner')->value('id');
        $champion = Badge::where('key', 'challenge_champion')->value('id');
        $boysHall = Location::where('code', 'B01')->value('id');
        $girlsHall = Location::where('code', 'G01')->value('id');
        $admin = User::where('phone', '+97336000001')->first();
        $maleJudge = User::where('phone', '+97336000003')->first();
        $maleJudge2 = User::where('phone', '+97336000008')->first();
        $femaleJudge = User::where('phone', '+97336000007')->first();

        // Boys: Juz Amma memorization, registration closed, first round judged.
        $boys = Competition::updateOrCreate(['name_ar' => 'مسابقة جزء عمّ للبنين'], [
            'name_en' => 'Juz Amma contest — boys', 'description' => 'حفظ جزء عمّ كاملًا مع أحكام التجويد.',
            'gender' => 'male', 'type' => 'memorization', 'scope' => 'authority', 'min_age' => 7, 'max_age' => 14,
            'registration_opens_at' => now()->subDays(20), 'registration_closes_at' => now()->subDays(6),
            'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(10), 'status' => 'judging',
            'criteria' => CompetitionService::DEFAULT_CRITERIA, 'tie_break' => 'last_round', 'created_by' => $admin?->id,
        ]);
        if ($boys->rounds()->doesntExist()) {
            $boys->rounds()->create(['name' => 'التصفيات', 'round_date' => today()->subDays(3)->toDateString(), 'start_time' => '16:30', 'location_id' => $boysHall, 'sort_order' => 1]);
            $boys->rounds()->create(['name' => 'النهائي', 'round_date' => today()->addDays(7)->toDateString(), 'start_time' => '17:00', 'location_id' => $boysHall, 'sort_order' => 2]);
            foreach ([[1, 'المركز الأول', 30], [2, 'المركز الثاني', 20], [3, 'المركز الثالث', 10]] as [$rank, $title, $pts]) {
                $boys->prizes()->create(['rank' => $rank, 'title' => $title, 'points' => $pts, 'badge_id' => $winner]);
            }
        }
        foreach (array_filter([$maleJudge, $maleJudge2]) as $j) {
            $competitions->addJudge($boys, $j);
        }
        $round = $boys->rounds()->orderBy('sort_order')->first();
        $boyIds = LessonStudent::where('status', 'active')->whereHas('student', fn ($q) => $q->where('gender', 'male')->where('status', 'active'))->pluck('student_id')->unique();
        foreach (Student::whereIn('id', $boyIds)->orderBy('student_no')->get() as $i => $s) {
            if (! $competitions->eligibility($boys, $s)['eligible']) {
                continue;
            }
            $p = $competitions->register($boys, $s, $admin, ignoreWindow: true);
            foreach (array_filter([$maleJudge, $maleJudge2]) as $k => $j) {
                $base = 10 - (($i + $k) % 5);
                $competitions->score($round, $p, $j, ['accuracy' => $base, 'tajweed' => max(5, $base - 1), 'voice' => max(5, $base - ($i % 3)), 'rules' => 10]);
            }
        }

        // Girls: tajweed recitation, open for registration now.
        $girls = Competition::updateOrCreate(['name_ar' => 'مسابقة التلاوة المجوّدة للبنات'], [
            'name_en' => 'Tajweed recitation — girls', 'description' => 'تلاوة مقطع من سورة الملك بأحكام التجويد.',
            'gender' => 'female', 'type' => 'tajweed', 'scope' => 'authority', 'min_age' => 7, 'max_age' => 14,
            'registration_opens_at' => now()->subDays(2), 'registration_closes_at' => now()->addDays(8),
            'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12), 'status' => 'open',
            'criteria' => CompetitionService::DEFAULT_CRITERIA, 'tie_break' => 'age_younger', 'created_by' => $admin?->id,
        ]);
        if ($girls->rounds()->doesntExist()) {
            $girls->rounds()->create(['name' => 'الجولة الأولى', 'round_date' => today()->addDays(10)->toDateString(), 'start_time' => '16:00', 'location_id' => $girlsHall, 'sort_order' => 1]);
            $girls->prizes()->create(['rank' => 1, 'title' => 'المركز الأول', 'points' => 30, 'badge_id' => $winner]);
            $girls->prizes()->create(['rank' => 2, 'title' => 'المركز الثاني', 'points' => 20]);
        }
        if ($femaleJudge) {
            $competitions->addJudge($girls, $femaleJudge);
        }

        // Challenges: one per track, measured from the demo attendance and ledger.
        foreach (['male' => 'تحدي الحضور المتميز', 'female' => 'تحدي حفظ سورة النبأ'] as $gender => $name) {
            $c = Challenge::updateOrCreate(['name_ar' => $name], $gender === 'male'
                ? ['name_en' => 'Attendance star', 'gender' => 'male', 'scope' => 'authority', 'goal_type' => 'attendance_days', 'goal_value' => 8,
                    'starts_at' => today()->subDays(21)->toDateString(), 'ends_at' => today()->addDays(9)->toDateString(), 'status' => 'active', 'reward_points' => 10, 'reward_badge_id' => $champion, 'created_by' => $admin?->id]
                : ['name_en' => 'Memorize an-Naba', 'gender' => 'female', 'scope' => 'authority', 'goal_type' => 'memorize_range', 'goal_value' => 40, 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 40,
                    'starts_at' => today()->subDays(21)->toDateString(), 'ends_at' => today()->addDays(20)->toDateString(), 'status' => 'active', 'reward_points' => 15, 'reward_badge_id' => $champion, 'created_by' => $admin?->id]);
            $ids = LessonStudent::where('status', 'active')->whereHas('student', fn ($q) => $q->where('gender', $gender)->where('status', 'active'))->pluck('student_id')->unique();
            foreach (Student::whereIn('id', $ids)->get() as $s) {
                if ($challenges->eligibility($c, $s)['eligible']) {
                    $challenges->join($c, $s, $admin);
                }
            }
        }
    }
}
