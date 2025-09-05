#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import time
import logging
import datetime as dt
from pathlib import Path
from typing import List, Dict, Tuple

import requests

# Optional: load .env if present
try:
    from dotenv import load_dotenv
    load_dotenv()
except Exception:
    pass

# Local helpers from nomads.py
from nomads import latest_available_run_with_base, build_hour_urls

# ===== Logging =====
LOGLEVEL = os.getenv("LOGLEVEL", "INFO").upper()
logging.basicConfig(level=getattr(logging, LOGLEVEL, logging.INFO), format="%(message)s")

# ===== Config =====
HTTP_TIMEOUT = int(os.getenv("HTTP_TIMEOUT", "30"))
USER_AGENT = os.getenv("HTTP_USER_AGENT", "normal-surf/1.0")
OUT_ROOT = Path(os.getenv("OUT_ROOT", "data/grib/gfswave"))
HOURS_SPEC = os.getenv("HOURS", "0:72:3")  # "start:end:step"

# Stations (lat, lon). Add more if needed.
STATIONS: Dict[str, Tuple[float, float]] = {
    "41112": (30.709, -81.292),
    "41117": (29.999, -81.079),
}

def parse_hours(spec: str) -> List[int]:
    a, b, c = (int(x) for x in spec.split(":"))
    return list(range(a, b + 1, c))

def download(url: str, out_path: Path, retries: int = 3, timeout: int = HTTP_TIMEOUT) -> bool:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    for attempt in range(1, retries + 1):
        try:
            with requests.get(url, stream=True, timeout=timeout, headers={"User-Agent": USER_AGENT}) as r:
                if r.status_code != 200:
                    raise RuntimeError(f"HTTP {r.status_code}")
                with open(out_path, "wb") as f:
                    for chunk in r.iter_content(1024 * 1024):
                        if chunk:
                            f.write(chunk)
            return True
        except Exception as ex:
            logging.warning(f"download fail ({attempt}/{retries}) {url}: {ex}")
            time.sleep(2 * attempt)
    return False

