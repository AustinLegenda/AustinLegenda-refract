#!/usr/bin/env python3
# Forecast/src/main.py — GFS-Wave → point time series JSON at a buoy (valid time fixed)

import os, json, datetime as dt
from typing import List, Tuple

import numpy as np
import pandas as pd
import xarray as xr
import requests
from dotenv import load_dotenv

from nomads import latest_available_run, gfswave_file_name

# ---------------- env helpers ----------------
def env_float(n, d):  v=os.getenv(n); return float(v) if v not in (None,"") else d
def env_int(n, d):    v=os.getenv(n); return int(v) if v not in (None,"") else d
def env_bool(n, d=False):
    v=(os.getenv(n) or "").strip().lower()
    return d if v=="" else v in ("1","true","yes","y")
def env_list(n, d):   v=os.getenv(n); return [s.strip() for s in v.split(",") if s.strip()] if v else d
def ensure_dir(p):    os.makedirs(p, exist_ok=True)

# ---------------- lon helpers (WW3 uses 0..360) ----------------
def lon_to_360(lon_deg: float) -> float:
    x = lon_deg % 360.0
    return x if x >= 0 else x + 360.0

def bbox_segments_0_360(center_lon: float, pad_deg: float) -> List[Tuple[float, float]]:
    """
    Return one or two (left,right) lon segments in 0..360 for a bbox centered at center_lon (deg, -180..180).
    If the bbox crosses the dateline (0/360), split into two segments.
    """
    c = lon_to_360(center_lon)
    L = (c - pad_deg) % 360.0
    R = (c + pad_deg) % 360.0
    if L <= R:
        return [(L, R)]
    else:
        # wrap-around: [0, R] union [L, 360)
        return [(0.0, R), (L, 360.0)]

# ---------------- NOMADS subset URL + download ----------------
def build_subset_url(date: dt.date, cycle: int, fh: int,
                     leftlon: float, rightlon: float,
                     toplat: float, bottomlat: float,
                     vars_list: List[str]) -> str:
    ymd = date.strftime("%Y%m%d")
    base = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfswave.pl"
    parts = [
        f"dir=/gfs.{ymd}/{cycle:02d}/wave/gridded",
        f"file={gfswave_file_name(cycle, fh)}",
        f"leftlon={leftlon}",
        f"rightlon={rightlon}",
        f"toplat={toplat}",
        f"bottomlat={bottomlat}",
    ]
    for v in vars_list:
        parts.append(f"var_{v}=on")
    return f"{base}?{'&'.join(parts)}"

def download(url: str, dest: str, timeout: int = 120) -> None:
    r = requests.get(url, stream=True, timeout=timeout)
    r.raise_for_status()
    with open(dest, "wb") as f:
        for chunk in r.iter_content(chunk_size=1<<20):
            if chunk:
                f.write(chunk)

# ---------------- xarray helpers ----------------
def normalize_vars(ds: xr.Dataset) -> xr.Dataset:
    """Map cfgrib names to canonical WW3 names (before final friendly names)."""
    rename = {}
    for v in ds.data_vars:
        lv = v.lower()
        # significant wave height
        if lv.startswith("htsgw") or lv == "swh":
            rename[v] = "HTSGW"
        # primary wave direction
        elif lv.startswith("dirpw") or lv == "mwd":
            rename[v] = "DIRPW"
        # primary wave period
        elif lv.startswith("perpw") or lv == "mwp":
            rename[v] = "PERPW"
    return ds.rename(rename) if rename else ds

def ensure_valid_time(ds: xr.Dataset) -> xr.Dataset:
    """
    Make 'time' the VALID time for this forecast slice.
    Prefer 'valid_time' if present; else compute time + step.
    Never rename over an existing 'time' (assign instead).
    """
    # Case 1: WW3 often provides 'valid_time'
    if "valid_time" in ds.coords:
        ds = ds.assign_coords(time=pd.to_datetime(ds["valid_time"].values))
        ds = ds.reset_coords("valid_time", drop=True)

    # Case 2: Otherwise derive from init 'time' + 'step'
    elif "time" in ds.coords and "step" in ds.coords:
        base = pd.to_datetime(ds["time"].values)
        lead = pd.to_timedelta(ds["step"].values)
        ds = ds.assign_coords(time=base + lead)

    # Drop 'step'—no longer needed once valid 'time' is set
    if "step" in ds.coords or "step" in ds.variables:
        ds = ds.reset_coords("step", drop=True)

    return ds

def select_point(ds: xr.Dataset, lat: float, lon_minus180_180: float) -> xr.Dataset:
    """Select nearest grid cell to (lat, lon), handling dataset lon convention."""
    if "longitude" not in ds.coords or "latitude" not in ds.coords:
        raise SystemExit("Dataset missing longitude/latitude coordinates.")
    lon_max = float(ds["longitude"].max())
    lon_sel = lon_to_360(lon_minus180_180) if lon_max > 180.0 else lon_minus180_180
    return ds.sel(longitude=lon_sel, latitude=lat, method="nearest")

