#!/usr/bin/env python3
"""
Main ETL script: ret_ -> ins_ retail statistics generation.

Usage:
    python generate_retail_stats.py --mode=daily [--date=YYYY-MM-DD]
    python generate_retail_stats.py --mode=realtime
    python generate_retail_stats.py --mode=full [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]

Modes:
    daily    - Process one day (default: yesterday). All tables.
    realtime - Current day only. hourly_store_sales + daily_store_sales.
    full     - Full period. All tables.
"""

import argparse
import logging
import sys
import time
from datetime import date, datetime, timedelta
from pathlib import Path
from decimal import Decimal

# Add scripts/etl to path for config import
sys.path.insert(0, str(Path(__file__).resolve().parent))

from config import DB_URL
from sqlalchemy import create_engine, text

from etl.extract import (
    extract_daily_sales,
    extract_hourly_sales,
    extract_settlement_summary,
    extract_payment_data,
    extract_stores,
    extract_items,
    extract_categories,
)
from etl.transform import (
    build_sales_fact,
    build_hourly_sales_fact,
    build_daily_store_sales,
    build_daily_item_sales,
    build_daily_category_sales,
    build_daily_store_item_sales,
    build_hourly_store_sales,
    build_daily_payment_summary,
    build_monthly_store_sales,
    build_monthly_item_sales,
    build_dim_stores,
    build_dim_items,
    build_dim_categories,
    build_dim_dates,
    build_dim_time_slots,
)
from etl.load import (
    load_sales_fact,
    load_hourly_sales_fact,
    load_daily_store_sales,
    load_daily_item_sales,
    load_daily_category_sales,
    load_daily_store_item_sales,
    load_hourly_store_sales,
    load_daily_payment_summary,
    load_monthly_store_sales,
    load_monthly_item_sales,
    load_dim_stores,
    load_dim_items,
    load_dim_categories,
    load_dim_dates,
    load_dim_time_slots,
)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("generate_retail_stats")


def verify_checksums(engine, start_date: date, end_date: date) -> bool:
    """Verify data integrity: SUM(sales_amount) from ret_ must match ins_."""
    logger.info("=" * 60)
    logger.info("CHECKSUM VERIFICATION")
    logger.info("=" * 60)

    checks_passed = True

    with engine.connect() as conn:
        # Check 1: ret_daily_sales vs ins_daily_store_sales
        ret_result = conn.execute(text("""
            SELECT
                COALESCE(SUM(sales_amount), 0) AS total_sales,
                COALESCE(SUM(sales_qty), 0) AS total_qty,
                COALESCE(SUM(gross_profit), 0) AS total_profit
            FROM ret_daily_sales
            WHERE slip_date BETWEEN :start AND :end
        """), {"start": start_date, "end": end_date}).fetchone()

        ins_store_result = conn.execute(text("""
            SELECT
                COALESCE(SUM(sales_amount), 0) AS total_sales,
                COALESCE(SUM(sales_qty), 0) AS total_qty,
                COALESCE(SUM(gross_profit), 0) AS total_profit
            FROM ins_daily_store_sales
            WHERE business_date BETWEEN :start AND :end
        """), {"start": start_date, "end": end_date}).fetchone()

        ins_item_result = conn.execute(text("""
            SELECT
                COALESCE(SUM(sales_amount), 0) AS total_sales,
                COALESCE(SUM(sales_qty), 0) AS total_qty,
                COALESCE(SUM(gross_profit), 0) AS total_profit
            FROM ins_daily_item_sales
            WHERE business_date BETWEEN :start AND :end
        """), {"start": start_date, "end": end_date}).fetchone()

    # Convert Decimal to int for comparison
    def to_int(v):
        if isinstance(v, Decimal):
            return int(v)
        return int(v) if v else 0

    ret_sales = to_int(ret_result[0])
    ret_qty = to_int(ret_result[1])
    ret_profit = to_int(ret_result[2])

    ins_store_sales = to_int(ins_store_result[0])
    ins_store_qty = to_int(ins_store_result[1])
    ins_store_profit = to_int(ins_store_result[2])

    ins_item_sales = to_int(ins_item_result[0])
    ins_item_qty = to_int(ins_item_result[1])
    ins_item_profit = to_int(ins_item_result[2])

    # Check 1: ret_daily_sales vs ins_daily_store_sales
    logger.info(f"[CHECK 1] ret_daily_sales vs ins_daily_store_sales ({start_date} to {end_date})")
    logger.info(f"  ret_daily_sales:      sales={ret_sales:>15,}  qty={ret_qty:>12,}  profit={ret_profit:>15,}")
    logger.info(f"  ins_daily_store_sales: sales={ins_store_sales:>15,}  qty={ins_store_qty:>12,}  profit={ins_store_profit:>15,}")

    if ret_sales == ins_store_sales and ret_qty == ins_store_qty and ret_profit == ins_store_profit:
        logger.info("  -> PASS: All values match")
    else:
        logger.error("  -> FAIL: Values do not match!")
        checks_passed = False

    # Check 2: ins_daily_store_sales vs ins_daily_item_sales
    logger.info(f"[CHECK 2] ins_daily_store_sales vs ins_daily_item_sales (cross-report consistency)")
    logger.info(f"  ins_daily_store_sales: sales={ins_store_sales:>15,}  qty={ins_store_qty:>12,}  profit={ins_store_profit:>15,}")
    logger.info(f"  ins_daily_item_sales:  sales={ins_item_sales:>15,}  qty={ins_item_qty:>12,}  profit={ins_item_profit:>15,}")

    if ins_store_sales == ins_item_sales and ins_store_qty == ins_item_qty and ins_store_profit == ins_item_profit:
        logger.info("  -> PASS: Cross-report totals match")
    else:
        logger.error("  -> FAIL: Cross-report totals do not match!")
        checks_passed = False

    logger.info("=" * 60)
    return checks_passed


