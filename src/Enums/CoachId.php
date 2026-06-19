<?php

namespace Sisly\Coach\Enums;

enum CoachId: string
{
    case Meetly  = 'meetly';
    case Presso  = 'presso';
    case Loopy   = 'loopy';
    case Boostly = 'boostly';
    case Vento   = 'vento';

    /**
     * Human-readable name for the coach.
     */
    public function label(): string
    {
        return match($this) {
            self::Meetly  => 'Meetly',
            self::Presso  => 'Presso',
            self::Loopy   => 'Loopy',
            self::Boostly => 'Boostly',
            self::Vento   => 'Vento',
        };
    }

    /**
     * Coach emoji icon — locked per product spec.
     */
    public function emoji(): string
    {
        return match($this) {
            self::Meetly  => '📅',
            self::Presso  => '⏳',
            self::Loopy   => '🧠',
            self::Boostly => '⚡',
            self::Vento   => '💬',
        };
    }

    /**
     * Coach hex colour — locked per brand spec.
     */
    public function color(): string
    {
        return match($this) {
            self::Meetly  => '#FF9F35',
            self::Presso  => '#C078F0',
            self::Loopy   => '#5B9CF0',
            self::Boostly => '#3DBF55',
            self::Vento   => '#F08090',
        };
    }

    /**
     * Short speciality description shown in the coach picker.
     */
    public function spec(string $locale = 'en'): string
    {
        return match($locale) {
            'ar' => match($this) {
                self::Meetly  => 'هدوء قبل الاجتماع',
                self::Presso  => 'حين يصبح كل شيء كثيراً',
                self::Loopy   => 'حين لا يهدأ عقلك',
                self::Boostly => 'حين تنخفض طاقتك',
                self::Vento   => 'حين تحتاج للتنفيس',
            },
            default => match($this) {
                self::Meetly  => 'Calm before a meeting',
                self::Presso  => 'When it\'s all too much',
                self::Loopy   => 'Can\'t switch off',
                self::Boostly => 'When energy dips',
                self::Vento   => 'When you need to vent',
            },
        };
    }

    /**
     * Phase-1 primed opening — shown client-side with NO model call.
     * Arabic strings are placeholder until native GCC copywriter authors them.
     */
    public function primedOpening(string $locale = 'en'): string
    {
        return match($locale) {
            'ar' => match($this) {
                self::Meetly  => "مرحباً، أنا ميتلي. اجتماع مهم يشغل بالك؟ لنهدّئ الأمور. هل هو قادم أم انتهى للتو؟",
                self::Presso  => "أهلاً، أنا بريسو. حين يتراكم كل شيء دفعة واحدة، يساعد أن نتمهّل. ما الذي يتراكم عليك؟",
                self::Loopy   => "مرحباً، أنا لوبي. حين لا يتوقف العقل عن الدوران، نهدّئه معاً. ما الذي يدور في بالك؟",
                self::Boostly => "أهلاً، أنا بوستلي. طاقتك على وشك النفاد؟ لنجد لك دفعة صغيرة. ما الذي يستنزفك اليوم؟",
                self::Vento   => "مرحباً، أنا فينتو. أحياناً تحتاج فقط أن تُخرج ما بداخلك. أنا أسمعك بلا حكم. ماذا حدث؟",
            },
            default => match($this) {
                self::Meetly  => "Hi, I'm Meetly. Big meeting on your mind? Let's get you steady. Is it coming up, or did it just happen?",
                self::Presso  => "Hey, I'm Presso. When it's all too much at once, it helps to slow right down. What's piling up on you?",
                self::Loopy   => "Hi, I'm Loopy. When the mind won't stop spinning, we can slow it together. What keeps going round for you?",
                self::Boostly => "Hey, I'm Boostly. Running on empty? Let's find you a little lift. What's draining you today?",
                self::Vento   => "Hi, I'm Vento. Sometimes you just need to get it out. I'm listening, no judgement. What happened?",
            },
        };
    }

    /**
     * The content_type parameter sent to the Sisly content API.
     * Frozen — do not change without product sign-off.
     */
    public function contentTypeParam(): string
    {
        return match($this) {
            self::Meetly  => 'Meetings',
            self::Presso  => 'Too much',
            self::Loopy   => 'Quiet mind',
            self::Boostly => 'Confidence',
            self::Vento   => 'Let it out',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
