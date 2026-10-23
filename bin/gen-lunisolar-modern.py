#!/usr/bin/env python3
"""Refresh the modern Chinese/Korean calendar records from pinned ICU4X data.

ICU4X validates these records against the Hong Kong Observatory and KASI.
The existing broad-range astronomical approximation remains outside this range.
"""

import base64
import datetime
import pathlib
import re
import struct
import urllib.request
import zlib

REVISION = "51f138436f50f8110f854252f9a506ccfe06ef1e"
BASE = f"https://raw.githubusercontent.com/unicode-org/icu4x/{REVISION}"
DIRECTORY = pathlib.Path(__file__).resolve().parents[1] / "src/BuiltIn/data"
PATTERN = re.compile(
    r"PackedEastAsianTraditionalYearData::new\((\d+), \[([ls, ]+)\], "
    r"(None|Some\((\d+)\)), gregorian\((\d+), (\d+), (\d+)\)\)"
)

for calendar, country in [("chinese", "china"), ("dangi", "korea")]:
    url = f"{BASE}/components/calendar/src/cal/east_asian_traditional/{country}_data.rs"
    source = urllib.request.urlopen(url).read().decode()
    path = DIRECTORY / f"{calendar}_calendar.php"
    text = path.read_text()
    start = int(re.search(r"'start' => (-?\d+)", text)[1])
    blob_match = re.search(r"'blob' => '([^']+)'", text)
    blob = bytearray(zlib.decompress(base64.b64decode(blob_match[1])))
    records = list(PATTERN.finditer(source))
    assert records, "No source records found"
    for match in records:
        year = int(match[1])
        is_leap = match[3] != "None"
        count = 13 if is_leap else 12
        lengths = match[2].split(", ")[:count]
        bits = sum(1 << i for i, length in enumerate(lengths) if length == "l")
        epoch = (datetime.date(*map(int, match.group(5, 6, 7))) - datetime.date(1970, 1, 1)).days
        leap = int(match[4]) - 2 if is_leap else -1
        offset = (year - start) * 8
        blob[offset:offset + 8] = struct.pack("<ibHB", epoch, leap, bits, count)
    encoded = base64.b64encode(zlib.compress(blob, 9)).decode()
    text = text[:blob_match.start(1)] + encoded + text[blob_match.end(1):]
    marker = "// Modern records are refreshed by bin/gen-lunisolar-modern.py from ICU4X.\n"
    if marker not in text:
        text = text.replace("// DO NOT EDIT BY HAND.", marker + "// DO NOT EDIT BY HAND.")
    path.write_text(text)
    print(f"{calendar}: refreshed {len(records)} years from {url}")

license_text = urllib.request.urlopen(f"{BASE}/LICENSE").read()
(DIRECTORY / "ICU4X-LICENSE").write_bytes(license_text)
