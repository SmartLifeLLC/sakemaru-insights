"""
Load module: UPSERT data into ins_ tables.

Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotent writes.
All table names use 'ins_' prefix since Python connects directly to the DB.
"""

import logging
from datetime import datetime
from typing import Optional

import polars as pl
from sqlalchemy import text
from sqlalchemy.engine import Engine

from config import TABLE_PREFIX, BATCH_SIZE

logger = logging.getLogger(__name__)


def _upsert_batch(
    engine: Engine,
    table: str,
    columns: list[str],
    rows: list[dict],
    unique_columns: list[str],
    has_timestamps: bool = True,
) -> int:
    """Execute INSERT ... ON DUPLICATE KEY UPDATE for a batch of rows."""
    if not rows:
        return 0

    full_table = f"{TABLE_PREFIX}{table}"
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # Build column list (add timestamps only if table has them)
    all_cols = columns + (["created_at", "updated_at"] if has_timestamps else [])
    col_names = ", ".join(f"`{c}`" for c in all_cols)
    placeholders = ", ".join(f":{c}" for c in all_cols)

    # Build ON DUPLICATE KEY UPDATE clause (exclude unique key columns + created_at)
    update_cols = [c for c in columns if c not in unique_columns]
    if has_timestamps:
        update_cols.append("updated_at")
    update_clause = ", ".join(f"`{c}` = VALUES(`{c}`)" for c in update_cols)

    sql = f"""
        INSERT INTO `{full_table}` ({col_names})
        VALUES ({placeholders})
        ON DUPLICATE KEY UPDATE {update_clause}
    """

    # Add timestamps to each row if applicable
    if has_timestamps:
        for row in rows:
            row["created_at"] = now
            row["updated_at"] = now

    with engine.begin() as conn:
        conn.execute(text(sql), rows)

    return len(rows)


def _df_to_dicts(df: pl.DataFrame, columns: list[str]) -> list[dict]:
    """Convert Polars DataFrame to list of dicts with specified columns."""
    # Select only the columns we need, handling missing ones
    available = [c for c in columns if c in df.columns]
    sub = df.select(available)

    # Convert to list of dicts
    records = sub.to_dicts()

    # Ensure all requested columns exist (fill missing with None)
    for record in records:
        for col in columns:
            if col not in record:
                record[col] = None
        # Convert date objects to string for SQL
        for k, v in record.items():
            if hasattr(v, "isoformat"):
                record[k] = v.isoformat()

    return records


def upsert_dataframe(
    engine: Engine,
    table: str,
    df: pl.DataFrame,
    columns: list[str],
    unique_columns: list[str],
    batch_size: int = BATCH_SIZE,
    has_timestamps: bool = True,
) -> int:
    """UPSERT a Polars DataFrame into an ins_ table in batches."""
    if df.is_empty():
        logger.info(f"Skipping {TABLE_PREFIX}{table}: empty DataFrame")
        return 0

    records = _df_to_dicts(df, columns)
    total = 0

    for i in range(0, len(records), batch_size):
        batch = records[i : i + batch_size]
        count = _upsert_batch(engine, table, columns, batch, unique_columns, has_timestamps)
        total += count

    logger.info(f"Upserted {total} rows into {TABLE_PREFIX}{table}")
    return total


# ─── Table-specific loaders ───────────────────────────────────────


def load_sales_fact(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "ret_store_id", "sales_ret_store_id", "shipping_ret_store_id",
        "item_code", "item_id", "category_code", "item_category_id",
        "sales_qty", "sales_amount", "return_qty", "return_amount",
        "gross_profit", "cost_amount",
    ]
    unique = ["business_date", "ret_store_id", "item_code"]
    return upsert_dataframe(engine, "sales_fact", df, columns, unique)


def load_hourly_sales_fact(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "ret_store_id", "time_slot",
        "item_code", "item_id", "category_code", "item_category_id",
        "sales_qty", "sales_amount", "return_qty", "return_amount",
        "gross_profit", "cost_amount",
    ]
    unique = ["business_date", "ret_store_id", "item_code", "time_slot"]
    return upsert_dataframe(engine, "hourly_sales_fact", df, columns, unique)


def load_daily_store_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "ret_store_id", "store_name", "area",
        "sales_amount", "sales_qty", "return_amount", "return_qty",
        "gross_profit", "customer_count", "unit_price", "gross_profit_rate",
    ]
    unique = ["business_date", "ret_store_id"]
    return upsert_dataframe(engine, "daily_store_sales", df, columns, unique)


