<?php

namespace App\Services\HolidayGreetings;

/**
 * The WIMS template of every holiday: the colours, the ornament, the words of the email (a headline with its Swahili line, then a few warm paragraphs; a paragraph that
 * starts with ">" is shown as the highlighted wish) and the SMS. These are only the starting point: the Super Admin reads and changes them before sending.
 * Placeholders: {full} (title and name), {title}, {name}, {holiday}, {year}, {date}, {company}, {signoff}. The SMS texts keep to plain letters so a message stays one or two SMS.
 */
class GreetingThemes
{
    public static function all(): array
    {
        return [
            'newyear' => [
                'label' => "New Year", 'emoji' => "🎆", 'ornament' => "✨ 🎆 🥂 🎆 ✨",
                'palette' => ['primary' => '#0f1b4d', 'secondary' => '#6d28d9', 'accent' => '#fbbf24', 'soft' => '#f5f3ff'],
                'wishes' => [['sun', "Fresh Starts"], ['health', "Good Health"], ['star', "Success"]],
                'icon' => 'sparkle',
                'subject' => "Happy New Year from {company}",
                'headline' => "Happy New Year | Heri ya Mwaka Mpya",
                'body' => "As the old year closes its book and a fresh, unwritten page opens, we pause to say thank you for the trust, the work and the friendship you shared with us.\n\nMay {year} bring you good health, steady progress, open doors and the quiet joy of seeing your efforts bear fruit.\n\n> May every day of this new year be a little brighter than the one before.",
                'sms' => "Dear {full}, {company} wishes you and your family a Happy New Year {year}. May it bring you good health, success and joy. Heri ya Mwaka Mpya! - {signoff}",
            ],
            'revolution' => [
                'label' => "Zanzibar Revolution Day", 'emoji' => "🇹🇿", 'ornament' => "🌴 ✨ 🇹🇿 ✨ 🌴",
                'palette' => ['primary' => '#0b4f8a', 'secondary' => '#16a34a', 'accent' => '#facc15', 'soft' => '#effaf3'],
                'wishes' => [['peace', "Peace"], ['unity', "Unity"], ['flag', "Pride"]],
                'icon' => 'flag',
                'subject' => "Happy Zanzibar Revolution Day from {company}",
                'headline' => "Happy Revolution Day | Heri ya Siku ya Mapinduzi",
                'body' => "Today we remember the courage and sacrifice that shaped Zanzibar, and celebrate the freedom, dignity and unity that have grown from it.\n\nOn behalf of everyone at {company}, we wish you and your loved ones a peaceful and proud Revolution Day.\n\n> Together we keep building the islands and the nation we are proud of.",
                'sms' => "Dear {full}, {company} wishes you a peaceful and proud Zanzibar Revolution Day. Let us keep building our islands and our nation together. Heri ya Mapinduzi! - {signoff}",
            ],
            'karume' => [
                'label' => "Karume Day", 'emoji' => "🕊️", 'ornament' => "🌿 ✨ 🕊️ ✨ 🌿",
                'palette' => ['primary' => '#14532d', 'secondary' => '#0e7490', 'accent' => '#fcd34d', 'soft' => '#f0fdf4'],
                'wishes' => [['peace', "Peace"], ['flower', "Remembrance"], ['unity', "Unity"]],
                'icon' => 'peace',
                'subject' => "Karume Day greetings from {company}",
                'headline' => "Happy Karume Day | Heri ya Siku ya Karume",
                'body' => "On Karume Day we honour the memory of Sheikh Abeid Amani Karume and the vision of unity, dignity and service that he left to the people of Zanzibar.\n\nMay today be a calm and reflective day for you and your family.\n\n> May we carry his spirit of service into everything we do.",
                'sms' => "Dear {full}, on Karume Day {company} wishes you and your family a calm and reflective day. May we carry his spirit of unity and service forward. - {signoff}",
            ],
            'goodfriday' => [
                'label' => "Good Friday", 'emoji' => "✝️", 'ornament' => "🕊️ ✨ ✝️ ✨ 🕊️",
                'palette' => ['primary' => '#3b2a5a', 'secondary' => '#6b4f8f', 'accent' => '#e5c07b', 'soft' => '#f6f3fa'],
                'wishes' => [['peace', "Peace"], ['moon', "Reflection"], ['sparkle', "Hope"]],
                'icon' => 'cross',
                'subject' => "Good Friday blessings from {company}",
                'headline' => "Blessed Good Friday | Ijumaa Kuu yenye Baraka",
                'body' => "On this solemn day of reflection, we think of you and your family with deep respect and warm regards.\n\nMay the day bring you stillness, gratitude and peace of heart.\n\n> May love and mercy fill your home today and always.",
                'sms' => "Dear {full}, {company} wishes you and your family a peaceful and blessed Good Friday. May this day of reflection bring you calm and hope. - {signoff}",
            ],
            'easter' => [
                'label' => "Easter", 'emoji' => "🐣", 'ornament' => "🌷 ✨ 🐣 ✨ 🌷",
                'palette' => ['primary' => '#7c3aed', 'secondary' => '#db2777', 'accent' => '#fde68a', 'soft' => '#fdf4ff'],
                'wishes' => [['flower', "New Hope"], ['smile', "Joy"], ['heart', "Love"]],
                'icon' => 'flower',
                'subject' => "Happy Easter from {company}",
                'headline' => "Happy Easter | Heri ya Pasaka",
                'body' => "Easter is the season of new life and new hope. We hope this holiday gives you time to rest, to gather with the people you love and to enjoy good food and good company.\n\nThank you for being part of our journey. We value you greatly.\n\n> May hope bloom in your heart and joy fill your home this Easter.",
                'sms' => "Dear {full}, {company} wishes you and your family a joyful Easter filled with hope, love and peace. Heri ya Pasaka! - {signoff}",
            ],
            'union' => [
                'label' => "Union Day", 'emoji' => "🤝", 'ornament' => "🇹🇿 ✨ 🤝 ✨ 🇹🇿",
                'palette' => ['primary' => '#0b4f8a', 'secondary' => '#15803d', 'accent' => '#fbbf24', 'soft' => '#f0f7ff'],
                'wishes' => [['unity', "Unity"], ['flag', "Pride"], ['unity', "Brotherhood"]],
                'icon' => 'unity',
                'subject' => "Happy Union Day from {company}",
                'headline' => "Happy Union Day | Heri ya Siku ya Muungano",
                'body' => "Today we celebrate the union of Tanganyika and Zanzibar, a bond of brotherhood that has grown stronger with every year.\n\nWe thank you for being part of the work that builds our country, and wish you and your family a restful and happy Union Day.\n\n> One people, one nation, one future.",
                'sms' => "Dear {full}, {company} wishes you a happy Union Day. One people, one nation, one future. Heri ya Siku ya Muungano! - {signoff}",
            ],
            'workers' => [
                'label' => "Workers' Day", 'emoji' => "🛠️", 'ornament' => "⚒️ ✨ 🛠️ ✨ ⚒️",
                'palette' => ['primary' => '#7c2d12', 'secondary' => '#b45309', 'accent' => '#fcd34d', 'soft' => '#fff7ed'],
                'wishes' => [['bolt', "Strength"], ['hammer', "Pride in Work"], ['moon', "Rest"]],
                'icon' => 'hammer',
                'subject' => "Happy Workers' Day from {company}",
                'headline' => "Happy Workers' Day | Heri ya Siku ya Wafanyakazi",
                'body' => "Behind every road, every wall and every finished project stand the hands and minds of dedicated people. Today we honour you and every worker who gives their best, day after day.\n\nThank you for your effort, your patience and your pride in good work. Please rest well and enjoy this day with your family.\n\n> Your work builds more than buildings. It builds a nation.",
                'sms' => "Dear {full}, thank you for your hard work and dedication. {company} wishes you a restful and happy Workers' Day. Heri ya Siku ya Wafanyakazi! - {signoff}",
            ],
            'sabasaba' => [
                'label' => "Saba Saba", 'emoji' => "🏭", 'ornament' => "🌟 ✨ 🏭 ✨ 🌟",
                'palette' => ['primary' => '#1e3a8a', 'secondary' => '#0891b2', 'accent' => '#fbbf24', 'soft' => '#eff6ff'],
                'wishes' => [['gear', "Enterprise"], ['trend', "Growth"], ['unity', "Partnership"]],
                'icon' => 'gear',
                'subject' => "Saba Saba greetings from {company}",
                'headline' => "Happy Saba Saba | Heri ya Saba Saba",
                'body' => "Saba Saba reminds us of the energy of trade, industry and enterprise that drives our country forward.\n\nWe thank you for being part of our story and wish you a happy and productive day.\n\n> Here is to growing together, one good partnership at a time.",
                'sms' => "Dear {full}, {company} wishes you a happy Saba Saba. Thank you for growing with us. Heri ya Saba Saba! - {signoff}",
            ],
            'farmers' => [
                'label' => "Nane Nane", 'emoji' => "🌾", 'ornament' => "🌽 ✨ 🌾 ✨ 🌽",
                'palette' => ['primary' => '#3f6212', 'secondary' => '#ca8a04', 'accent' => '#fde047', 'soft' => '#fefce8'],
                'wishes' => [['wheat', "Harvest"], ['sun', "Good Weather"], ['flower', "Plenty"]],
                'icon' => 'wheat',
                'subject' => "Happy Nane Nane from {company}",
                'headline' => "Happy Farmers' Day | Heri ya Nane Nane",
                'body' => "Today we salute the farmers whose work feeds our homes and our nation, and everyone whose patience helps good things grow.\n\nWe wish you a day of good harvest in every sense, with family, rest and gratitude.\n\n> May all you have sown return to you as plenty.",
                'sms' => "Dear {full}, {company} wishes you a happy Nane Nane. May all you have sown return to you as plenty. Heri ya Nane Nane! - {signoff}",
            ],
            'nyerere' => [
                'label' => "Nyerere Day", 'emoji' => "📖", 'ornament' => "🌍 ✨ 📖 ✨ 🌍",
                'palette' => ['primary' => '#14532d', 'secondary' => '#1d4ed8', 'accent' => '#fcd34d', 'soft' => '#f0fdf4'],
                'wishes' => [['pen', "Wisdom"], ['unity', "Unity"], ['peace', "Peace"]],
                'icon' => 'pen',
                'subject' => "Nyerere Day greetings from {company}",
                'headline' => "Nyerere Day | Siku ya Mwalimu Nyerere",
                'body' => "On Nyerere Day we remember Mwalimu Julius Kambarage Nyerere, the Father of the Nation, and his belief in unity, equality, education and service.\n\nMay his example guide our work and our life together. We wish you and your family a calm and thoughtful day.\n\n> Unity, peace and hard work: the gifts we keep building on.",
                'sms' => "Dear {full}, on Nyerere Day {company} remembers Mwalimu Nyerere and his call for unity, peace and hard work. We wish you a calm and thoughtful day. - {signoff}",
            ],
            'independence' => [
                'label' => "Independence Day", 'emoji' => "🇹🇿", 'ornament' => "🎉 ✨ 🇹🇿 ✨ 🎉",
                'palette' => ['primary' => '#075985', 'secondary' => '#15803d', 'accent' => '#fde047', 'soft' => '#f0fdf4'],
                'wishes' => [['flag', "Freedom"], ['unity', "Unity"], ['flag', "Pride"]],
                'icon' => 'flag',
                'subject' => "Happy Independence Day from {company}",
                'headline' => "Happy Independence Day | Heri ya Siku ya Uhuru",
                'body' => "On the ninth of December we celebrate the day Tanzania stood tall as a free nation. We are proud of how far we have come, and of the people who made the journey possible.\n\nThank you for being part of the work that builds our country. May this holiday bring you rest, laughter and time with the people you cherish.\n\n> Uhuru na Umoja: freedom and unity, today and always.",
                'sms' => "Dear {full}, {company} wishes you and your family a proud and joyful Independence Day. Uhuru na Umoja! Heri ya Siku ya Uhuru! - {signoff}",
            ],
            'christmas' => [
                'label' => "Christmas", 'emoji' => "🎄", 'ornament' => "🎄 ✨ 🎁 ✨ 🎄",
                'palette' => ['primary' => '#0b4d2e', 'secondary' => '#9f1239', 'accent' => '#f5c542', 'soft' => '#fff8ee'],
                'wishes' => [['smile', "Joy"], ['peace', "Peace"], ['heart', "Love"]],
                'icon' => 'snowflake',
                'subject' => "Merry Christmas from {company}",
                'headline' => "Merry Christmas | Heri ya Krismasi",
                'body' => "At this most wonderful time of the year, our thoughts turn to the people who make our work meaningful, and you are one of them.\n\nThank you for your trust, your kindness and your partnership. We hope your home is filled with laughter, good food and the warmth of the people you love.\n\n> May the peace and joy of Christmas stay with you and your family all year long.",
                'sms' => "Dear {full}, {company} wishes you and your family a blessed Christmas filled with joy, peace and love. Heri ya Krismasi na Mwaka Mpya! - {signoff}",
            ],
            'boxing' => [
                'label' => "Boxing Day", 'emoji' => "🎁", 'ornament' => "🎁 ✨ ❄️ ✨ 🎁",
                'palette' => ['primary' => '#7f1d1d', 'secondary' => '#166534', 'accent' => '#fcd34d', 'soft' => '#fff5f5'],
                'wishes' => [['heart', "Giving"], ['smile', "Joy"], ['house', "Family"]],
                'icon' => 'heart',
                'subject' => "Boxing Day greetings from {company}",
                'headline' => "Happy Boxing Day | Heri ya Boxing Day",
                'body' => "We hope the festive season has brought you plenty of joy and that Boxing Day finds you rested, surrounded by family and friends.\n\nThank you for being part of our year. Enjoy every moment of this holiday.\n\n> May the spirit of giving stay with you well beyond the holidays.",
                'sms' => "Dear {full}, {company} wishes you a restful and happy Boxing Day with the people you love. Thank you for being part of our year. - {signoff}",
            ],
            'eidfitr' => [
                'label' => "Eid al-Fitr", 'emoji' => "🌙", 'ornament' => "🌙 ✨ ⭐ ✨ 🌙",
                'palette' => ['primary' => '#064e3b', 'secondary' => '#0f766e', 'accent' => '#facc15', 'soft' => '#f0fdf9'],
                'wishes' => [['sparkle', "Blessings"], ['heart', "Mercy"], ['smile', "Joy"]],
                'icon' => 'crescent',
                'subject' => "Eid Mubarak from {company}",
                'headline' => "Eid Mubarak | Eid Njema",
                'body' => "After a month of fasting, prayer and generosity, Eid arrives as a celebration of faith, gratitude and togetherness.\n\nWe wish you and your family a joyful Eid. May your prayers be answered, your homes be full of blessings and your table be full of loved ones.\n\n> Taqabbal Allahu minna wa minkum. May Allah accept our good deeds.",
                'sms' => "Dear {full}, {company} wishes you and your family a blessed Eid. May Allah accept your fasting and prayers and fill your home with joy. Eid Mubarak! Eid Njema! - {signoff}",
            ],
            'eidadha' => [
                'label' => "Eid al-Adha", 'emoji' => "🕌", 'ornament' => "🌙 ✨ 🕌 ✨ 🌙",
                'palette' => ['primary' => '#14532d', 'secondary' => '#0e7490', 'accent' => '#fcd34d', 'soft' => '#f0fdf4'],
                'wishes' => [['crescent', "Faith"], ['heart', "Generosity"], ['peace', "Peace"]],
                'icon' => 'crescent',
                'subject' => "Eid al-Adha Mubarak from {company}",
                'headline' => "Eid al-Adha Mubarak | Idd el Hajj Njema",
                'body' => "Eid al-Adha reminds us of sacrifice, faith and the joy of sharing what we have with others.\n\nWe wish you and your family a blessed and peaceful Eid, full of prayer, generosity and time together.\n\n> May Allah bless you with health, peace and abundant mercy.",
                'sms' => "Dear {full}, {company} wishes you and your family a blessed Eid al-Adha full of peace, generosity and togetherness. Eid Mubarak! Idd el Hajj Njema! - {signoff}",
            ],
            'maulid' => [
                'label' => "Maulid", 'emoji' => "🌟", 'ornament' => "🌙 ✨ 🌟 ✨ 🌙",
                'palette' => ['primary' => '#134e4a', 'secondary' => '#7c3aed', 'accent' => '#fde68a', 'soft' => '#f0fdfa'],
                'wishes' => [['star', "Light"], ['peace', "Peace"], ['heart', "Kindness"]],
                'icon' => 'star',
                'subject' => "Maulid greetings from {company}",
                'headline' => "Blessed Maulid | Maulid Njema",
                'body' => "On the occasion of the birthday of the Holy Prophet, peace and blessings be upon him, we send you our warmest wishes.\n\nMay this day bring you closer to kindness, patience and compassion, and fill your home with peace.\n\n> May the light of his message guide us all to goodness.",
                'sms' => "Dear {full}, {company} wishes you and your family a blessed Maulid, a day of peace, kindness and reflection. Maulid Njema! - {signoff}",
            ],
            'generic' => [
                'label' => "RANS greeting (any occasion)", 'emoji' => "🏗️", 'ornament' => "🧱 ✨ 🏗️ ✨ 🧱",
                'palette' => ['primary' => '#0f2540', 'secondary' => '#2f6fb3', 'accent' => '#e6b800', 'soft' => '#f2f6fb'],
                'wishes' => [['trend', "Progress"], ['unity', "Teamwork"], ['moon', "Rest"]],
                'icon' => 'hammer',
                'subject' => "{holiday}: a message from {company}",
                'headline' => "{holiday}",
                'body' => "On the occasion of {holiday}, {company} wishes to say thank you for your hard work, your commitment and the part you play in everything we build together.\n\nWe hope this is a time to rest, to be with the people you love and to return refreshed.\n\n> Thank you for building with us. Enjoy every moment of {holiday}.",
                'sms' => "Dear {full}, {company} wishes you a happy {holiday}. Thank you for your hard work. Enjoy a restful time with your loved ones. - {signoff}",
            ],
        ];
    }

    public static function get(?string $theme): array
    {
        $all = self::all();

        return $all[$theme] ?? $all['generic'];
    }

    /** For the theme choice on a holiday: key => label. */
    public static function choices(): array
    {
        // the RANS-branded greeting for any occasion first: it is what a company holiday or a thank-you day starts from
        $all = collect(self::all())->map(fn ($t) => $t['label']);

        return $all->only('generic')->merge($all->except('generic'))->all();
    }
}
