import datetime as dt
import requests

BASE = "https://nomads.ncep.noaa.gov"
GFSWAVE_PROD = f"{BASE}/pub/data/nccf/com/gfs/prod"

def gfswave_cycle_url(date: dt.date, cycle: int) -> str:
    """Return the base URL for a gfswave run (date + cycle)."""
    ymd = date.strftime("%Y%m%d")
    return f"{GFSWAVE_PROD}/gfs.{ymd}/{cycle:02d}/wave"

def gfswave_file_name(cycle: int, fh: int) -> str:
    """Build the filename for a forecast hour file."""
    return f"gfswave.t{cycle:02d}z.global.0p25.f{fh:03d}.grib2"

def gfswave_file_url(date: dt.date, cycle: int, fh: int) -> str:
    """Full URL to a GRIB2 forecast file for this run/hour."""
    return f"{gfswave_cycle_url(date, cycle)}/{gfswave_file_name(cycle, fh)}"

def exists(url: str, timeout=6) -> bool:
    """Check if a given NOMADS URL exists/returns 200."""
    try:
        r = requests.head(url, timeout=timeout)
        if r.status_code == 200:
            return True
        # some servers reject HEAD; fallback to GET with stream
        r = requests.get(url, stream=True, timeout=timeout)
        return r.status_code == 200
    except requests.RequestException:
        return False

def latest_available_run(
    now_utc: dt.datetime | None = None,
    lookback_hours: int = 30
):
    """
    Find the most recent available gfswave run among cycles [00,06,12,18].
    Looks backward from now_utc up to lookback_hours.
    Returns (date, cycle).
    """
    if now_utc is None:
        now_utc = dt.datetime.utcnow().replace(tzinfo=None)

    cycles = [18, 12, 6, 0]
    for h in range(0, lookback_hours + 1, 3):
        check = now_utc - dt.timedelta(hours=h)
        d = check.date()

        # sort cycles by closeness to current hour
        cyc_sorted = sorted(cycles, key=lambda c: abs((check.hour - c) % 24))
        for cyc in cyc_sorted:
            # probe f000, then f003
            if exists(gfswave_file_url(d, cyc, 0)):
                return d, cyc
            if exists(gfswave_file_url(d, cyc, 3)):
                return d, cyc

    raise RuntimeError("No recent gfswave run found within lookback window.")
