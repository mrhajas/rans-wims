<?php

namespace App\Services\Prayer;

/**
 * The verses and sayings shown at prayer time (the starting set; the Super Admin can switch each one off, change it or add more
 * in Prayer Times > Messages). Qur'an verses carry the Arabic text; hadith are given in English with the collection they come from.
 * Please have an Imam review the wording before it goes live.
 */
class PrayerContent
{
    /** @return list<array{kind:string,prayer:string,arabic:?string,text_en:string,reference:string}> */
    public static function defaults(): array
    {
        $q = fn (string $prayer, string $ref, string $arabic, string $en) => ['kind' => 'ayah', 'prayer' => $prayer, 'arabic' => $arabic, 'text_en' => $en, 'reference' => $ref];
        $h = fn (string $prayer, string $ref, string $en) => ['kind' => 'hadith', 'prayer' => $prayer, 'arabic' => null, 'text_en' => $en, 'reference' => $ref];

        return [
            $q('any', 'Surah Ta-Ha 20:14', 'إِنَّنِي أَنَا اللَّهُ لَا إِلَٰهَ إِلَّا أَنَا فَاعْبُدْنِي وَأَقِمِ الصَّلَاةَ لِذِكْرِي', 'Indeed, I am Allah. There is no deity except Me, so worship Me and establish prayer for My remembrance.'),
            $q('any', 'Surah Al-Ankabut 29:45', 'اتْلُ مَا أُوحِيَ إِلَيْكَ مِنَ الْكِتَابِ وَأَقِمِ الصَّلَاةَ ۖ إِنَّ الصَّلَاةَ تَنْهَىٰ عَنِ الْفَحْشَاءِ وَالْمُنكَرِ', 'Recite what has been revealed to you of the Book and establish prayer. Indeed, prayer prohibits immorality and wrongdoing.'),
            $q('any', 'Surah An-Nisa 4:103', 'إِنَّ الصَّلَاةَ كَانَتْ عَلَى الْمُؤْمِنِينَ كِتَابًا مَّوْقُوتًا', 'Indeed, prayer has been decreed upon the believers a decree of specified times.'),
            $q('any', 'Surah Al-Baqarah 2:45', 'وَاسْتَعِينُوا بِالصَّبْرِ وَالصَّلَاةِ ۚ وَإِنَّهَا لَكَبِيرَةٌ إِلَّا عَلَى الْخَاشِعِينَ', 'And seek help through patience and prayer; and indeed, it is difficult except for the humbly submissive [to Allah].'),
            $q('any', 'Surah Al-Baqarah 2:153', 'يَا أَيُّهَا الَّذِينَ آمَنُوا اسْتَعِينُوا بِالصَّبْرِ وَالصَّلَاةِ ۚ إِنَّ اللَّهَ مَعَ الصَّابِرِينَ', 'O you who have believed, seek help through patience and prayer. Indeed, Allah is with the patient.'),
            $q('any', 'Surah An-Nur 24:37', 'رِجَالٌ لَّا تُلْهِيهِمْ تِجَارَةٌ وَلَا بَيْعٌ عَن ذِكْرِ اللَّهِ وَإِقَامِ الصَّلَاةِ', '[Such are] men whom neither commerce nor sale distracts from the remembrance of Allah and performance of prayer.'),
            $q('any', 'Surah Al-Mu\'minun 23:1-2', 'قَدْ أَفْلَحَ الْمُؤْمِنُونَ ۝ الَّذِينَ هُمْ فِي صَلَاتِهِمْ خَاشِعُونَ', 'Certainly will the believers have succeeded: they who are during their prayer humbly submissive.'),
            $q('any', 'Surah Ta-Ha 20:132', 'وَأْمُرْ أَهْلَكَ بِالصَّلَاةِ وَاصْطَبِرْ عَلَيْهَا', 'And enjoin prayer upon your family and be steadfast therein.'),
            $q('fajr', 'Surah Al-Isra 17:78', 'أَقِمِ الصَّلَاةَ لِدُلُوكِ الشَّمْسِ إِلَىٰ غَسَقِ اللَّيْلِ وَقُرْآنَ الْفَجْرِ ۖ إِنَّ قُرْآنَ الْفَجْرِ كَانَ مَشْهُودًا', 'Establish prayer at the decline of the sun until the darkness of the night, and [also] the recitation of dawn. Indeed, the recitation of dawn is ever witnessed.'),
            $q('dhuhr', 'Surah Al-Jumu\'ah 62:9', 'يَا أَيُّهَا الَّذِينَ آمَنُوا إِذَا نُودِيَ لِلصَّلَاةِ مِن يَوْمِ الْجُمُعَةِ فَاسْعَوْا إِلَىٰ ذِكْرِ اللَّهِ وَذَرُوا الْبَيْعَ', 'O you who have believed, when the call is made for the prayer on the day of Jumu\'ah, then proceed to the remembrance of Allah and leave trade.'),
            $q('asr', 'Surah Al-Baqarah 2:238', 'حَافِظُوا عَلَى الصَّلَوَاتِ وَالصَّلَاةِ الْوُسْطَىٰ وَقُومُوا لِلَّهِ قَانِتِينَ', 'Maintain with care the [obligatory] prayers and [in particular] the middle prayer, and stand before Allah, devoutly obedient.'),
            $q('maghrib', 'Surah Ar-Rum 30:17', 'فَسُبْحَانَ اللَّهِ حِينَ تُمْسُونَ وَحِينَ تُصْبِحُونَ', 'So exalt Allah when you reach the evening and when you reach the morning.'),
            $q('isha', 'Surah Hud 11:114', 'وَأَقِمِ الصَّلَاةَ طَرَفَيِ النَّهَارِ وَزُلَفًا مِّنَ اللَّيْلِ ۚ إِنَّ الْحَسَنَاتِ يُذْهِبْنَ السَّيِّئَاتِ', 'And establish prayer at the two ends of the day and at the approach of the night. Indeed, good deeds do away with misdeeds.'),
            $h('any', 'Sahih al-Bukhari 527, Sahih Muslim 85', 'The Prophet (peace be upon him) was asked: "Which deed is most beloved to Allah?" He said: "Prayer at its proper time."'),
            $h('any', 'Sahih Muslim 233', 'The five daily prayers, and from one Friday prayer to the next, are expiation for what is between them, as long as the major sins are avoided.'),
            $h('any', 'Sahih al-Bukhari 528, Sahih Muslim 667', '"Do you think that if there was a river at the door of one of you, and he bathed in it five times a day, any dirt would remain on him?" They said: "No dirt would remain." He said: "That is the example of the five prayers; Allah erases sins by them."'),
            $h('any', 'Sahih al-Bukhari 645, Sahih Muslim 650', 'The prayer in congregation is twenty-seven times better than the prayer of a person praying alone.'),
            $h('any', 'Sahih Muslim 223', 'Purification is half of faith... and prayer is light.'),
            $h('any', 'Sunan an-Nasa\'i; Musnad Ahmad (graded sahih)', 'The coolness of my eyes has been placed in prayer.'),
            $h('any', 'Sahih al-Bukhari 662, Sahih Muslim 669', 'Whoever goes to the mosque in the morning or the evening, Allah prepares for him a place in Paradise every time he goes.'),
            $h('fajr', 'Sahih Muslim 657', 'Whoever prays the Fajr prayer is under the protection of Allah.'),
            $h('fajr', 'Sahih Muslim 656', 'Whoever prays Isha in congregation, it is as if he spent half the night in prayer; and whoever prays Fajr in congregation, it is as if he prayed the whole night.'),
            $h('dhuhr', 'Sahih al-Bukhari 615, Sahih Muslim 437', 'If the people knew what is in the call to prayer and in the first row, and they could not get it except by drawing lots, they would draw lots for it.'),
            $h('asr', 'Sahih al-Bukhari 552', 'Whoever misses the Asr prayer, it is as if he has lost his family and his wealth.'),
            $h('asr', 'Sahih al-Bukhari 574, Sahih Muslim 635', 'Whoever prays the two cool prayers (Fajr and Asr) will enter Paradise.'),
            $h('maghrib', 'Sahih al-Bukhari 1145, Sahih Muslim 758', 'Our Lord descends every night to the lowest heaven... and says: "Who is calling upon Me, that I may answer him?"'),
            $h('isha', 'Sahih Muslim 656', 'Whoever prays Isha in congregation, it is as if he had spent half of the night in prayer.'),
        ];
    }
}
