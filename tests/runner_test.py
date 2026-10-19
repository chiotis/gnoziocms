"""Regression checks for run.sh's exit codes and diagnostic logs, without running the CMS."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="faros-runner-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "tests/js/node_modules/jsdom").mkdir(parents=True)
        (self.root / "bin").mkdir()
        shutil.copy(Path(__file__).with_name("run.sh"), self.root / "tests/run.sh")
        stub = """#!/usr/bin/env bash
if [[ "$1" == scripts/check-blocks.php ]]; then
  echo 'Checked content: 0 problems'
  if [[ "${RUNNER_CASE:-}" == content-exit ]]; then exit 7; fi
  exit 0
fi
echo 'ok   stub assertion'
if [[ "$1" == tests/unit/blocks.php || "$1" == tests/js/serializer.test.js ]]; then
  case "${RUNNER_CASE:-}" in
    failed-assertion) echo 'FAIL assertion'; echo 'ALL PASSED'; exit 0 ;;
    missing-summary) exit 0 ;;
    error-exit)
      echo 'ALL PASSED'
      for i in {1..40}; do echo "diagnostic $i"; done
      echo 'stderr diagnostic' >&2
      exit 7 ;;
  esac
fi
echo 'ALL PASSED'
"""
        for name in ("php", "node"):
            path = self.root / "bin" / name
            path.write_text(stub)
            path.chmod(0o755)
        npm = self.root / "bin/npm"
        npm.write_text("#!/usr/bin/env bash\necho 'npm unavailable' >&2\nexit 23\n")
        npm.chmod(0o755)

    def real_tools(self, **tools):
        """A folder (outside the copied tree) with links to real programs, to put before the stubs on PATH."""
        folder = tempfile.TemporaryDirectory(prefix="faros-tools-")
        self.addCleanup(folder.cleanup)
        for name, path in tools.items():
            (Path(folder.name) / name).symlink_to(path)
        return Path(folder.name)

    def run_suite(self, mode="unit", case="", extra=None):
        env = os.environ.copy()
        env.update(PATH=str(self.root / "bin") + os.pathsep + env["PATH"],
                   FAROS_TEST_LOG_DIR=str(self.root / "logs"), RUNNER_CASE=case, GNOZIO_TESTS_SKIP_PREFLIGHT="1")
        env.update(extra or {})
        return subprocess.run(["bash", str(self.root / "tests/run.sh"), mode],
                              env=env, capture_output=True, text=True, timeout=30)

    def test_success(self):
        result = self.run_suite()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("ALL GOOD", result.stdout)

    def test_errors_cannot_pass_even_with_success_text(self):
        for mode, case in (("unit", "error-exit"), ("js", "error-exit"),
                           ("unit", "failed-assertion"), ("unit", "missing-summary"),
                           ("unit", "content-exit")):
            with self.subTest(mode=mode, case=case):
                result = self.run_suite(mode, case)
                self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
                self.assertIn("FAILED:", result.stdout)
                self.assertNotIn("ALL GOOD", result.stdout)

    def test_log_keeps_output_beyond_console_excerpt_and_stderr(self):
        result = self.run_suite(case="error-exit")
        self.assertEqual(result.returncode, 1)
        log = (self.root / "logs/unit-blocks.log").read_text()
        self.assertIn("diagnostic 40", log)
        self.assertIn("stderr diagnostic", log)
        self.assertNotIn("diagnostic 40", result.stdout)

    def test_npm_failure_keeps_diagnostics(self):
        shutil.rmtree(self.root / "tests/js/node_modules")
        result = self.run_suite("js")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("npm unavailable", (self.root / "logs/js-npm-install.log").read_text())

    def test_http_port_failure_stops_before_running_tests(self):
        python = self.root / "bin/python3"
        python.write_text("#!/usr/bin/env bash\nif [[ \"$1\" == -c ]]; then exit 11; fi\nexit 0\n")
        python.chmod(0o755)
        result = self.run_suite("http")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("FAILED: HTTP port", result.stdout)
        self.assertNotIn("HTTP tests (temporary copy", result.stdout)


    def test_preflight_refuses_a_wrong_environment_before_running_anything(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("php is not installed")
        (self.root / "tests/preflight.php").write_text((Path(__file__).with_name("preflight.php")).read_text())
        (self.root / "composer.json").write_text('{"require": {"php": ">=99.1", "ext-nonexistent": "*"}}')
        (self.root / "installer.php").write_text("<?php const EXTENSIONS = ['zip' => true];")
        real = self.real_tools(php=php)   # the real PHP instead of the stub, so the version check is real
        for mode in ("unit", "all"):
            with self.subTest(mode=mode):
                result = self.run_suite(mode, extra={"GNOZIO_TESTS_SKIP_PREFLIGHT": "", "PATH": f"{real}{os.pathsep}{os.environ['PATH']}"})
                self.assertEqual(result.returncode, 3, result.stdout + result.stderr)
                self.assertRegex(result.stdout, r"PHP 99\.1 or newer is needed; this is \d")
                self.assertIn("the PHP extension nonexistent is missing", result.stdout)
                self.assertNotIn("Unit checks", result.stdout)
                self.assertNotIn("ALL GOOD", result.stdout)

    def test_preflight_can_be_skipped_for_experiments(self):
        (self.root / "composer.json").write_text('{"require": {"php": ">=99.1"}}')
        result = self.run_suite()   # the helper sets GNOZIO_TESTS_SKIP_PREFLIGHT=1
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_a_hanging_http_test_times_out_alone_and_skips_are_counted(self):
        php, python = shutil.which("php"), shutil.which("python3")
        if not (php and python and shutil.which("curl")):
            self.skipTest("php, python3 and curl are needed")
        shutil.copy(Path(__file__).with_name("with_timeout.py"), self.root / "tests/with_timeout.py")
        (self.root / "public").mkdir()
        (self.root / "public/index.php").write_text("<?php echo 'ok';")
        (self.root / "tests/fixtures/content").mkdir(parents=True)
        (self.root / "tests/http").mkdir()
        (self.root / "tests/http/search_test.py").write_text("import time\nprint('ok   one')\nprint('skip two  (skipped: one failed)', flush=True)\ntime.sleep(60)\n")
        (self.root / "tests/http/editor_test.py").write_text("print('ok   one')\nprint('skip two  (skipped: x failed)')\nprint('ALL PASSED')\n")
        real = self.real_tools(php=php, python3=python)
        extra = {"PATH": f"{real}{os.pathsep}{os.environ['PATH']}", "GNOZIO_TEST_TIMEOUT": "2"}
        result = self.run_suite("search", extra=extra)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("TIMEOUT search (2 s)", result.stdout)
        self.assertIn("FAILED: search (TIMEOUT)", result.stdout)
        result = self.run_suite("editor", extra=extra)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("1 checks, 1 skipped", result.stdout)
        self.assertIn("1 checks skipped", result.stdout)
        self.assertIn("ALL GOOD", result.stdout)


if __name__ == "__main__":
    unittest.main()
