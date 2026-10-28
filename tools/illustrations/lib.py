import math
D='#141419';W='#ffffff';LAV='#c8b6ff';VIO='#7c5cff';MINT='#9fe8cb';GRN='#13795b';LEM='#e4f28f';PEACH='#ffb48a';SKY='#9fd2ff';PINK='#ffbcd3';GRAY='#ececf2'
SK=['#f2c7a5','#c98e6b','#8d5a3b','#f6d2b8']
def n(v):
    v=round(v,1)
    return str(int(v)) if v==int(v) else str(v)
def _o(o): return f' opacity="{o}"' if o is not None else ''
def _t(t): return f' transform="{t}"' if t else ''
def C(x,y,r,f,o=None,st=None,sw=2.5):
    s=f' stroke="{st}" stroke-width="{sw}"' if st else ''
    return f'<circle cx="{n(x)}" cy="{n(y)}" r="{n(r)}" fill="{f}"{s}{_o(o)}/>'
def E(x,y,rx,ry,f,o=None,t=None,st=None,sw=2.5):
    s=f' stroke="{st}" stroke-width="{sw}"' if st else ''
    return f'<ellipse cx="{n(x)}" cy="{n(y)}" rx="{n(rx)}" ry="{n(ry)}" fill="{f}"{s}{_o(o)}{_t(t)}/>'
def R(x,y,w,h,f,rx=0,o=None,t=None,st=None,sw=2.5):
    r=f' rx="{n(rx)}"' if rx else ''
    s=f' stroke="{st}" stroke-width="{sw}"' if st else ''
    return f'<rect x="{n(x)}" y="{n(y)}" width="{n(w)}" height="{n(h)}"{r} fill="{f}"{s}{_o(o)}{_t(t)}/>'
def P(d,f,o=None,t=None,st=None,sw=2.5):
    s=f' stroke="{st}" stroke-width="{sw}" stroke-linejoin="round"' if st else ''
    return f'<path d="{d}" fill="{f}"{s}{_o(o)}{_t(t)}/>'
def S(d,c,w=3,o=None,t=None,dash=None):
    ds=f' stroke-dasharray="{dash}"' if dash else ''
    return f'<path d="{d}" fill="none" stroke="{c}" stroke-width="{n(w)}" stroke-linecap="round" stroke-linejoin="round"{ds}{_o(o)}{_t(t)}/>'
def G(c,t=None,o=None):
    return f'<g{_t(t)}{_o(o)}>'+''.join(c if isinstance(c,list) else [c])+'</g>'
def pts(p):
    if len(p)==2: return f'M{n(p[0][0])},{n(p[0][1])} L{n(p[1][0])},{n(p[1][1])}'
    return f'M{n(p[0][0])},{n(p[0][1])} Q{n(p[1][0])},{n(p[1][1])} {n(p[2][0])},{n(p[2][1])}'
def spark(x,y,s,f,o=None):
    return P(f'M{n(x)},{n(y-s)} Q{n(x)},{n(y)} {n(x+s)},{n(y)} Q{n(x)},{n(y)} {n(x)},{n(y+s)} Q{n(x)},{n(y)} {n(x-s)},{n(y)} Q{n(x)},{n(y)} {n(x)},{n(y-s)}Z',f,o)
def blob(cx,cy,rx,ry,f=LAV,o=.35):
    return P(f'M{n(cx-rx)},{n(cy)} C{n(cx-rx)},{n(cy-ry*1.1)} {n(cx+rx*.3)},{n(cy-ry*1.2)} {n(cx+rx*.8)},{n(cy-ry*.7)} C{n(cx+rx*1.2)},{n(cy-ry*.2)} {n(cx+rx)},{n(cy+ry*.9)} {n(cx+rx*.2)},{n(cy+ry)} C{n(cx-rx*.5)},{n(cy+ry*1.1)} {n(cx-rx)},{n(cy+ry*.6)} {n(cx-rx)},{n(cy)}Z',f,o)
