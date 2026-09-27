<?php

namespace Database\Factories\Support;

/**
 * Demo and factory names: Bahraini Shia given and family names, stored in Arabic.
 * One list for factories and DemoSeeder so every screen shows the same community.
 */
final class BahrainiNames
{
    /** Hussain, Ali, Mohammed Baqir, Jaafar, Haidar, Abbas, Mahdi, Kadhim, Redha, Sadiq, Hadi, Jawad, Zain Al-Abideen, Murtadha, Sajjad, Ameer, Mujtaba, Hassan, Mohsen */
    public const MALE = ['حسين', 'علي', 'محمد باقر', 'جعفر', 'حيدر', 'عباس', 'مهدي', 'كاظم', 'رضا', 'صادق', 'هادي', 'جواد', 'زين العابدين', 'مرتضى', 'سجاد', 'أمير', 'مجتبى', 'حسن', 'محسن'];

    /** Zainab, Fatima, Zahra, Ruqayya, Sakina, Narjes, Masooma, Khadija, Batool, Hawra, Kawthar */
    public const FEMALE = ['زينب', 'فاطمة', 'زهراء', 'رقية', 'سكينة', 'نرجس', 'معصومة', 'خديجة', 'بتول', 'حوراء', 'كوثر'];

    /** Al-Mahroos, Al-Sitrawi, Al Abbas, Al-Samahiji, Al-Darazi, Al Saif, Jinahi, Al-Asfoor, Al-Marzooq, Al-Shaikh, Al-Haddad, Al-Mosawi, Al-Alawi, Al-Jamri, Al-Saffar, Al-Ekri, Radhi, Al-Basri */
    public const FAMILY = ['المحروس', 'الستراوي', 'آل عباس', 'السماهيجي', 'الدرازي', 'آل سيف', 'جناحي', 'العصفور', 'المرزوق', 'الشيخ', 'الحداد', 'الموسوي', 'العلوي', 'الجمري', 'الصفار', 'العكري', 'راضي', 'البصري'];

    /** Fathers (and so guardians) are always men. */
    public static function full(string $gender = 'male'): string
    {
        $first = fake()->randomElement($gender === 'female' ? self::FEMALE : self::MALE);

        return $first.' '.fake()->randomElement(self::MALE).' '.fake()->randomElement(self::FAMILY);
    }
}