def to_point_payload(ds: xr.Dataset, round_dec: int, pt_lat: float, pt_lon_minus180_180: float) -> dict:
    """Return {meta, data:[{time, Hs_m, Dir_deg, Per_s}...]} with friendly names."""
    ds = normalize_vars(ds)
    if round_dec > 0:
        for v in ds.data_vars:
            ds[v].data = np.round(ds[v].data, round_dec)

    # Final friendly names
    rename_final = {}
    if "HTSGW" in ds: rename_final["HTSGW"] = "Hs_m"
    if "DIRPW" in ds: rename_final["DIRPW"] = "Dir_deg"
    if "PERPW" in ds: rename_final["PERPW"] = "Per_s"
    if rename_final:
        ds = ds.rename(rename_final)

    df = ds.to_dataframe().reset_index()

    # meta coordinates from DS if present, else requested point
    if "longitude" in df:
        lon_raw = float(df["longitude"].iloc[0])
        lon_meta = lon_raw if lon_raw <= 180.0 else lon_raw - 360.0
    else:
        lon_meta = pt_lon_minus180_180
    lat_meta = float(df.get("latitude", pd.Series([pt_lat])).iloc[0]) if "latitude" in df else pt_lat

    # Build records
    vars_present = [c for c in ["Hs_m","Dir_deg","Per_s"] if c in df.columns]
    records = []
    for _, row in df.iterrows():
        entry = {"time": pd.Timestamp(row["time"]).isoformat()}
        for k in vars_present:
            val = row[k]
            entry[k] = None if pd.isna(val) else float(val)
        records.append(entry)

    return {
        "meta": {"model": "gfswave", "point": {"lat": lat_meta, "lon": lon_meta}, "vars": vars_present},
        "data": records,
    }

# ---------------- main ----------------
def main():
    # load .env next to this file
    load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

    # Buoy point (defaults = NDBC 41112)
    pt_lat = env_float("POINT_LAT", 30.709)
    pt_lon = env_float("POINT_LON", -81.292)

    # tiny bbox around point to keep subset small
    pad = env_float("POINT_PAD_DEG", 0.2)
    bottomlat, toplat = pt_lat - pad, pt_lat + pad
    lon_segments = bbox_segments_0_360(pt_lon, pad)

    # variables + horizon
    vars_list = env_list("WAVE_VARS", ["HTSGW", "DIRPW", "PERPW"])
    fh_start = env_int("FH_START", 0)
    fh_end   = env_int("FH_END", 120)
    fh_step  = env_int("FH_STEP", 3)
    round_dec = env_int("ROUND_DECIMALS", 2)
    write_csv = env_bool("WRITE_CSV", False)

    # output/caching
    out_dir  = os.getenv("OUT_DIR", "./data/wave-forecast"); ensure_dir(out_dir)
    out_stem = os.getenv("OUT_STEM", "wave_point_41112")
    cache_dir = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", ".cache")); ensure_dir(cache_dir)

    # run selection
    run_date_env  = (os.getenv("RUN_DATE")  or "").strip()
    run_cycle_env = (os.getenv("RUN_CYCLE") or "").strip()
    if run_date_env and run_cycle_env:
        if "-" in run_date_env:
            y,m,d = map(int, run_date_env.split("-"))
        else:
            y,m,d = int(run_date_env[:4]), int(run_date_env[4:6]), int(run_date_env[6:8])
        run_date, run_cycle = dt.date(y,m,d), int(run_cycle_env)
    else:
        run_date, run_cycle = latest_available_run()

    print(f"Using run: {run_date} {run_cycle:02d}Z @ point ({pt_lat:.3f}, {pt_lon:.3f})")

    # fetch/concat hourly datasets as *point* only
    point_datasets: List[xr.Dataset] = []
    for fh in range(fh_start, fh_end + 1, fh_step):
        ds_final = None
        for seg_idx, (L, R) in enumerate(lon_segments, start=1):
            url = build_subset_url(run_date, run_cycle, fh, L, R, toplat, bottomlat, vars_list)
            print(f"f{fh:03d}[seg{seg_idx}]: {url}")
            bbox_tag = f"{L}_{R}_{toplat}_{bottomlat}".replace(".","p").replace("-","m")
            local_name = gfswave_file_name(run_cycle, fh).replace(".grib2", f".{bbox_tag}.grib2")
            local_path = os.path.join(cache_dir, local_name)

            try:
                download(url, local_path)
                ds = xr.open_dataset(local_path, engine="cfgrib", decode_timedelta=True)
            except Exception as e:
                print(f"  ! seg{seg_idx} skip f{fh:03d}: {e}")
                continue

            # keep only requested wave vars (cover both canonical and shortNames)
            keep = [v for v in ds.data_vars if any(k in v.lower() for k in ("htsgw","dirpw","perpw","swh","mwd","mwp"))]
            if not keep:
                print(f"  ! seg{seg_idx} skip f{fh:03d}: no vars"); continue
            ds = ds[keep]

            # select nearest cell and fix time to VALID time
            try:
                ds = select_point(ds, pt_lat, pt_lon)
            except Exception as e:
                print(f"  ! seg{seg_idx} point select failed f{fh:03d}: {e}")
                continue

            ds = ensure_valid_time(ds)
            ds = ds.squeeze()  # remove length-1 dims
            ds_final = ds
            break  # success for this hour

        if ds_final is None:
            print(f"  ! skip f{fh:03d}: no valid segment produced data"); continue

        point_datasets.append(ds_final)

    if not point_datasets:
        raise SystemExit("No forecast steps opened; widen POINT_PAD_DEG (e.g., 0.4) or check run/cycle.")

    merged = xr.concat(
        point_datasets,
        dim="time",
        coords="minimal",
        compat="override",
        join="override",
    ).sortby("time")

    # no more duplicate collapse: times are valid times now
    payload = to_point_payload(merged, round_dec, pt_lat, pt_lon)

    # write plain, compact JSON
    out_path = os.path.join(out_dir, f"{out_stem}_{run_date.strftime('%Y%m%d')}_{run_cycle:02d}Z.json")
    with open(out_path, "w") as f:
        json.dump(payload, f, separators=(",", ":"))
    print(f"Wrote {out_path}")

    if write_csv:
        df = pd.DataFrame(payload["data"])
        csv_path = out_path.replace(".json", ".csv")
        df.to_csv(csv_path, index=False)
        print(f"Wrote {csv_path}")

if __name__ == "__main__":
    main()
