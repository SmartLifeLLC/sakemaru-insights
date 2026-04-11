"""
Extract module: reads data from ret_ source tables.

Data source mapping:
- ret_daily_sales: sales_amount, sales_qty, gross_profit, cost_amount (SOLE basis)
- ret_hourly_sales: hourly analysis
- ret_daily_settlement_summary: customer_count (auxiliary)
- ret_store_settlement_*: payment type analysis (auxiliary)
- ret_stores / items / item_categories: dimension data

Column types (after ret_ migration):
- item_code: unsigned int (商品コード)
- item_id: unsigned bigint nullable (items.id)
- category_code: unsigned smallint nullable (カテゴリコード)
- item_category_id: unsigned bigint nullable (item_categories.id)
"""

import logging
from datetime import date, timedelta
from typing import Optional

import polars as pl
from sqlalchemy import text
from sqlalchemy.engine import Engine

logger = logging.getLogger(__name__)


def extract_daily_sales(
    engine: Engine,
    start_date: date,
    end_date: date,
) -> pl.DataFrame:
    """Extract from ret_daily_sales (primary sales source)."""
    query = text("""
        SELECT
            slip_date AS business_date,
            ret_store_id,
            sales_ret_store_id,
            shipping_ret_store_id,
            item_code,
            item_id,
            category_code,
            item_category_id,
            sales_qty,
            sales_amount,
            return_qty,
            return_amount,
            gross_profit,
            cost_amount
        FROM ret_daily_sales
        WHERE slip_date BETWEEN :start_date AND :end_date
    """)
    with engine.connect() as conn:
        result = conn.execute(query, {"start_date": start_date, "end_date": end_date})
        rows = result.fetchall()
        columns = list(result.keys())

    if not rows:
        logger.warning(f"No daily sales data for {start_date} to {end_date}")
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "sales_ret_store_id": pl.UInt64,
            "shipping_ret_store_id": pl.UInt64,
            "item_code": pl.UInt32,
            "item_id": pl.UInt64,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "sales_qty": pl.Int32,
            "sales_amount": pl.Int32,
            "return_qty": pl.Int32,
            "return_amount": pl.Int32,
            "gross_profit": pl.Int32,
            "cost_amount": pl.Float64,
        })

    df = pl.DataFrame(
        [dict(zip(columns, row)) for row in rows],
    )
    logger.info(f"Extracted {len(df)} rows from ret_daily_sales ({start_date} to {end_date})")
    return df


def extract_hourly_sales(
    engine: Engine,
    start_date: date,
    end_date: date,
) -> pl.DataFrame:
    """Extract from ret_hourly_sales (hourly analysis source)."""
    query = text("""
        SELECT
            slip_date AS business_date,
            ret_store_id,
            time_slot,
            item_code,
            item_id,
            category_code,
            item_category_id,
            sales_qty,
            sales_amount,
            return_qty,
            return_amount,
            gross_profit,
            cost_amount
        FROM ret_hourly_sales
        WHERE slip_date BETWEEN :start_date AND :end_date
    """)
    with engine.connect() as conn:
        result = conn.execute(query, {"start_date": start_date, "end_date": end_date})
        rows = result.fetchall()
        columns = list(result.keys())

    if not rows:
        logger.warning(f"No hourly sales data for {start_date} to {end_date}")
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "time_slot": pl.Utf8,
            "item_code": pl.UInt32,
            "item_id": pl.UInt64,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "sales_qty": pl.Int32,
            "sales_amount": pl.Int32,
            "return_qty": pl.Int32,
            "return_amount": pl.Int32,
            "gross_profit": pl.Int32,
            "cost_amount": pl.Float64,
        })

    df = pl.DataFrame([dict(zip(columns, row)) for row in rows])
    logger.info(f"Extracted {len(df)} rows from ret_hourly_sales ({start_date} to {end_date})")
    return df


def extract_settlement_summary(
    engine: Engine,
    start_date: date,
    end_date: date,
) -> pl.DataFrame:
    """Extract customer_count from ret_daily_settlement_summary (per-register -> SUM for per-store)."""
    query = text("""
        SELECT
            slip_date AS business_date,
            ret_store_id,
            SUM(customer_count) AS customer_count
        FROM ret_daily_settlement_summary
        WHERE slip_date BETWEEN :start_date AND :end_date
        GROUP BY slip_date, ret_store_id
    """)
    with engine.connect() as conn:
        result = conn.execute(query, {"start_date": start_date, "end_date": end_date})
        rows = result.fetchall()
        columns = list(result.keys())

    if not rows:
        logger.warning(f"No settlement summary data for {start_date} to {end_date}")
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "customer_count": pl.Int64,
        })

    df = pl.DataFrame([dict(zip(columns, row)) for row in rows])
    logger.info(f"Extracted {len(df)} store-level settlement summaries ({start_date} to {end_date})")
    return df