def ground(cx,cy,rx,ry=9,f=LAV,o=.6): return E(cx,cy,rx,ry,f,o)
def leaf(x,y,dx,dy,f,w=.35):
    L=math.hypot(dx,dy); px,py=-dy/L*L*w,dx/L*L*w
    mx,my=x+dx/2,y+dy/2
    return P(f'M{n(x)},{n(y)} Q{n(mx+px)},{n(my+py)} {n(x+dx)},{n(y+dy)} Q{n(mx-px)},{n(my-py)} {n(x)},{n(y)}Z',f)
def plant(x,y,s=1,pot=PEACH):
    c=[leaf(0,-30,-26,-40,GRN),leaf(0,-30,26,-44,GRN),leaf(0,-30,-6,-58,MINT),leaf(0,-30,-32,-16,MINT),leaf(0,-30,30,-18,MINT),
       P('M-16,-32 L16,-32 L12,0 L-12,0Z',pot),R(-18,-36,36,8,pot,rx=3)]
    return G(c,f'translate({n(x)} {n(y)}) scale({s})')
def cloud(x,y,s=1,f=W,o=None,st=D):
    sh=[(-14,0,14),(6,-8,18),(24,2,12)]
    c=[]
    if st: c+=[C(a,b,r,f,st=st,sw=n(2.5/s)) for a,b,r in sh]+[R(-28,0,64,14,f,rx=7,st=st,sw=n(2.5/s))]
    c+=[C(a,b,r,f) for a,b,r in sh]+[R(-28,0,64,14,f,rx=7)] if not st else [C(a,b,r-1.2/s,f) for a,b,r in sh]+[R(-27,0,62,13,f,rx=6)]
    return G(c,f'translate({n(x)} {n(y)}) scale({s})',o)
def puffs(lst,f=GRAY,st=D):
    return ''.join(C(x,y,r,f,st=st) for x,y,r in lst)+''.join(C(x,y,r-1.2,f) for x,y,r in lst)
def dots(lst,f,r=3,o=None): return ''.join(C(x,y,r,f,o) for x,y in lst)

def face(mood='smile',r=20,shades=False,brows=None):
    o=[]
    if shades:
        o+= [R(-15,-1,13,9,D,rx=4),R(2,-1,13,9,D,rx=4),S('M-3,2 L3,2',D,2)]
    else:
        o+= [C(-7,4,2.4,D),C(7,4,2.4,D)]
    if brows=='worry': o+= [S('M-11,-4 L-4,-6',D,2),S('M4,-6 L11,-4',D,2)]
    if brows=='up': o+= [S('M-11,-5 L-4,-6',D,2),S('M4,-7 L11,-4',D,2)]
    if mood=='smile': o.append(S('M-5,10 Q0,15 5,10',D,2.2))
    elif mood=='open': o.append(P('M-6,10 Q0,19 6,10Z',D))
    elif mood=='o': o.append(C(0,12,2.6,D))
    elif mood=='flat': o.append(S('M-4,12 L4,11',D,2.2))
    o+= [C(-12,10,3.2,PINK,.8),C(12,10,3.2,PINK,.8)]
    return ''.join(o)

