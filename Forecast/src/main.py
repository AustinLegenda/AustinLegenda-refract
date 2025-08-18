#!/usr/bin/env python3
# Forecast/src/main.py — GFS-Wave → point time series JSON for multiple buoys (same run)

import os, json, datetime as dt
from typing import List, Tuple, Dict

import numpy as np
import pandas as pd
import xarray as xr
import requests
from dotenv import load_dotenv

from nomads import latest_available_run, gfswave_file_name

# ---------------- config ----------------
# Pull both points in the SAME run/cycle
POINTS: Dict[str, Dict[str, float]] = {
    "41112": {"lat": 30.709, "lon": -81.292},
    "41117": {"lat": 29.999, "lon": -81.079},  # 29°59'56"N, 81°4'46"W
}

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
    c = lon_to_360(center_lon)
    L = (c - pad_deg) % 360.0
    R = (c + pad_deg) % 360.0
    return [(L, R)] if L <= R else [(0.0, R), (L, 360.0)]

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
        f"leftlon={leftlon}", f"rightlon={rightlon}",
        f"toplat={toplat}",   f"bottomlat={bottomlat}",
    ] + [f"var_{v}=on" for v in vars_list]
    return f"{base}?{'&'.join(parts)}"

def download(url: str, dest: str, timeout: int = 120) -> None:
    r = requests.get(url, stream=True, timeout=timeout); r.raise_for_status()
    with open(dest, "wb") as f:
        for chunk in r.iter_content(chunk_size=1<<20):
            if chunk: f.write(chunk)

# ---------------- xarray helpers ----------------
def normalize_vars(ds: xr.Dataset) -> xr.Dataset:
    """Map cfgrib names to canonical WW3 names."""
    rename = {}
    for v in ds.data_vars:
        lv = v.lower()
        if lv.startswith("htsgw") or lv == "swh": rename[v] = "HTSGW"
        elif lv.startswith("dirpw") or lv == "mwd": rename[v] = "DIRPW"
        elif lv.startswith("perpw") or lv == "mwp": rename[v] = "PERPW"
    return ds.rename(rename) if rename else ds

def ensure_valid_time(ds: xr.Dataset) -> xr.Dataset:
    """Make 'time' the VALID time (prefer valid_time; else time+step)."""
    if "valid_time" in ds.coords:
        ds = ds.assign_coords(time=pd.to_datetime(ds["valid_time"].values))
        ds = ds.reset_coords("valid_time", drop=True)
    elif "time" in ds.coords and "step" in ds.coords:
        base = pd.to_datetime(ds["time"].values)
        lead = pd.to_timedelta(ds["step"].values)
        ds = ds.assign_coords(time=base + lead)
    if "step" in ds.coords or "step" in ds.variables:
        ds = ds.reset_coords("step", drop=True)
    return ds

def select_point(ds: xr.Dataset, lat: float, lon_minus180_180: float) -> xr.Dataset:
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
    if rename_final: ds = ds.rename(rename_final)

    df = ds.to_dataframe().reset_index()
    lon_meta = (float(df["longitude"].iloc[0]) if "longitude" in df else pt_lon_minus180_180)
    if lon_meta > 180.0: lon_meta -= 360.0
    lat_meta = float(df.get("latitude", pd.Series([pt_lat])).iloc[0]) if "latitude" in df else pt_lat

    vars_present = [c for c in ["Hs_m","Dir_deg","Per_s"] if c in df.columns]
    records = []
    for _, row in df.iterrows():
        entry = {"time": pd.Timestamp(row["time"]).isoformat()}
        for k in vars_present:
            val = row[k]
            entry[k] = None if pd.isna(val) else float(val)
        records.append(entry)

    return {"meta": {"model": "gfswave", "point": {"lat": lat_meta, "lon": lon_meta}, "vars": vars_present},
            "data": records}

# ---------------- fetch one station ----------------
def fetch_point_series(run_date: dt.date, run_cycle: int,
                       lat: float, lon: float,
                       vars_list: List[str], fh_start: int, fh_end: int, fh_step: int,
                       pad: float, cache_dir: str) -> xr.Dataset:
    bottomlat, toplat = lat - pad, lat + pad
    lon_segments = bbox_segments_0_360(lon, pad)

    point_ds_list: List[xr.Dataset] = []
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
                print(f"  ! seg{seg_idx} skip f{fh:03d}: {e}"); continue

            keep = [v for v in ds.data_vars if any(k in v.lower() for k in ("htsgw","dirpw","perpw","swh","mwd","mwp"))]
            if not keep:
                print(f"  ! seg{seg_idx} skip f{fh:03d}: no vars"); continue
            ds = ds[keep]

            try:
                ds = select_point(ds, lat, lon)
            except Exception as e:
                print(f"  ! seg{seg_idx} point select failed f{fh:03d}: {e}"); continue

            ds = ensure_valid_time(ds).squeeze()
            ds_final = ds
            break
        if ds_final is None:
            print(f"  ! skip f{fh:03d}: no valid segment produced data"); continue
        point_ds_list.append(ds_final)

    if not point_ds_list:
        raise SystemExit("No forecast steps opened; widen POINT_PAD_DEG (e.g., 0.4) or check run/cycle.")

    merged = xr.concat(point_ds_list, dim="time", coords="minimal", compat="override", join="override").sortby("time")
    return merged

# ---------------- main ----------------
def main():
    load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

    # variables + horizon
    vars_list = env_list("WAVE_VARS", ["HTSGW","DIRPW","PERPW"])
    fh_start = env_int("FH_START", 0)
    fh_end   = env_int("FH_END", 120)
    fh_step  = env_int("FH_STEP", 3)
    round_dec = env_int("ROUND_DECIMALS", 2)
    pad = env_float("POINT_PAD_DEG", 0.2)

    # output/caching
    out_dir  = os.getenv("OUT_DIR", "./.data/wave-forecast"); ensure_dir(out_dir)
    cache_dir = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", ".cache")); ensure_dir(cache_dir)

    # run selection (ONE run for all stations)
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

    print(f"Using run: {run_date} {run_cycle:02d}Z for stations: {', '.join(POINTS.keys())}")

    # loop stations
    for station, coords in POINTS.items():
        lat, lon = coords["lat"], coords["lon"]
        print(f"\n=== {station} @ ({lat:.3f}, {lon:.3f}) ===")
        ds = fetch_point_series(run_date, run_cycle, lat, lon, vars_list, fh_start, fh_end, fh_step, pad, cache_dir)
        payload = to_point_payload(ds, round_dec, lat, lon)

        out_path = os.path.join(out_dir, f"wave_point_{station}.json")
        with open(out_path, "w") as f:
            json.dump(payload, f, separators=(",", ":"))
        print(f"Saved → {out_path}  ({len(payload['data'])} rows)")

if __name__ == "__main__":
    main()
