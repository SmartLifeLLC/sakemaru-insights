"""
DB connection configuration.
Reads .env from the Laravel project root.
"""

import os
from pathlib import Path
from dotenv import load_dotenv

# Find project root (.env is at the Laravel project root)
_script_dir = Path(__file__).resolve().parent
_project_root = _script_dir.parent.parent
_env_path = _project_root / ".env"

if _env_path.exists():
    load_dotenv(_env_path)
else:
    raise FileNotFoundError(f".env not found at {_env_path}")

DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = os.getenv("DB_PORT", "3306")
DB_DATABASE = os.getenv("DB_DATABASE", "sakemaru_hana_prod")
DB_USERNAME = os.getenv("DB_USERNAME", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")

# Python connects directly - use ins_ prefix in table names
TABLE_PREFIX = "ins_"

# SQLAlchemy connection URL
DB_URL = f"mysql+pymysql://{DB_USERNAME}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_DATABASE}?charset=utf8mb4"

# Batch size for upsert
BATCH_SIZE = 1000