def get_full_date_range(engine) -> tuple[date, date]:
    """Get the full date range from ret_daily_sales."""
    with engine.connect() as conn:
        result = conn.execute(text("""
            SELECT MIN(slip_date) AS min_date, MAX(slip_date) AS max_date
            FROM ret_daily_sales
        """)).fetchone()

    if result and result[0]:
        return result[0], result[1]
    raise RuntimeError("No data in ret_daily_sales")


def process_date_range(
    engine,
    start_date: date,
    end_date: date,
    mode: str,
) -> dict:
    """Process ETL for a date range. Returns stats dict."""
    stats = {}
    t0 = time.time()

    logger.info(f"Processing {mode} mode: {start_date} to {end_date}")

    # ── Extract ──────────────────────────────────────────────
    logger.info("--- EXTRACT ---")
    daily_sales = extract_daily_sales(engine, start_date, end_date)
    stores = extract_stores(engine)
    items = extract_items(engine)
    categories = extract_categories(engine)

    if mode == "realtime":
        hourly_sales = extract_hourly_sales(engine, start_date, end_date)
        settlement_summary = extract_settlement_summary(engine, start_date, end_date)
    else:
        hourly_sales = extract_hourly_sales(engine, start_date, end_date)
        settlement_summary = extract_settlement_summary(engine, start_date, end_date)
        payment_data = extract_payment_data(engine, start_date, end_date)

    # ── Transform ────────────────────────────────────────────
    logger.info("--- TRANSFORM ---")

    if mode == "realtime":
        # Realtime: only hourly_store_sales + daily_store_sales
        t_hourly_store = build_hourly_store_sales(hourly_sales, stores)
        t_daily_store = build_daily_store_sales(daily_sales, settlement_summary, stores)
    else:
        # Daily/Full: all tables
        t_sales_fact = build_sales_fact(daily_sales)
        t_hourly_fact = build_hourly_sales_fact(hourly_sales)
        t_daily_store = build_daily_store_sales(daily_sales, settlement_summary, stores)
        t_daily_item = build_daily_item_sales(daily_sales, items, categories)
        t_daily_category = build_daily_category_sales(daily_sales, categories)
        t_daily_store_item = build_daily_store_item_sales(daily_sales, stores, items, categories)
        t_hourly_store = build_hourly_store_sales(hourly_sales, stores)
        t_daily_payment = build_daily_payment_summary(payment_data, stores)
        t_monthly_store = build_monthly_store_sales(t_daily_store)
        t_monthly_item = build_monthly_item_sales(t_daily_item)

        # Dimensions
        t_dim_stores = build_dim_stores(stores)
        t_dim_items = build_dim_items(items, categories)
        t_dim_categories = build_dim_categories(categories)
        t_dim_dates = build_dim_dates(start_date, end_date)
        t_dim_time_slots = build_dim_time_slots(hourly_sales)

    # ── Load ─────────────────────────────────────────────────
    logger.info("--- LOAD ---")

    if mode == "realtime":
        stats["hourly_store_sales"] = load_hourly_store_sales(engine, t_hourly_store)
        stats["daily_store_sales"] = load_daily_store_sales(engine, t_daily_store)
    else:
        # Dimensions first
        stats["dim_store"] = load_dim_stores(engine, t_dim_stores)
        stats["dim_item"] = load_dim_items(engine, t_dim_items)
        stats["dim_category"] = load_dim_categories(engine, t_dim_categories)
        stats["dim_date"] = load_dim_dates(engine, t_dim_dates)
        stats["dim_time_slot"] = load_dim_time_slots(engine, t_dim_time_slots)

        # Facts
        stats["sales_fact"] = load_sales_fact(engine, t_sales_fact)
        stats["hourly_sales_fact"] = load_hourly_sales_fact(engine, t_hourly_fact)

        # Daily summaries
        stats["daily_store_sales"] = load_daily_store_sales(engine, t_daily_store)
        stats["daily_item_sales"] = load_daily_item_sales(engine, t_daily_item)
        stats["daily_category_sales"] = load_daily_category_sales(engine, t_daily_category)
        stats["daily_store_item_sales"] = load_daily_store_item_sales(engine, t_daily_store_item)
        stats["hourly_store_sales"] = load_hourly_store_sales(engine, t_hourly_store)
        stats["daily_payment_summary"] = load_daily_payment_summary(engine, t_daily_payment)

        # Monthly summaries
        stats["monthly_store_sales"] = load_monthly_store_sales(engine, t_monthly_store)
        stats["monthly_item_sales"] = load_monthly_item_sales(engine, t_monthly_item)

    elapsed = time.time() - t0
    logger.info(f"ETL completed in {elapsed:.1f}s")
    return stats


