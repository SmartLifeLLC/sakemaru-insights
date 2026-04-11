"""
Transform module: aggregate and compute derived metrics.

Rules:
- sales/qty/profit: ONLY from ret_daily_sales
- hourly: ONLY from ret_hourly_sales
- payment: from settlement tables
- customer_count: from ret_daily_settlement_summary
- unit_price = sales_amount / customer_count (when customer_count > 0)
- gross_profit_rate = gross_profit / sales_amount * 100 (when sales_amount > 0)

Join keys (after ret_ migration):
- item: item_id (bigint) → items.id for item_name lookup
- category: item_category_id (bigint) → item_categories.id for category_name lookup
- store: ret_store_id (bigint) → ret_stores.id for store_name lookup
"""

import logging
from typing import Optional

import polars as pl

logger = logging.getLogger(__name__)

PAYMENT_LABELS = {
    "cash": "現金",
    "credit": "クレジット",
    "emoney": "電子マネー",
    "voucher": "金券",
    "receivable": "売掛",
    "charge": "チャージ",
    "sales_voucher": "商品券",
}


def build_sales_fact(daily_sales: pl.DataFrame) -> pl.DataFrame:
    """Build ins_sales_fact from ret_daily_sales (day x store x item)."""
    if daily_sales.is_empty():
        return daily_sales

    fact = daily_sales.group_by(
        ["business_date", "ret_store_id", "item_code"]
    ).agg([
        pl.col("sales_ret_store_id").first(),
        pl.col("shipping_ret_store_id").first(),
        pl.col("item_id").first(),
        pl.col("category_code").first(),
        pl.col("item_category_id").first(),
        pl.col("sales_qty").sum(),
        pl.col("sales_amount").sum(),
        pl.col("return_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("gross_profit").sum(),
        pl.col("cost_amount").sum(),
    ])

    logger.info(f"Built sales_fact: {len(fact)} rows")
    return fact


def build_hourly_sales_fact(hourly_sales: pl.DataFrame) -> pl.DataFrame:
    """Build ins_hourly_sales_fact from ret_hourly_sales (day x store x item x time_slot)."""
    if hourly_sales.is_empty():
        return hourly_sales

    fact = hourly_sales.group_by(
        ["business_date", "ret_store_id", "item_code", "time_slot"]
    ).agg([
        pl.col("item_id").first(),
        pl.col("category_code").first(),
        pl.col("item_category_id").first(),
        pl.col("sales_qty").sum(),
        pl.col("sales_amount").sum(),
        pl.col("return_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("gross_profit").sum(),
        pl.col("cost_amount").sum(),
    ])

    logger.info(f"Built hourly_sales_fact: {len(fact)} rows")
    return fact


def build_daily_store_sales(
    daily_sales: pl.DataFrame,
    settlement_summary: pl.DataFrame,
    stores: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_daily_store_sales: day x store with customer_count from settlement."""
    if daily_sales.is_empty():
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "store_name": pl.Utf8,
            "area": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "return_qty": pl.Int64,
            "gross_profit": pl.Int64,
            "customer_count": pl.Int64,
            "unit_price": pl.Int64,
            "gross_profit_rate": pl.Float64,
        })

    agg = daily_sales.group_by(["business_date", "ret_store_id"]).agg([
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("return_qty").sum(),
        pl.col("gross_profit").sum(),
    ])

    # Join customer_count from settlement
    if not settlement_summary.is_empty():
        settlement_summary = settlement_summary.select([
            "business_date", "ret_store_id", "customer_count"
        ])
        agg = agg.join(
            settlement_summary,
            on=["business_date", "ret_store_id"],
            how="left",
        )
    else:
        agg = agg.with_columns(pl.lit(0).alias("customer_count"))

    agg = agg.with_columns(
        pl.col("customer_count").fill_null(0)
    )

    # Join store dimension
    if not stores.is_empty():
        store_dim = stores.select([
            pl.col("id").alias("ret_store_id"),
            "store_name",
        ])
        agg = agg.join(store_dim, on="ret_store_id", how="left")
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("store_name"))

    # area is not available in ret_stores, set to null
    agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("area"))

    # Compute derived metrics
    agg = agg.with_columns([
        pl.when(pl.col("customer_count") > 0)
        .then((pl.col("sales_amount") / pl.col("customer_count")).cast(pl.Int64))
        .otherwise(0)
        .alias("unit_price"),
        pl.when(pl.col("sales_amount") > 0)
        .then((pl.col("gross_profit") / pl.col("sales_amount") * 100).round(2))
        .otherwise(0.0)
        .alias("gross_profit_rate"),
    ])

    logger.info(f"Built daily_store_sales: {len(agg)} rows")
    return agg


def build_daily_item_sales(
    daily_sales: pl.DataFrame,
    items: pl.DataFrame,
    categories: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_daily_item_sales: day x item.

    Join item_name via item_id, category_name via item_category_id.
    """
    if daily_sales.is_empty():
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "item_code": pl.UInt32,
            "item_id": pl.UInt64,
            "item_name": pl.Utf8,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "category_name": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "return_qty": pl.Int64,
            "gross_profit": pl.Int64,
        })

    agg = daily_sales.group_by(["business_date", "item_code"]).agg([
        pl.col("item_id").first(),
        pl.col("category_code").first(),
        pl.col("item_category_id").first(),
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("return_qty").sum(),
        pl.col("gross_profit").sum(),
    ])

    # Join item name via item_id
    if not items.is_empty():
        agg = agg.join(
            items.select(["item_id", "item_name"]),
            on="item_id",
            how="left",
        )
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("item_name"))

    # Join category name via item_category_id
    if not categories.is_empty():
        agg = agg.join(
            categories.select(["item_category_id", "category_name"]),
            on="item_category_id",
            how="left",
        )
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("category_name"))

    logger.info(f"Built daily_item_sales: {len(agg)} rows")
    return agg