def load_daily_item_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "item_code", "item_id", "item_name",
        "category_code", "item_category_id", "category_name",
        "sales_amount", "sales_qty", "return_amount", "return_qty", "gross_profit",
    ]
    unique = ["business_date", "item_code"]
    return upsert_dataframe(engine, "daily_item_sales", df, columns, unique)


def load_daily_category_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "category_code", "item_category_id", "category_name",
        "sales_amount", "sales_qty", "return_amount", "gross_profit",
    ]
    unique = ["business_date", "category_code"]
    return upsert_dataframe(engine, "daily_category_sales", df, columns, unique)


def load_daily_store_item_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "ret_store_id", "store_name",
        "item_code", "item_id", "item_name",
        "category_code", "item_category_id", "category_name",
        "sales_amount", "sales_qty", "return_amount", "gross_profit",
    ]
    unique = ["business_date", "ret_store_id", "item_code"]
    return upsert_dataframe(engine, "daily_store_item_sales", df, columns, unique)


def load_hourly_store_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "ret_store_id", "store_name", "time_slot",
        "sales_amount", "sales_qty", "gross_profit", "customer_count",
    ]
    unique = ["business_date", "ret_store_id", "time_slot"]
    return upsert_dataframe(engine, "hourly_store_sales", df, columns, unique)


def load_daily_payment_summary(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "business_date", "ret_store_id", "store_name",
        "payment_type", "payment_label", "amount", "count",
    ]
    unique = ["business_date", "ret_store_id", "payment_type"]
    return upsert_dataframe(engine, "daily_payment_summary", df, columns, unique)


def load_monthly_store_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "year_month", "ret_store_id", "store_name", "area",
        "sales_amount", "sales_qty", "return_amount", "return_qty",
        "gross_profit", "customer_count", "unit_price", "gross_profit_rate",
    ]
    unique = ["year_month", "ret_store_id"]
    return upsert_dataframe(engine, "monthly_store_sales", df, columns, unique)


def load_monthly_item_sales(engine: Engine, df: pl.DataFrame) -> int:
    columns = [
        "year_month", "item_code", "item_id", "item_name",
        "category_code", "item_category_id", "category_name",
        "sales_amount", "sales_qty", "return_amount", "gross_profit",
    ]
    unique = ["year_month", "item_code"]
    return upsert_dataframe(engine, "monthly_item_sales", df, columns, unique)


def load_dim_stores(engine: Engine, df: pl.DataFrame) -> int:
    columns = ["store_code", "store_name", "area", "region"]
    unique = ["store_code"]
    return upsert_dataframe(engine, "dim_store", df, columns, unique)


def load_dim_items(engine: Engine, df: pl.DataFrame) -> int:
    columns = ["item_code", "item_name", "category_code", "brand"]
    unique = ["item_code"]
    return upsert_dataframe(engine, "dim_item", df, columns, unique)


def load_dim_categories(engine: Engine, df: pl.DataFrame) -> int:
    columns = ["category_code", "item_category_id", "category_name"]
    unique = ["category_code"]
    return upsert_dataframe(engine, "dim_category", df, columns, unique)


def load_dim_dates(engine: Engine, df: pl.DataFrame) -> int:
    """Special loader for dim_date: primary key is 'date', not auto-increment id."""
    if df.is_empty():
        return 0

    full_table = f"{TABLE_PREFIX}dim_date"
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    columns = ["date", "year", "month", "day", "weekday", "week_of_year"]

    records = _df_to_dicts(df, columns)

    sql = f"""
        INSERT INTO `{full_table}` (`date`, `year`, `month`, `day`, `weekday`, `week_of_year`)
        VALUES (:date, :year, :month, :day, :weekday, :week_of_year)
        ON DUPLICATE KEY UPDATE
            `year` = VALUES(`year`),
            `month` = VALUES(`month`),
            `day` = VALUES(`day`),
            `weekday` = VALUES(`weekday`),
            `week_of_year` = VALUES(`week_of_year`)
    """

    total = 0
    batch_size = BATCH_SIZE
    with engine.begin() as conn:
        for i in range(0, len(records), batch_size):
            batch = records[i : i + batch_size]
            conn.execute(text(sql), batch)
            total += len(batch)

    logger.info(f"Upserted {total} rows into {full_table}")
    return total


def load_dim_time_slots(engine: Engine, df: pl.DataFrame) -> int:
    columns = ["time_slot", "label"]
    unique = ["time_slot"]
    return upsert_dataframe(engine, "dim_time_slot", df, columns, unique, has_timestamps=False)