def extract_payment_data(
    engine: Engine,
    start_date: date,
    end_date: date,
) -> pl.DataFrame:
    """Extract payment data from settlement tables, normalized to (date, store, payment_type, amount, count)."""
    queries = {
        "cash": text("""
            SELECT
                business_date,
                ret_store_id,
                'cash' AS payment_type,
                SUM(subtotal - voucher_amount - credit_amount - receivable_amount - store_credit_amount) AS amount,
                0 AS count
            FROM ret_store_settlement_registers
            WHERE business_date BETWEEN :start_date AND :end_date
            GROUP BY business_date, ret_store_id
        """),
        "credit": text("""
            SELECT
                business_date,
                ret_store_id,
                'credit' AS payment_type,
                SUM(credit_amount) AS amount,
                SUM(credit_count) AS count
            FROM ret_store_settlement_credits
            WHERE business_date BETWEEN :start_date AND :end_date
            GROUP BY business_date, ret_store_id
        """),
        "emoney": text("""
            SELECT
                business_date,
                ret_store_id,
                'emoney' AS payment_type,
                SUM(emoney_amount) AS amount,
                0 AS count
            FROM ret_store_settlement_emoney
            WHERE business_date BETWEEN :start_date AND :end_date
            GROUP BY business_date, ret_store_id
        """),
        "voucher": text("""
            SELECT
                business_date,
                ret_store_id,
                'voucher' AS payment_type,
                SUM(voucher_amount) AS amount,
                SUM(voucher_quantity) AS count
            FROM ret_store_settlement_vouchers
            WHERE business_date BETWEEN :start_date AND :end_date
            GROUP BY business_date, ret_store_id
        """),
        "receivable": text("""
            SELECT
                business_date,
                ret_store_id,
                'receivable' AS payment_type,
                SUM(receivable_amount) AS amount,
                COUNT(*) AS count
            FROM ret_store_settlement_receivables
            WHERE business_date BETWEEN :start_date AND :end_date
            GROUP BY business_date, ret_store_id
        """),
    }

    all_frames = []
    with engine.connect() as conn:
        for payment_type, query in queries.items():
            result = conn.execute(query, {"start_date": start_date, "end_date": end_date})
            rows = result.fetchall()
            columns = list(result.keys())
            if rows:
                df = pl.DataFrame([dict(zip(columns, row)) for row in rows])
                all_frames.append(df)

    if not all_frames:
        logger.warning(f"No payment data for {start_date} to {end_date}")
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "payment_type": pl.Utf8,
            "amount": pl.Int64,
            "count": pl.Int64,
        })

    df = pl.concat(all_frames, how="diagonal_relaxed")
    logger.info(f"Extracted {len(df)} payment rows ({start_date} to {end_date})")
    return df


def extract_stores(engine: Engine) -> pl.DataFrame:
    """Extract store dimension data from ret_stores."""
    query = text("""
        SELECT id, code AS store_code, name AS store_name
        FROM ret_stores
    """)
    with engine.connect() as conn:
        result = conn.execute(query)
        rows = result.fetchall()
        columns = list(result.keys())

    if not rows:
        return pl.DataFrame(schema={"id": pl.UInt64, "store_code": pl.Utf8, "store_name": pl.Utf8})

    df = pl.DataFrame([dict(zip(columns, row)) for row in rows])
    logger.info(f"Extracted {len(df)} stores")
    return df


def extract_items(engine: Engine) -> pl.DataFrame:
    """Extract item dimension data from shared items table.

    Returns item_code (int) and item_name for joining with ret_daily_sales.item_code.
    Note: ret_daily_sales.item_code is now unsigned int matching items.code directly.
    """
    query = text("""
        SELECT id AS item_id, code AS item_code, name AS item_name
        FROM items
        WHERE is_active = 1
    """)
    with engine.connect() as conn:
        result = conn.execute(query)
        rows = result.fetchall()
        columns = list(result.keys())

    if not rows:
        return pl.DataFrame(schema={
            "item_id": pl.UInt64,
            "item_code": pl.UInt32,
            "item_name": pl.Utf8,
        })

    df = pl.DataFrame([dict(zip(columns, row)) for row in rows])
    logger.info(f"Extracted {len(df)} items")
    return df


def extract_categories(engine: Engine) -> pl.DataFrame:
    """Extract category dimension data from shared item_categories table.

    Returns item_category_id (id), category_code, category_name.
    category_code has hierarchy duplicates, so item_category_id is the unique key.
    """
    query = text("""
        SELECT id AS item_category_id, code AS category_code, name AS category_name
        FROM item_categories
        WHERE is_active = 1
    """)
    with engine.connect() as conn:
        result = conn.execute(query)
        rows = result.fetchall()
        columns = list(result.keys())

    if not rows:
        return pl.DataFrame(schema={
            "item_category_id": pl.UInt64,
            "category_code": pl.UInt16,
            "category_name": pl.Utf8,
        })

    df = pl.DataFrame([dict(zip(columns, row)) for row in rows])
    logger.info(f"Extracted {len(df)} categories")
    return df
