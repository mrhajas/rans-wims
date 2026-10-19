#!/bin/bash
# Regenerates public/images/lock-glow.gif — the pulsing-glow padlock icon
# used in resources/views/emails/reset-password.blade.php.
#
# The icon itself (resources/lock-icon-source.png) is static artwork with its
# own navy circular backdrop baked in. Around it, this script draws a soft
# yellow radial glow whose radius/opacity pulses frame to frame, and bakes
# the whole thing into a looping GIF — the same reasoning as the earlier
# animated lock: CSS @keyframes/animation is silently ignored by most
# desktop/webmail clients (Outlook, Gmail), so a GIF is the one technique
# that reliably plays everywhere.
#
# Each frame is flattened onto a white background before being added to the
# GIF. GIF transparency is all-or-nothing (no partial alpha), so a soft
# blurred glow left on a transparent canvas dithers into a speckled edge
# once quantized to a GIF palette; flattening onto the email card's own
# white background first avoids that and costs nothing since the icon
# always sits on that same white card.
#
# Requires ImageMagick (`magick`/`convert`) and `bc`.
# Usage: bash resources/build-lock-glow.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$ROOT/resources/lock-icon-source.png"
OUT="$ROOT/public/images/lock-glow.gif"
FRAMES_DIR="$(mktemp -d)"
trap 'rm -rf "$FRAMES_DIR"' EXIT

CANVAS=460
CX=230
CY=230
FRAME_COUNT=8
GLOW_COLOR="f6c945"

magick "$SOURCE" -resize 300x300 -trim +repage "$FRAMES_DIR/icon.png"

for i in $(seq 0 $((FRAME_COUNT - 1))); do
    # A full sine cycle across the frame count gives a smooth pulse that
    # loops seamlessly back to frame 0 — no visible "seam" in the loop.
    phase=$(echo "$i * 2 * 3.14159265 / $FRAME_COUNT" | bc -l)
    s=$(echo "(s($phase) + 1) / 2" | bc -l)
    radius=$(printf '%.0f' "$(echo "150 + 26 * $s" | bc -l)")
    alpha=$(printf '%02x' "$(printf '%.0f' "$(echo "150 + 90 * $s" | bc -l)")")
    edge_x=$((CX + radius))

    magick -size ${CANVAS}x${CANVAS} xc:none \
        -fill "#${GLOW_COLOR}${alpha}" \
        -draw "circle ${CX},${CY} ${edge_x},${CY}" \
        -blur 0x22 \
        "$FRAMES_DIR/glow.png"

    magick "$FRAMES_DIR/glow.png" "$FRAMES_DIR/icon.png" -gravity center -compose over -composite \
        -background white -flatten \
        -resize 240x240 \
        "$FRAMES_DIR/frame$(printf '%02d' "$i").png"
done

magick -delay 30 "$FRAMES_DIR"/frame*.png -loop 0 -layers Optimize -colors 96 "$OUT"

echo "Wrote $OUT"
