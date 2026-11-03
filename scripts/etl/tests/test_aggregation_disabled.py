"""Database-free regression checks for retired Insights aggregation entrypoints."""

import contextlib
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import ModuleType
import unittest
from unittest import mock


ETL_DIR = Path(__file__).resolve().parents[1]
SCRIPT = ETL_DIR / "generate_retail_stats.py"
DISABLED_MESSAGE = "Insights aggregation is disabled; no statistics were generated."
TEST_ENV = {
    "DB_CONNECTION": "mysql",
    "DB_HOST": "127.0.0.1",
    "DB_PORT": "1",
    "DB_DATABASE": "sakemaru_test",
    "DB_USERNAME": "test_only",
    "DB_PASSWORD": "test_only",
    "PYTHONDONTWRITEBYTECODE": "1",
}


class DisabledCliTests(unittest.TestCase):
    def test_all_cli_modes_stop_without_site_packages_or_environment_file(self):
        cases = [
            [],
            ["--mode=daily"],
            ["--mode=realtime"],
            ["--mode=full"],
            ["--mode=daily", "--date=2026-01-01"],
            ["--mode=full", "--start=2026-01-01", "--end=2026-01-31"],
            ["--mode=daily", "--date=2026-01-01", "--skip-verify"],
            ["--mode=realtime", "--skip-verify"],
            ["--mode=full", "--skip-verify"],
        ]
        with tempfile.TemporaryDirectory(prefix="insights-disabled-cli-") as cwd:
            for arguments in cases:
                with self.subTest(arguments=arguments):
                    result = subprocess.run(
                        [sys.executable, "-S", "-B", str(SCRIPT), *arguments],
                        cwd=cwd,
                        env=TEST_ENV.copy(),
                        capture_output=True,
                        text=True,
                        timeout=10,
                        check=False,
                    )
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual(result.stdout.strip(), DISABLED_MESSAGE)
                    self.assertEqual(result.stderr, "")


class DisabledImportedEntrypointTests(unittest.TestCase):
    def setUp(self):
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        self.stack.enter_context(mock.patch.dict(os.environ, TEST_ENV, clear=True))
        self.stack.enter_context(mock.patch.object(sys, "path", [str(ETL_DIR), *sys.path]))
        self.stack.enter_context(mock.patch.object(sys, "dont_write_bytecode", True))

        # Never import config.py: it reads the Laravel .env and has production defaults.
        # Keep this test independent from any real project configuration or DB server.
        original_modules = {
            name: module
            for name, module in sys.modules.items()
            if name == "config" or name == "etl" or name.startswith("etl.")
        }

        def restore_modules():
            for name in list(sys.modules):
                if name == "config" or name == "etl" or name.startswith("etl."):
                    del sys.modules[name]
            sys.modules.update(original_modules)

        self.stack.callback(restore_modules)
        for name in original_modules:
            del sys.modules[name]

        config = ModuleType("config")
        config.DB_URL = "mysql+pymysql://test_only:test_only@127.0.0.1:1/sakemaru_test"
        config.TABLE_PREFIX = "ins_"
        config.BATCH_SIZE = 1000
        sys.modules["config"] = config

        # Block DB access before importing the actual implementation, including any
        # accidental connection introduced as an import-time side effect.
        self.create_engine = self.stack.enter_context(
            mock.patch("sqlalchemy.create_engine", side_effect=AssertionError("DB creation forbidden"))
        )
        self.connect = self.stack.enter_context(
            mock.patch("sqlalchemy.engine.Engine.connect", side_effect=AssertionError("DB connection forbidden"))
        )
        self.begin = self.stack.enter_context(
            mock.patch("sqlalchemy.engine.Engine.begin", side_effect=AssertionError("DB transaction forbidden"))
        )
        spec = importlib.util.spec_from_file_location("disabled_aggregation_under_test", SCRIPT)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.warning = self.stack.enter_context(mock.patch.object(self.module.logger, "warning"))

        self.extractors = [
            self.stack.enter_context(
                mock.patch.object(self.module, name, side_effect=AssertionError("Extraction forbidden"))
            )
            for name in (
                "extract_daily_sales", "extract_hourly_sales", "extract_settlement_summary",
                "extract_payment_data", "extract_stores", "extract_items", "extract_categories",
            )
        ]
        self.engine = mock.Mock(spec=["connect", "begin"])
        self.engine.connect.side_effect = AssertionError("DB connection forbidden")
        self.engine.begin.side_effect = AssertionError("DB transaction forbidden")

    def tearDown(self):
        for blocked in [
            self.create_engine, self.connect, self.begin,
            self.engine.connect, self.engine.begin, *self.extractors,
        ]:
            blocked.assert_not_called()

    def test_imported_main_stops_before_engine_creation_or_full_range_lookup(self):
        with mock.patch.object(self.module, "get_full_date_range") as full_range:
            with mock.patch.object(self.module, "verify_checksums") as checksum:
                for arguments in ([], ["--mode=daily"], ["--mode=realtime"], ["--mode=full"]):
                    with self.subTest(arguments=arguments):
                        self.warning.reset_mock()
                        with mock.patch.object(sys, "argv", [str(SCRIPT), *arguments]):
                            self.assertEqual(self.module.main(), 0)
                        self.warning.assert_called_once_with(DISABLED_MESSAGE)
                full_range.assert_not_called()
                checksum.assert_not_called()

    def test_imported_date_range_processor_stops_before_extracting(self):
        for mode in ("daily", "realtime", "full"):
            with self.subTest(mode=mode):
                self.assertEqual(
                    self.module.process_date_range(self.engine, mock.sentinel.start, mock.sentinel.end, mode),
                    {},
                )

    def test_imported_full_processor_does_not_iterate_or_call_date_range_processor(self):
        with mock.patch.object(self.module, "process_date_range") as process_range:
            self.assertEqual(
                self.module.run_full_in_chunks(self.engine, mock.sentinel.start, mock.sentinel.end),
                {},
            )
            process_range.assert_not_called()

if __name__ == "__main__":
    unittest.main()