def head(skin,hair,style,r=20,mood='smile',shades=False,brows=None):
    o=[]
    if style=='long': o+=[R(-r-5,-6,13,r+18,hair,rx=6),R(r-8,-6,13,r+18,hair,rx=6)]
    if style=='afro': o.append(C(0,-3,r+9,hair))
    if style=='bun': o.append(C(0,-r-6,9,hair))
    if style=='pony': o.append(E(r+4,-2,7,14,hair,t=f'rotate(-25 {r+4} -2)'))
    if style in('short','long','bun','side','curly','pony'): o.append(C(0,-2,r+2,hair))
    o.append(C(-r+1,5,4,skin));o.append(C(r-1,5,4,skin))
    o.append(C(0,2,r,skin))
    if style in('short','long','bun','pony'):
        o.append(P(f'M{-r-2},3 A{r+2},{r+2} 0 0 1 {r+2},3 Q{n(r*.25)},{n(-r*.5)} {-r-2},3Z',hair))
    elif style=='side':
        o.append(P(f'M{-r-2},4 A{r+2},{r+2} 0 0 1 {r+2},2 Q{n(-r*.2)},{n(-r*.55)} {-r-2},4Z',hair))
    elif style in('curly','afro'):
        o+= [C(x,y,7,hair) for x,y in [(-15,-8),(-8,-15),(1,-17),(10,-14),(16,-7)]]
    elif style=='hat':
        o+= [P(f'M{-r-1},-2 A{r+1},{r+1} 0 0 1 {r+1},-2Z',LEM),R(-r-6,-5,2*r+12,6,LEM,rx=3),R(-3,-r-3,6,r-2,'#ffffff',o=.6,rx=3)]
    o.append(face(mood,r,shades,brows))
    return ''.join(o)

POSES={
 'down':([(-18,-50),(-27,-30),(-27,-10)],[(18,-50),(27,-30),(27,-10)]),
 'wave':([(-18,-50),(-40,-58),(-42,-88)],[(18,-50),(40,-58),(42,-88)]),
 'up':([(-18,-50),(-38,-66),(-40,-94)],[(18,-50),(38,-66),(40,-94)]),
 'point':([(-18,-50),(-40,-52),(-62,-64)],[(18,-50),(40,-52),(62,-64)]),
 'hold':([(-18,-50),(-28,-30),(-8,-26)],[(18,-50),(28,-30),(8,-26)]),
 'hips':([(-18,-50),(-36,-34),(-20,-16)],[(18,-50),(36,-34),(20,-16)]),
 'shrug':([(-18,-50),(-34,-34),(-48,-46)],[(18,-50),(34,-34),(48,-46)]),
}
def arm(a,side):
    if isinstance(a,str): return POSES[a][0 if side=='L' else 1]
    return a
def person(x,y,s=1,skin=SK[0],hair=D,style='short',shirt=VIO,pants=D,arms=('down','down'),legs='stand',mood='smile',shades=False,brows=None,front='',flip=False,shoes=D,back='',rot=0):
    """origin = hip. standing feet at +60"""
    o=[back]
    if legs=='stand':
        o+= [S('M-9,-2 L-10,52',pants,15),S('M9,-2 L10,52',pants,15),E(-13,56,11,6,shoes),E(13,56,11,6,shoes)]
    elif legs=='sit':
        o+= [S('M-6,-4 L34,-4',pants,16),S('M34,-4 L36,40',pants,15),E(42,44,12,6,shoes)]
    elif legs=='cross':
        o+= [E(0,2,38,13,pants),E(-32,9,9,5,shoes),E(32,9,9,5,shoes)]
    elif legs=='jump':
        o+= [S('M-9,-2 L-18,24 L-8,46',pants,15),S('M9,-2 L20,22 L12,46',pants,15),E(-6,50,11,6,shoes),E(16,50,11,6,shoes)]
    o.append(R(-5,-70,10,12,skin,rx=3))
    o.append(P('M-22,-2 L-21,-48 Q-21,-62 -8,-62 L8,-62 Q21,-62 21,-48 L22,-2 Q22,2 18,2 L-18,2 Q-22,2 -22,-2Z',shirt))
    o.append(front)
    for side in 'LR':
        p=arm(arms[0 if side=='L' else 1],side)
        if p is None: continue
        o.append(S(pts(p),shirt,12)); o.append(C(p[-1][0],p[-1][1],6,skin))
    o.append(G(head(skin,hair,style,mood=mood,shades=shades,brows=brows),'translate(0 -86)'))
    sx=f'{-s} {s}' if flip else f'{s}'
    rt=f' rotate({rot})' if rot else ''
    return G(o,f'translate({n(x)} {n(y)}){rt} scale({sx})')
def svg(vb,body):
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" fill="none">'+''.join(body)+'</svg>'
