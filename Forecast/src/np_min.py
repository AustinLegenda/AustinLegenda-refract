# np_min.py - tiny subset to avoid numpy dependency
import math

def array(x): return list(x)
def asarray(x): return list(x)
def mean(x): 
    x=list(x); return sum(x)/len(x) if x else 0.0
def sqrt(x): return math.sqrt(x)
def sin(x): return math.sin(x)
def cos(x): return math.cos(x)
pi = math.pi

def linspace(start, stop, num=50):
    if num <= 1: return [float(start)]
    step = (stop - start) / (num - 1)
    return [start + i*step for i in range(num)]

def arange(start, stop=None, step=1):
    if stop is None: start, stop = 0, start
    x = []
    v = start
    if step == 0: raise ValueError("step must not be 0")
    if step > 0:
        while v < stop: x.append(v); v += step
    else:
        while v > stop: x.append(v); v += step
    return x

def interp(x, xp, fp):
    # simple 1D linear interpolation like numpy.interp
    if not xp or not fp or len(xp) != len(fp):
        raise ValueError("xp and fp must be same-length, non-empty")
    if x <= xp[0]: return fp[0]
    if x >= xp[-1]: return fp[-1]
    # find interval
    lo, hi = 0, len(xp)-1
    while hi - lo > 1:
        mid = (lo + hi)//2
        if xp[mid] <= x: lo = mid
        else: hi = mid
    x0, x1 = xp[lo], xp[hi]
    y0, y1 = fp[lo], fp[hi]
    t = (x - x0) / (x1 - x0)
    return y0 + t*(y1 - y0)

def argmax(seq):
    if not seq: raise ValueError("empty sequence")
    m, idx = seq[0], 0
    for i,v in enumerate(seq):
        if v > m: m, idx = v, i
    return idx
