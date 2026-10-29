#!/usr/bin/env python3
"""
Cuts the real RANS logo (public/images/logo-gold.png) into its two lettering parts for the Holiday Greetings email:

  public/images/holiday/logo-r.png    the R, in the dark blue of the logo (the email shows the logo on white, no container)
  public/images/holiday/logo-ns.png   the N and the S, in dark blue

The temple between them is NOT cut out: the email draws it in HTML and CSS so that it can animate (it builds itself, column by column) in the mail apps that run CSS animation,
and still shows as a finished temple in the ones that do not (Gmail, Outlook). Run: python3 resources/holiday/make_logo_parts.py (needs Pillow).
"""
import os
from PIL import Image

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
OUT = os.path.join(ROOT, 'public', 'images', 'holiday')
os.makedirs(OUT, exist_ok=True)
NAVY = (20, 42, 82)
logo = Image.open(os.path.join(ROOT, 'public', 'images', 'logo-gold.png')).convert('RGBA')
for name, box in (('logo-r.png', (50, 12, 171, 142)), ('logo-ns.png', (345, 12, 556, 142))):
    part = logo.crop(box)
    solid = Image.new('RGBA', part.size, NAVY + (255,))
    solid.putalpha(part.getchannel('A'))          # the shape of the lettering, filled with the dark blue
    part = solid
    part.save(os.path.join(OUT, name), optimize=True)
    print(name, part.size, os.path.getsize(os.path.join(OUT, name)) // 1024, 'KB')
