"""Builds the static artwork of the RANS ID cards (run once; the PNG/JPEG results are committed).
   Icons: Font Awesome Free 6.5.1 (CC BY 4.0), turned into white masks that the card renderer tints. The wave texture, arrow and back pattern are drawn here."""
import os, subprocess, math, random
import numpy as np
from PIL import Image, ImageDraw, ImageFilter
here=os.path.dirname(os.path.abspath(__file__)); svg=os.path.join(here,'svg'); out=os.path.join(here,'img'); os.makedirs(out+'/icons',exist_ok=True)
S=4
# 1) icon masks (white on transparent, 480px square)
for f in sorted(os.listdir(svg)):
    if not f.endswith('.svg'): continue
    n=f[:-4]
    subprocess.run(['magick','-background','none','-density','900',os.path.join(svg,f),'-resize','480x480','-gravity','center','-extent','480x480','-channel','RGB','-evaluate','set','100%','+channel',f'{out}/icons/{n}.png'],check=True)
# aluminium extrusion profile icon (square, notched corners, centre hole, four slots)
W=480*S; im=Image.new('L',(W,W),0); d=ImageDraw.Draw(im); u=W/480
def rr(x0,y0,x1,y1,r,fill): d.rounded_rectangle([x0*u,y0*u,x1*u,y1*u],radius=r*u,fill=fill)
rr(40,40,440,440,28,255)
for cx,cy in [(40,40),(440,40),(40,440),(440,440)]:
    pass
# four corner cut-outs forming the cross, and the centre
for (x0,y0,x1,y1) in [(40,40,150,150),(330,40,440,150),(40,330,150,440),(330,330,440,440)]:
    rr(x0+0,y0+0,x1,y1,22,255)
d.rectangle([150*u,40*u,330*u,95*u],fill=0); d.rectangle([150*u,385*u,330*u,440*u],fill=0); d.rectangle([40*u,150*u,95*u,330*u],fill=0); d.rectangle([385*u,150*u,440*u,330*u],fill=0)
for (x0,y0,x1,y1) in [(110,110,200,200),(280,110,370,200),(110,280,200,370),(280,280,370,370)]: rr(x0,y0,x1,y1,10,0)
d.ellipse([170*u,170*u,310*u,310*u],fill=0); d.ellipse([205*u,205*u,275*u,275*u],fill=255)
alu=im.resize((480,480),Image.LANCZOS); white=Image.new('RGBA',(480,480),(255,255,255,0)); white.putalpha(alu); white.save(f'{out}/icons/aluminium.png')
# carpenter: hammer and saw crossed is not in the free set: use the hammer
# 2) the rounded arrow (points up-left); stroke round caps
def arrow(size=600,stroke=0.2):
    W=size*S; im=Image.new('L',(W,W),0); d=ImageDraw.Draw(im); w=int(stroke*W); r=w//2
    pad=r+int(0.02*W); a=(pad,pad); right=(W-pad,pad); down=(pad,W-pad); tip=(W-pad,W-pad)
    def line(p,q):
        d.line([p,q],fill=255,width=w); d.ellipse([p[0]-r,p[1]-r,p[0]+r,p[1]+r],fill=255); d.ellipse([q[0]-r,q[1]-r,q[0]+r,q[1]+r],fill=255)
    line(a,right); line(a,down); line(a,tip)
    return im.resize((size,size),Image.LANCZOS)
m=arrow(600,0.21); white=Image.new('RGBA',m.size,(255,255,255,0)); white.putalpha(m); white.save(f'{out}/arrow.png')
# 3) wave texture of the off-white card surface (1148 x 1786 like the reference), very soft
w,h=1148,1786; yy,xx=np.mgrid[0:h,0:w].astype(np.float32)
f=(np.sin((yy+90*np.sin(xx/310.0)+55*np.sin(xx/130.0+yy/420.0))/37.0)+0.6*np.sin((xx*0.6-yy*0.35+70*np.sin(yy/260.0))/53.0))
f=f/1.6; base=np.array([240,239,235],dtype=np.float32)
img=np.clip(base[None,None,:]+f[...,None]*np.array([4.2,4.2,4.4]),0,255).astype(np.uint8)
Image.fromarray(img,'RGB').filter(ImageFilter.GaussianBlur(1.2)).save(f'{out}/wave.jpg',quality=88,optimize=True)
# 4) back: faint skyscraper outlines on the orange, and the large yellow arrow shape
# the building outlines of the back are drawn as boxes by the renderer (no big picture); the yellow arrow is a small cropped picture at double resolution
K=1; X0,Y0,X1,Y1=840,1170,1148,1786
big=Image.new('L',((X1-X0)*K*S//2,(Y1-Y0)*K*S//2),0); d=ImageDraw.Draw(big); k=K*S//2
def L(p,q,wd):
    d.line([((p[0]-X0)*k,(p[1]-Y0)*k),((q[0]-X0)*k,(q[1]-Y0)*k)],fill=255,width=int(wd*k)); r=wd*k/2
    for c in (p,q): d.ellipse([(c[0]-X0)*k-r,(c[1]-Y0)*k-r,(c[0]-X0)*k+r,(c[1]-Y0)*k+r],fill=255)
d.rounded_rectangle([(862-X0)*k,(1185-Y0)*k,(w+400-X0)*k,(1400-Y0)*k],radius=70*k,fill=255)
L((930,1260),(1500,1900),210)
m=big.resize(((X1-X0)*K,(Y1-Y0)*K),Image.LANCZOS); y=Image.new('RGBA',m.size,(255,242,0,0)); y.putalpha(m); y.save(f'{out}/back-arrow.png',optimize=True)
print('assets built')