def process_point_series(local_paths: List[str], station_id: str, lat: float, lon: float,
                         run_date: dt.date, run_cycle: int, ymd: str) -> None:
    """
    Open each GRIB file eagerly (no dask), extract nearest-point series for one station,
    and write JSON to data/waveforecast/wave_forecast_<station_id>.json
    """
    import json
    import numpy as np
    import pandas as pd
    import xarray as xr

    if not local_paths:
        raise SystemExit("No GRIB paths provided to process_point_series().")

    rows = []
    lon_0_360 = None  # detect grid convention once

    # Common GFS-Wave variable name fallbacks
    var_candidates = {
        "hs_m":   ["swh", "hs", "htsgws"],
        "per_s":  ["perpw", "mp2", "per", "mwp"],
        "dir_deg":["dirpw", "mwd", "dir"],
    }

    def pick_var(ds_or_pt, keys):
        for k in keys:
            if k in ds_or_pt:
                return k
        return None

    for p in local_paths:
        ds = xr.open_dataset(
            p,
            engine="cfgrib",
            backend_kwargs={"indexpath": "", "filter_by_keys": {"typeOfLevel": "surface"}},
            chunks=None,           # eager read; no dask required
            decode_times=True,
        )
        try:
            # Establish lon convention once (0..360 vs -180..180)
            if lon_0_360 is None:
                lons = ds["longitude"].values
                lon_0_360 = (np.nanmin(lons) >= 0.0)
            lon_target = (lon + 360.0) % 360.0 if lon_0_360 and lon < 0.0 else lon

            # ---- derive a proper time coordinate (force array-like) ----
            if "valid_time" in ds.variables or "valid_time" in ds.coords:
                tvals = pd.to_datetime(ds["valid_time"].values)
            elif "time" in ds.coords and "step" in ds.variables:
                base = pd.to_datetime(ds["time"].values)
                lead = pd.to_timedelta(ds["step"].values)
                tvals = base + lead
            elif "time" in ds.coords:
                tvals = pd.to_datetime(ds["time"].values)
            else:
                tvals = pd.to_datetime([f"{ymd}T{run_cycle:02d}:00:00Z"])

            if isinstance(tvals, pd.Timestamp):
                tvals = pd.DatetimeIndex([tvals])
            else:
                tvals = pd.to_datetime(tvals)

            # nearest grid point
            pt = ds.sel(latitude=lat, longitude=lon_target, method="nearest")

            hs_k  = pick_var(pt, var_candidates["hs_m"])
            per_k = pick_var(pt, var_candidates["per_s"])
            dir_k = pick_var(pt, var_candidates["dir_deg"])
            if hs_k is None and per_k is None and dir_k is None:
                continue

            n = int(getattr(tvals, "size", len(tvals)))
            rec = {"t_utc": pd.to_datetime(tvals)}

            def _vals(name):
                if name is None:
                    return None
                arr = np.asarray(pt[name])
                if arr.ndim == 0:
                    return np.repeat(arr.astype(float), n)
                arr = arr.reshape(-1).astype(float)
                if arr.size == 1 and n > 1:
                    return np.repeat(arr, n)
                return arr[:n]

            hs_v, per_v, dir_v = _vals(hs_k), _vals(per_k), _vals(dir_k)
            if hs_v is not None:   rec["hs_m"]   = hs_v
            if per_v is not None:  rec["per_s"]  = per_v
            if dir_v is not None:  rec["dir_deg"]= dir_v

            rows.append(pd.DataFrame(rec))
        finally:
            try:
                ds.close()
            except Exception:
                pass

    if not rows:
        raise SystemExit(f"Parsed 0 usable records for station {station_id} (no expected vars found).")

    import pandas as pd
    df = pd.concat(rows, ignore_index=True)

    # Consolidate duplicates by time (keep last) and sort
    df = df.sort_values("t_utc").drop_duplicates(subset=["t_utc"], keep="last")

    # sanity limits
    if "hs_m" in df:    df = df[df["hs_m"].between(0, 20)]
    if "per_s" in df:   df = df[df["per_s"].between(0, 30)]
    if "dir_deg" in df: df = df[df["dir_deg"].between(0, 360)]

    # Output JSON only
    out_dir = Path("data/wave-forecast")
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path = out_dir / f"wave_point_{station_id}.json"

    # ISO timestamps for JSON
    recs = df.copy()
    recs["t_utc"] = recs["t_utc"].dt.strftime("%Y-%m-%dT%H:%M:%SZ")
    import json
    with open(json_path, "w") as f:
        json.dump(recs.to_dict(orient="records"), f, indent=2)

    logging.info(f"Wrote JSON: {json_path} ({len(df)} rows)")

def main() -> None:
    # 1) Discover newest run + concrete base directory
    try:
        run_date, run_cycle, base_dir = latest_available_run_with_base()
    except RuntimeError as e:
        if "No recent gfswave run" in str(e):
            logging.warning("No recent gfswave run found – exiting 0 (no work).")
            sys.exit(0)
        raise

    ymd = run_date.strftime("%Y%m%d")
    logging.info(f"Resolved run {run_date} {run_cycle:02d}Z @ {base_dir}")

    # 2) Build URLs for hours from SAME base_dir
    hours = parse_hours(HOURS_SPEC)
    urls = build_hour_urls(base_dir, run_cycle, hours, ext="grib2")

    # 3) Download once
    target_dir = OUT_ROOT / ymd / f"{run_cycle:02d}"
    ok_count = 0
    local_paths: List[str] = []
    for u in urls:
        fname = u.rsplit("/", 1)[-1]
        dest = target_dir / fname
        if dest.exists() and dest.stat().st_size > 0:
            ok = True
        else:
            ok = download(u, dest)
        if ok:
            ok_count += 1
            local_paths.append(str(dest))

    if ok_count == 0:
        raise SystemExit(
            f"Resolved run {ymd} {run_cycle:02d}Z @ {base_dir}, but downloaded 0 files. "
            "Check URL pattern or try again shortly."
        )

    logging.info(f"Downloaded {ok_count}/{len(urls)} GRIB files → {target_dir}")

    # 4) Parse once per station → JSON
    for station_id, (lat, lon) in STATIONS.items():
        logging.info(f"Processing station {station_id} at lat={lat}, lon={lon}…")
        process_point_series(local_paths, station_id, lat, lon, run_date, run_cycle, ymd)

if __name__ == "__main__":
    main()