def run_full_in_chunks(engine, start_date: date, end_date: date, chunk_days: int = 30):
    """Run full mode by processing in monthly chunks to avoid memory issues."""
    all_stats = {}
    current = start_date

    while current <= end_date:
        chunk_end = min(current + timedelta(days=chunk_days - 1), end_date)
        logger.info(f"Processing chunk: {current} to {chunk_end}")
        chunk_stats = process_date_range(engine, current, chunk_end, "daily")

        for k, v in chunk_stats.items():
            all_stats[k] = all_stats.get(k, 0) + v

        current = chunk_end + timedelta(days=1)

    return all_stats


def main():
    parser = argparse.ArgumentParser(description="Generate retail statistics (ret_ -> ins_)")
    parser.add_argument("--mode", choices=["daily", "realtime", "full"], default="daily",
                        help="Processing mode (default: daily)")
    parser.add_argument("--date", type=str, default=None,
                        help="Target date for daily mode (YYYY-MM-DD, default: yesterday)")
    parser.add_argument("--start", type=str, default=None,
                        help="Start date for full mode (YYYY-MM-DD)")
    parser.add_argument("--end", type=str, default=None,
                        help="End date for full mode (YYYY-MM-DD)")
    parser.add_argument("--skip-verify", action="store_true",
                        help="Skip checksum verification")

    args = parser.parse_args()

    engine = create_engine(DB_URL, pool_pre_ping=True)

    # Determine date range
    if args.mode == "daily":
        if args.date:
            target = datetime.strptime(args.date, "%Y-%m-%d").date()
        else:
            target = date.today() - timedelta(days=1)
        start_date = target
        end_date = target

    elif args.mode == "realtime":
        start_date = date.today()
        end_date = date.today()

    elif args.mode == "full":
        if args.start and args.end:
            start_date = datetime.strptime(args.start, "%Y-%m-%d").date()
            end_date = datetime.strptime(args.end, "%Y-%m-%d").date()
        else:
            start_date, end_date = get_full_date_range(engine)
            logger.info(f"Full range from DB: {start_date} to {end_date}")

    logger.info(f"=== ETL Start: mode={args.mode}, range={start_date} to {end_date} ===")

    # Process
    if args.mode == "full":
        stats = run_full_in_chunks(engine, start_date, end_date)
    else:
        stats = process_date_range(engine, start_date, end_date, args.mode)

    # Summary
    logger.info("--- RESULTS ---")
    total_rows = 0
    for table, count in stats.items():
        logger.info(f"  {table}: {count:,} rows")
        total_rows += count
    logger.info(f"  TOTAL: {total_rows:,} rows")

    # Verify (skip in realtime mode — only daily_store_sales + hourly_store_sales
    # are updated, so cross-report check against daily_item_sales would always fail)
    if args.mode == "realtime":
        logger.info("Checksum verification skipped (realtime mode)")
    elif not args.skip_verify:
        ok = verify_checksums(engine, start_date, end_date)
        if not ok:
            logger.error("CHECKSUM VERIFICATION FAILED")
            sys.exit(1)
    else:
        logger.info("Checksum verification skipped")

    logger.info("=== ETL Complete ===")
    sys.exit(0)


if __name__ == "__main__":
    main()
