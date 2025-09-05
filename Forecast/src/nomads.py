#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import logging
import datetime as dt
from typing import List, Tuple, Optional, Set
import requests

# ===== Config =====
GFSWAVE_PROD = os.getenv(
    "GFSWAVE_PROD",
    "https://nomads.ncep.noaa.gov/pub/data/nccf/com/gfs/prod",
)
LOOKBACK_HOURS_DEFAULT = int(os.getenv("LOOKBACK_HOURS", "72"))
HTTP_TIMEOUT = int(os.getenv("HTTP_TIMEOUT", "12"))
USER_AGENT = os.getenv("HTTP_USER_AGENT", "normal-surf/1.0")

# ===== URL builders =====
def _candidate_dirs(date: dt.date, cycle: int) -> List[str]:
    """Prefer .../wave/gridded, then .../wave."""
    ymd = date.strftime("%Y%m%d")
    base = f"{GFSWAVE_PROD}/gfs.{ymd}/{cycle:02d}/wave"
    return [f"{base}/gridded", base]

def gfswave_file_name(cycle: int, fhr: int, ext: str = "grib2") -> str:
    """Global 0p25° GFS-Wave file name."""
    return f"gfswave.t{cycle:02d}z.global.0p25.f{fhr:03d}.{ext}"

def gfswave_cycle_url(date: dt.date, cycle: int) -> str:
    """Primary directory (gridded preferred)."""
    return _candidate_dirs(date, cycle)[0]

def gfswave_file_url(date: dt.date, cycle: int, fhr: int, ext: str = "grib2") -> str:
    return f"{gfswave_cycle_url(date, cycle)}/{gfswave_file_name(cycle, fhr, ext)}"

# ===== HTTP =====
def http_ok(url: str, timeout: int = HTTP_TIMEOUT) -> bool:
    """Robust existence check (GET, follows redirects)."""
    try:
        r = requests.get(
            url,
            stream=True,
            timeout=timeout,
            headers={"User-Agent": USER_AGENT},
        )
        return r.status_code == 200
    except requests.RequestException:
        return False

# Back-compat alias some code may import
def exists(url: str, timeout: int = HTTP_TIMEOUT) -> bool:
    return http_ok(url, timeout=timeout)

# ===== Discovery =====
def _probe_idx(date: dt.date, cycle: int, fhr: int = 0) -> Optional[str]:
    """
    Probe the tiny .idx file for a given forecast hour across candidate dirs.
    Returns the URL if found; else None.
    """
    for durl in _candidate_dirs(date, cycle):
        idx = f"{durl}/{gfswave_file_name(cycle, fhr, 'grib2.idx')}"
        logging.info(f"probe {date} {cycle:02d}Z → {idx}")
        if http_ok(idx):
            return idx
    return None

def pick_base_dir(date: dt.date, cycle: int) -> Optional[str]:
    """
    Return the concrete directory that actually exists for this run by probing f000.idx,
    preferring wave/gridded then wave/.
    """
    idx = _probe_idx(date, cycle, 0) or _probe_idx(date, cycle, 3)
    if not idx:
        return None
    # strip filename from idx URL
    return idx.rsplit("/", 1)[0]

def latest_available_run(
    lookback_hours: Optional[int] = None,
    now_utc: Optional[dt.datetime] = None,
) -> Tuple[dt.date, int]:
    """
    Old signature: kept for compatibility. Uses the improved probe logic.
    """
    d, c, _ = latest_available_run_with_base(lookback_hours, now_utc)
    return d, c

def latest_available_run_with_base(
    lookback_hours: Optional[int] = None,
    now_utc: Optional[dt.datetime] = None,
) -> Tuple[dt.date, int, str]:
    """
    Discover newest (date, cycle) and ALSO return the exact base directory URL
    that we proved exists (…/wave/gridded or …/wave). Raises RuntimeError if none.
    """
    if lookback_hours is None:
        lookback_hours = LOOKBACK_HOURS_DEFAULT
    if now_utc is None:
        now_utc = dt.datetime.utcnow()

    cycles = (18, 12, 6, 0)
    seen: Set[Tuple[dt.date, int]] = set()

    logging.info(f"Discovering latest GFS-Wave run (lookback={lookback_hours}h)…")
    for h in range(0, lookback_hours + 1, 3):
        t = now_utc - dt.timedelta(hours=h)
        d = t.date()
        for cyc in cycles:
            key = (d, cyc)
            if key in seen:
                continue
            seen.add(key)
            base = pick_base_dir(d, cyc)
            if base:
                logging.info(f"Found run {d} {cyc:02d}Z @ {base}")
                return d, cyc, base

    raise RuntimeError("No recent gfswave run found within lookback window.")

# ===== Utilities =====
def build_hour_urls(base_dir: str, cycle: int, hours: List[int], ext: str = "grib2") -> List[str]:
    return [f"{base_dir}/{gfswave_file_name(cycle, h, ext)}" for h in hours]

# Optional: CLI to test discovery quickly
if __name__ == "__main__":
    import argparse, sys
    logging.basicConfig(level=logging.INFO, format="%(message)s")
    ap = argparse.ArgumentParser()
    ap.add_argument("--hours", type=int, default=LOOKBACK_HOURS_DEFAULT)
    args = ap.parse_args()
    try:
        d, c, base = latest_available_run_with_base(args.hours)
        print(f"latest: {d} {c:02d}Z\nbase:   {base}")
        # Show first two URLs we’ll actually fetch
        for h in (0, 3):
            print("url:", f"{base}/{gfswave_file_name(c, h, 'grib2')}")
        sys.exit(0)
    except RuntimeError as e:
        print(str(e))
        sys.exit(1)
