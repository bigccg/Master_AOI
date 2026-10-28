#!/usr/bin/env python3
"""
AOI Inspection & Shelf Management System - Python API Client
Demonstrates automated communication with the AOI Web PHP system via its AJAX REST APIs.
Ideal for SMT line automation, automated barcode scanners, and test station daemons.

Usage:
    python3 scripts/aoi_client.py --help
    python3 scripts/aoi_client.py status
    python3 scripts/aoi_client.py boards --zone 1 --sheet S
    python3 scripts/aoi_client.py programs
    python3 scripts/aoi_client.py add --barcode "PCB-TEST-001" --zone 1 --sheet S --shelf 1 --status OK
"""

import argparse
import json
import sys
import urllib.parse
import urllib.request
from typing import Any, Dict, Optional


class AOIClient:
    def __init__(self, base_url: str = "http://localhost:8080/aoi-web-php"):
        self.base_url = base_url.rstrip("/")

    def _get(self, endpoint: str, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        url = f"{self.base_url}/{endpoint.lstrip('/')}"
        if params:
            query = urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
            url = f"{url}?{query}"
        req = urllib.request.Request(url, headers={"Accept": "application/json", "X-Requested-With": "XMLHttpRequest"})
        with urllib.request.urlopen(req, timeout=10) as response:
            return json.loads(response.read().decode("utf-8"))

    def _post(self, endpoint: str, data: Dict[str, Any]) -> Dict[str, Any]:
        url = f"{self.base_url}/{endpoint.lstrip('/')}"
        payload = json.dumps(data).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=payload,
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=10) as response:
            return json.loads(response.read().decode("utf-8"))

    def get_status(self) -> Dict[str, Any]:
        """Fetch system auth & topology status"""
        return self._get("api/admin_auth.php")

    def get_sheet_data(self, zone: str = "1", sheet: str = "S") -> Dict[str, Any]:
        """Fetch KPI and shelf counts for a specific zone and shelf"""
        return self._get("api/sheet_data.php", {"zone": zone, "sheet": sheet})

    def get_boards(self, zone: str = "1", sheet: str = "ALL", page: int = 1, limit: int = 20) -> Dict[str, Any]:
        """Fetch board inspection records"""
        return self._get("api/boards.php", {"zone": zone, "sheet": sheet, "page": page, "limit": limit})

    def get_programs(self, machine: str = "ALL", status: str = "ALL", limit: int = 20) -> Dict[str, Any]:
        """Fetch AOI program tracker pipeline"""
        return self._get("api/programs.php", {"machine": machine, "status": status, "limit": limit})


def main():
    parser = argparse.ArgumentParser(description="AOI System Python Automation Client")
    parser.add_argument("--url", default="http://localhost:8080/aoi-web-php", help="Base URL of AOI web system")
    subparsers = parser.add_subparsers(dest="command", help="Command to execute")

    # Command: status
    subparsers.add_parser("status", help="Check server connection and status")

    # Command: boards
    b_parser = subparsers.add_parser("boards", help="Query boards data")
    b_parser.add_argument("--zone", default="1", help="Zone (e.g. 1, 2, ALL)")
    b_parser.add_argument("--sheet", default="ALL", help="Shelf (e.g. S, M, L, ALL)")
    b_parser.add_argument("--limit", type=int, default=10, help="Number of records to fetch")

    # Command: programs
    p_parser = subparsers.add_parser("programs", help="Query programs data")
    p_parser.add_argument("--machine", default="ALL", help="Machine filter")
    p_parser.add_argument("--status", default="ALL", help="Status filter")
    p_parser.add_argument("--limit", type=int, default=10, help="Number of records to fetch")

    args = parser.parse_args()
    if not args.command:
        parser.print_help()
        sys.exit(1)

    client = AOIClient(base_url=args.url)

    try:
        if args.command == "status":
            res = client.get_status()
            print("=== AOI System Status ===")
            print(f"Connected to: {client.base_url}")
            print(f"Admin Configured: {res.get('configured')}")
            print(f"Is Admin: {res.get('is_admin')}")
            print(f"CSRF Session: {res.get('csrf')[:8]}...")

        elif args.command == "boards":
            res = client.get_boards(zone=args.zone, sheet=args.sheet, limit=args.limit)
            rows = res.get("data", [])
            print(f"=== Boards (Zone {args.zone}, Sheet {args.sheet}) Total: {res.get('total', len(rows))} ===")
            for b in rows:
                if isinstance(b, list):
                    # Tuple format: [code, shelf, product, side, boardType, status, pcs, magSlot, id, zoneKey]
                    code = b[0] if len(b) > 0 else "-"
                    shelf = b[1] if len(b) > 1 else "-"
                    product = b[2] if len(b) > 2 else "-"
                    status = b[5] if len(b) > 5 else "-"
                    print(f"[{code}] Shelf: {shelf} | Product: {product} | Status: {status}")
                elif isinstance(b, dict):
                    print(f"[{b.get('number_code')}] Shelf: {b.get('shelf_no')} | Barcode: {b.get('barcode')} | Status: {b.get('status')}")

        elif args.command == "programs":
            res = client.get_programs(machine=args.machine, status=args.status, limit=args.limit)
            print(f"=== AOI Programs (Total: {res.get('total', 0)}) ===")
            for p in res.get("data", []):
                print(f"[{p.get('id')}] {p.get('program_name')} ({p.get('machine')}) - Owner: {p.get('owner')} - Status: {p.get('status_label')}")

    except Exception as e:
        print(f"Error communicating with AOI system: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
