# Wave Forecast → JSON (NOMADS gfswave)

Pulls GFS-Wave (WW3) forecast from NOAA NOMADS, subsets a lat-lon box around NDBC buoy 41112, and writes JSON arrays for HTSGW, DIRPW, PERPW.

## Requirements

- Python 3.10+
- `eccodes` installed (needed by cfgrib)
  - macOS: `brew install eccodes`
  - conda: `conda install -c conda-forge eccodes cfgrib xarray`
- `pip install -r requirements.txt`

## Setup

1. `cp .env.example .env` and adjust bbox / range / vars.
2. Install deps:
   ```bash
   python -m pip install -r requirements.txt