def build_daily_category_sales(
    daily_sales: pl.DataFrame,
    categories: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_daily_category_sales: day x category.

    Join category_name via item_category_id.
    """
    if daily_sales.is_empty():
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "category_name": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "gross_profit": pl.Int64,
        })

    # Filter out null category_code
    filtered = daily_sales.filter(pl.col("category_code").is_not_null())
    if filtered.is_empty():
        logger.warning("No data with valid category_code for daily_category_sales")
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "category_name": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "gross_profit": pl.Int64,
        })

    agg = filtered.group_by(["business_date", "category_code"]).agg([
        pl.col("item_category_id").first(),
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("gross_profit").sum(),
    ])

    if not categories.is_empty():
        agg = agg.join(
            categories.select(["item_category_id", "category_name"]),
            on="item_category_id",
            how="left",
        )
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("category_name"))

    logger.info(f"Built daily_category_sales: {len(agg)} rows")
    return agg


def build_daily_store_item_sales(
    daily_sales: pl.DataFrame,
    stores: pl.DataFrame,
    items: pl.DataFrame,
    categories: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_daily_store_item_sales: day x store x item.

    Join item_name via item_id, category_name via item_category_id.
    """
    if daily_sales.is_empty():
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "store_name": pl.Utf8,
            "item_code": pl.UInt32,
            "item_id": pl.UInt64,
            "item_name": pl.Utf8,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "category_name": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "gross_profit": pl.Int64,
        })

    agg = daily_sales.group_by(["business_date", "ret_store_id", "item_code"]).agg([
        pl.col("item_id").first(),
        pl.col("category_code").first(),
        pl.col("item_category_id").first(),
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("gross_profit").sum(),
    ])

    # Join store name
    if not stores.is_empty():
        store_dim = stores.select([pl.col("id").alias("ret_store_id"), "store_name"])
        agg = agg.join(store_dim, on="ret_store_id", how="left")
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("store_name"))

    # Join item name via item_id
    if not items.is_empty():
        agg = agg.join(
            items.select(["item_id", "item_name"]),
            on="item_id",
            how="left",
        )
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("item_name"))

    # Join category name via item_category_id
    if not categories.is_empty():
        agg = agg.join(
            categories.select(["item_category_id", "category_name"]),
            on="item_category_id",
            how="left",
        )
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("category_name"))

    logger.info(f"Built daily_store_item_sales: {len(agg)} rows")
    return agg


