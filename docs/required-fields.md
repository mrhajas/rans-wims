# Required fields: glow and asterisk (2026-10-30)

Every compulsory field in WIMS has a glowing asterisk in its label, and the field itself shows its state:

| State | Asterisk | Field |
|---|---|---|
| empty | maroon, glowing and pulsing | soft maroon glow |
| good value | green, glowing and pulsing | calm |
| rejected (server or check error) | maroon | red error glow (existing rule) |

* `resources/js/Core/requiredFields.js` (started in `app.js`) finds the asterisks, works out which field each belongs to and sets `data-req` on the asterisk and `data-req-state` on the field. A timer re-checks every 400 ms because pickers change values without DOM events.
* Write a compulsory label as `<label>{{ $t('Name') }} <b class="wims-req">*</b></label>`. A bare `*` inside a label's own tag (`<b>*</b>`, `<span>*</span>`) is picked up automatically; a field with the native `required` attribute but no asterisk gets one drawn on its label.
* The error glow (`app.css`, "Invalid-field highlight") now also reacts to the `.ac-err` message and the other field-message classes of the modules, not only `.wims-field-error`.
* Colours: `--req-bad` (maroon) and `--req-ok` (green) in `app.css`, with dark-mode values.
* Forms of the older modules that never carried an asterisk are not marked until their labels get one.
