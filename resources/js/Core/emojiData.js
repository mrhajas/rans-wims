// Emoji set for the WIMSChat picker. [emoji, "search words"] per entry, grouped. Kept as data (not hard-coded in the
// component) so groups can be extended in one place.
export const EMOJI_GROUPS = [
  {
    key: 'smileys', label: 'Smileys', icon: '😀',
    items: [
      ['😀', 'grin smile happy'], ['😃', 'smile happy open'], ['😄', 'smile laugh happy'], ['😁', 'grin beam'], ['😆', 'laugh squint'], ['😅', 'sweat laugh nervous'],
      ['😂', 'joy tears laugh lol'], ['🤣', 'rofl rolling laugh'], ['🙂', 'slight smile'], ['🙃', 'upside down'], ['😉', 'wink'], ['😊', 'blush smile'],
      ['😇', 'angel halo innocent'], ['🥰', 'love hearts adore'], ['😍', 'heart eyes love'], ['🤩', 'star struck wow'], ['😘', 'kiss blow'], ['😗', 'kiss'],
      ['😋', 'yum tasty delicious'], ['😛', 'tongue'], ['😜', 'wink tongue crazy'], ['🤪', 'zany crazy'], ['😎', 'cool sunglasses'], ['🤓', 'nerd glasses'],
      ['🧐', 'monocle curious'], ['🤔', 'think thinking hmm'], ['🤨', 'raised eyebrow doubt'], ['😐', 'neutral'], ['😑', 'expressionless'], ['😶', 'no mouth silent'],
      ['🙄', 'eye roll'], ['😏', 'smirk'], ['😒', 'unamused'], ['😬', 'grimace awkward'], ['😌', 'relieved calm'], ['😔', 'pensive sad'],
      ['😪', 'sleepy'], ['😴', 'sleep zzz'], ['🤒', 'sick thermometer'], ['🤕', 'bandage hurt'], ['🤢', 'nauseated sick'], ['🥵', 'hot'],
      ['🥶', 'cold freezing'], ['😵', 'dizzy'], ['🤯', 'mind blown'], ['🥳', 'party celebrate'], ['😕', 'confused'], ['😟', 'worried'],
      ['🙁', 'frown'], ['😮', 'surprised wow open mouth'], ['😲', 'astonished'], ['😳', 'flushed embarrassed'], ['🥺', 'pleading please'], ['😢', 'cry sad tear'],
      ['😭', 'sob crying loud'], ['😱', 'scream fear'], ['😖', 'confounded'], ['😞', 'disappointed'], ['😓', 'sweat downcast'], ['😩', 'weary tired'],
      ['😤', 'triumph huff'], ['😡', 'angry mad'], ['😠', 'angry'], ['🤬', 'swearing cursing'], ['😈', 'devil smile'], ['💀', 'skull dead'],
    ],
  },
  {
    key: 'people', label: 'People & gestures', icon: '👍',
    items: [
      ['👍', 'thumbs up like yes ok good'], ['👎', 'thumbs down dislike no'], ['👌', 'ok perfect'], ['✌️', 'victory peace'], ['🤞', 'fingers crossed luck'], ['🤟', 'love you'],
      ['🤘', 'rock'], ['🤙', 'call me'], ['👈', 'point left'], ['👉', 'point right'], ['👆', 'point up'], ['👇', 'point down'],
      ['☝️', 'index up'], ['✋', 'hand stop'], ['🖐️', 'hand fingers'], ['👋', 'wave hello bye'], ['🙌', 'raised hands praise'], ['👏', 'clap applause'],
      ['🙏', 'pray thanks please'], ['🤝', 'handshake deal'], ['💪', 'strong muscle flex'], ['🫡', 'salute'], ['🤷', 'shrug'], ['🤦', 'facepalm'],
      ['🙋', 'raise hand'], ['🙇', 'bow'], ['👀', 'eyes look'], ['🧠', 'brain'], ['🫶', 'heart hands'], ['👷', 'construction worker engineer'],
    ],
  },
  {
    key: 'hearts', label: 'Hearts & symbols', icon: '❤️',
    items: [
      ['❤️', 'red heart love'], ['🧡', 'orange heart'], ['💛', 'yellow heart'], ['💚', 'green heart'], ['💙', 'blue heart'], ['💜', 'purple heart'],
      ['🖤', 'black heart'], ['🤍', 'white heart'], ['💔', 'broken heart'], ['💕', 'two hearts'], ['💯', 'hundred perfect'], ['🔥', 'fire hot lit'],
      ['✨', 'sparkles'], ['⭐', 'star'], ['🌟', 'glowing star'], ['💥', 'boom collision'], ['💫', 'dizzy star'], ['🎉', 'party tada celebrate'],
      ['🎊', 'confetti'], ['✅', 'check done yes'], ['❌', 'cross no'], ['⚠️', 'warning caution'], ['❗', 'exclamation important'], ['❓', 'question'],
      ['➕', 'plus add'], ['➖', 'minus'], ['♻️', 'recycle'], ['🚫', 'prohibited no'], ['🔔', 'bell notification'], ['🔕', 'bell off mute'],
    ],
  },
  {
    key: 'work', label: 'Work & objects', icon: '💼',
    items: [
      ['💼', 'briefcase work'], ['📁', 'folder'], ['📂', 'open folder'], ['📄', 'page document'], ['📝', 'memo note write'], ['📋', 'clipboard'],
      ['📌', 'pin pushpin'], ['📎', 'paperclip attach'], ['📅', 'calendar date'], ['📆', 'calendar'], ['⏰', 'alarm clock'], ['⏳', 'hourglass wait'],
      ['📊', 'chart bar stats'], ['📈', 'chart up growth'], ['📉', 'chart down'], ['💰', 'money bag'], ['💵', 'dollar cash'], ['💳', 'credit card'],
      ['🧾', 'receipt invoice'], ['📦', 'package box delivery'], ['🚚', 'truck delivery'], ['🏗️', 'construction crane'], ['🧱', 'brick'], ['🔧', 'wrench tool'],
      ['🔨', 'hammer'], ['⚙️', 'gear settings'], ['🔒', 'lock secure'], ['🔑', 'key'], ['💡', 'idea bulb'], ['📞', 'phone call'],
      ['📱', 'mobile phone'], ['💻', 'laptop computer'], ['🖥️', 'desktop'], ['📧', 'email mail'], ['📷', 'camera photo'], ['🏠', 'house home'],
      ['🏢', 'office building'], ['🚧', 'construction barrier'], ['🦺', 'safety vest'], ['⛑️', 'helmet safety'],
    ],
  },
  {
    key: 'nature', label: 'Food & nature', icon: '🌴',
    items: [
      ['☕', 'coffee tea'], ['🍵', 'tea'], ['🥤', 'drink'], ['🍽️', 'plate meal'], ['🍕', 'pizza'], ['🍔', 'burger'],
      ['🍟', 'fries'], ['🍞', 'bread'], ['🍎', 'apple'], ['🍌', 'banana'], ['🥭', 'mango'], ['🍉', 'watermelon'],
      ['🍰', 'cake'], ['🍪', 'cookie'], ['🌴', 'palm tree'], ['🌞', 'sun'], ['🌧️', 'rain'], ['⛅', 'cloud sun'],
      ['🌈', 'rainbow'], ['🌊', 'wave sea'], ['🐘', 'elephant'], ['🦁', 'lion'], ['🐢', 'turtle'], ['🌍', 'earth africa'],
    ],
  },
  {
    key: 'travel', label: 'Travel & flags', icon: '✈️',
    items: [
      ['🚗', 'car'], ['🚙', 'suv'], ['🚌', 'bus'], ['🚜', 'tractor'], ['🚛', 'lorry truck'], ['✈️', 'plane flight'],
      ['🚢', 'ship boat'], ['⛽', 'fuel'], ['🛠️', 'tools'], ['📍', 'location pin'], ['🗺️', 'map'], ['🧭', 'compass'],
      ['🇹🇿', 'tanzania flag'], ['🇰🇪', 'kenya flag'], ['🇺🇬', 'uganda flag'], ['🇬🇧', 'uk flag'], ['🇺🇸', 'usa flag'], ['🏁', 'finish flag'],
    ],
  },
];

export function searchEmoji(query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return [];
  const out = [];
  EMOJI_GROUPS.forEach(g => g.items.forEach(([e, words]) => { if (words.includes(q) || words.split(' ').some(w => w.startsWith(q))) out.push(e); }));
  return out.slice(0, 80);
}