def build_hourly_store_sales(
    hourly_sales: pl.DataFrame,
    stores: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_hourly_store_sales: day x store x time_slot."""
    if hourly_sales.is_empty():
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "store_name": pl.Utf8,
            "time_slot": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "gross_profit": pl.Int64,
            "customer_count": pl.Int64,
        })

    agg = hourly_sales.group_by(["business_date", "ret_store_id", "time_slot"]).agg([
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("gross_profit").sum(),
    ])

    # customer_count at hourly level is not readily available - set to 0
    agg = agg.with_columns(pl.lit(0).alias("customer_count"))

    if not stores.is_empty():
        store_dim = stores.select([pl.col("id").alias("ret_store_id"), "store_name"])
        agg = agg.join(store_dim, on="ret_store_id", how="left")
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("store_name"))

    logger.info(f"Built hourly_store_sales: {len(agg)} rows")
    return agg


def build_daily_payment_summary(
    payment_data: pl.DataFrame,
    stores: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_daily_payment_summary: day x store x payment_type."""
    if payment_data.is_empty():
        return pl.DataFrame(schema={
            "business_date": pl.Date,
            "ret_store_id": pl.UInt64,
            "store_name": pl.Utf8,
            "payment_type": pl.Utf8,
            "payment_label": pl.Utf8,
            "amount": pl.Int64,
            "count": pl.Int64,
        })

    agg = payment_data.group_by(["business_date", "ret_store_id", "payment_type"]).agg([
        pl.col("amount").sum(),
        pl.col("count").sum(),
    ])

    # Add payment label
    agg = agg.with_columns(
        pl.col("payment_type").replace_strict(
            PAYMENT_LABELS, default="その他"
        ).alias("payment_label")
    )

    if not stores.is_empty():
        store_dim = stores.select([pl.col("id").alias("ret_store_id"), "store_name"])
        agg = agg.join(store_dim, on="ret_store_id", how="left")
    else:
        agg = agg.with_columns(pl.lit(None).cast(pl.Utf8).alias("store_name"))

    logger.info(f"Built daily_payment_summary: {len(agg)} rows")
    return agg


def build_monthly_store_sales(
    daily_store_sales: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_monthly_store_sales from daily_store_sales: month x store."""
    if daily_store_sales.is_empty():
        return pl.DataFrame(schema={
            "year_month": pl.Utf8,
            "ret_store_id": pl.UInt64,
            "store_name": pl.Utf8,
            "area": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "return_qty": pl.Int64,
            "gross_profit": pl.Int64,
            "customer_count": pl.Int64,
            "unit_price": pl.Int64,
            "gross_profit_rate": pl.Float64,
        })

    df = daily_store_sales.with_columns(
        pl.col("business_date").dt.strftime("%Y-%m").alias("year_month")
    )

    agg = df.group_by(["year_month", "ret_store_id"]).agg([
        pl.col("store_name").first(),
        pl.col("area").first(),
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("return_qty").sum(),
        pl.col("gross_profit").sum(),
        pl.col("customer_count").sum(),
    ])

    agg = agg.with_columns([
        pl.when(pl.col("customer_count") > 0)
        .then((pl.col("sales_amount") / pl.col("customer_count")).cast(pl.Int64))
        .otherwise(0)
        .alias("unit_price"),
        pl.when(pl.col("sales_amount") > 0)
        .then((pl.col("gross_profit") / pl.col("sales_amount") * 100).round(2))
        .otherwise(0.0)
        .alias("gross_profit_rate"),
    ])

    logger.info(f"Built monthly_store_sales: {len(agg)} rows")
    return agg


def build_monthly_item_sales(
    daily_item_sales: pl.DataFrame,
) -> pl.DataFrame:
    """Build ins_monthly_item_sales from daily_item_sales: month x item."""
    if daily_item_sales.is_empty():
        return pl.DataFrame(schema={
            "year_month": pl.Utf8,
            "item_code": pl.UInt32,
            "item_id": pl.UInt64,
            "item_name": pl.Utf8,
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "category_name": pl.Utf8,
            "sales_amount": pl.Int64,
            "sales_qty": pl.Int64,
            "return_amount": pl.Int64,
            "gross_profit": pl.Int64,
        })

    df = daily_item_sales.with_columns(
        pl.col("business_date").dt.strftime("%Y-%m").alias("year_month")
    )

    agg = df.group_by(["year_month", "item_code"]).agg([
        pl.col("item_id").first(),
        pl.col("item_name").first(),
        pl.col("category_code").first(),
        pl.col("item_category_id").first(),
        pl.col("category_name").first(),
        pl.col("sales_amount").sum(),
        pl.col("sales_qty").sum(),
        pl.col("return_amount").sum(),
        pl.col("gross_profit").sum(),
    ])

    logger.info(f"Built monthly_item_sales: {len(agg)} rows")
    return agg


def build_dim_stores(stores: pl.DataFrame) -> pl.DataFrame:
    """Build ins_dim_store from ret_stores."""
    if stores.is_empty():
        return pl.DataFrame(schema={
            "store_code": pl.Utf8,
            "store_name": pl.Utf8,
            "area": pl.Utf8,
            "region": pl.Utf8,
        })

    dim = stores.select([
        "store_code",
        "store_name",
    ]).with_columns([
        pl.lit(None).cast(pl.Utf8).alias("area"),
        pl.lit(None).cast(pl.Utf8).alias("region"),
    ])

    logger.info(f"Built dim_store: {len(dim)} rows")
    return dim


def build_dim_items(items: pl.DataFrame, categories: pl.DataFrame) -> pl.DataFrame:
    """Build ins_dim_item."""
    if items.is_empty():
        return pl.DataFrame(schema={
            "item_code": pl.UInt32,
            "item_name": pl.Utf8,
            "category_code": pl.UInt16,
            "brand": pl.Utf8,
        })

    dim = items.select(["item_code", "item_name"]).with_columns([
        pl.lit(None).cast(pl.UInt16).alias("category_code"),
        pl.lit(None).cast(pl.Utf8).alias("brand"),
    ])

    logger.info(f"Built dim_item: {len(dim)} rows")
    return dim


def build_dim_categories(categories: pl.DataFrame) -> pl.DataFrame:
    """Build ins_dim_category."""
    if categories.is_empty():
        return pl.DataFrame(schema={
            "category_code": pl.UInt16,
            "item_category_id": pl.UInt64,
            "category_name": pl.Utf8,
        })

    dim = categories.select(["category_code", "item_category_id", "category_name"])
    logger.info(f"Built dim_category: {len(dim)} rows")
    return dim


def build_dim_dates(start_date, end_date) -> pl.DataFrame:
    """Build ins_dim_date for the given date range."""
    import datetime

    dates = []
    current = start_date
    while current <= end_date:
        dates.append({
            "date": current,
            "year": current.year,
            "month": current.month,
            "day": current.day,
            "weekday": current.weekday(),  # 0=Monday
            "week_of_year": current.isocalendar()[1],
        })
        current += datetime.timedelta(days=1)

    if not dates:
        return pl.DataFrame(schema={
            "date": pl.Date,
            "year": pl.Int16,
            "month": pl.Int8,
            "day": pl.Int8,
            "weekday": pl.Int8,
            "week_of_year": pl.Int8,
        })

    df = pl.DataFrame(dates)
    logger.info(f"Built dim_date: {len(df)} rows")
    return df


def build_dim_time_slots(hourly_sales: pl.DataFrame) -> pl.DataFrame:
    """Build ins_dim_time_slot from unique time_slots in hourly_sales."""
    if hourly_sales.is_empty():
        return pl.DataFrame(schema={
            "time_slot": pl.Utf8,
            "label": pl.Utf8,
        })

    slots = hourly_sales.select("time_slot").unique().sort("time_slot")
    slots = slots.with_columns(
        pl.col("time_slot").alias("label")
    )

    logger.info(f"Built dim_time_slot: {len(slots)} rows")
    return slots
