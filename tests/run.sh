#!/usr/bin/env bash
#
# Runs the checks:
#   tests/run.sh            everything
#   tests/run.sh unit       the PHP checks only (fast, no server)
#   tests/run.sh js         the JavaScript checks only (the visual editor's serializer and toolbar; needs Node 20 or later)
#   tests/run.sh http       the browser-level tests only
#   tests/run.sh redirects  one HTTP test by name (editor, roles, import, redirects, save, history, taxonomies, links, delete, blocks, hero_layouts, mobile_menu, bottom_bar, logs, users_tabs, sidebar, sidebar_brand, theme_page, media_upload, upload_limits, notifications, bad_yaml, custom_roles, storage, storage_cache, line_endings, media_usage, media_picker, admin_a11y, seo_jsonld, robots, media_usage_cache, update_install, setup, fresh_install, catalogue, menu_editor, form_builder, form_translation, layouts, header_footer, branding, custom_taxonomies, type_options, demo_content, hero_video, seo, analytics, translations, content_types, media, dashboard, editor_visual, youtube_playlist blocks_pack geo clean_addresses footer_blocks single_templates relations updates_screen backup_schedule backup_remote feed omni category_tree form_upload block_widths entry_preview block_reorder block_containers accordion faq_initial palettes branding_fonts installer)
#
# The HTTP tests never touch your site: they run against a temporary copy of the code with the small
# content set in tests/fixtures, on a free local port, and remove it afterwards. The server runs with the functions that
# shared hosts often switch off (set_time_limit, ignore_user_abort, disk_free_space, opcache_reset, curl_exec) disabled, because
# calling a disabled function is a fatal error and the code must cope. They need PHP and Python 3
# (standard library only).
#
# Before anything runs, preflight() checks the environment (PHP version and extensions from composer.json and the installer,
# python3 and curl for the HTTP tests, Node 20 for the js group) and stops with exit code 3 and one line per problem.
# GNOZIO_TESTS_SKIP_PREFLIGHT=1 turns that off for deliberate experiments. Each HTTP test is also limited in time:
# GNOZIO_TEST_TIMEOUT seconds (default 180) or what test_timeout() gives it; one that runs out is reported as
# "TIMEOUT <name> (<n> s)" and the rest carry on.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODE="${1:-all}"
FAILED=()
TOTAL_OK=0
LOG_DIR="${FAROS_TEST_LOG_DIR:-}"
PHASE=""
TOTAL_SKIPPED=0
RUN_TIMEOUT=""
HTTP_WORK=""
HTTP_PID=""
if [ -n "$LOG_DIR" ]; then
  mkdir -p "$LOG_DIR" || exit 1
  LOG_DIR="$(cd "$LOG_DIR" && pwd)" || exit 1
fi

PREFLIGHT_EXIT=3
preflight() { # which groups will run: unit, js, http (any of them)
  [ "${GNOZIO_TESTS_SKIP_PREFLIGHT:-}" = "1" ] && return 0
  local problems="" groups=" $* "
  if ! command -v php >/dev/null 2>&1; then
    problems+="php is not installed or not on PATH (PHP $(sed -n 's/.*"php": *"[^0-9]*\([0-9.]*\)".*/\1/p' "$ROOT/composer.json" | head -1) or newer is needed)"$'\n'
  else
    problems+="$(php "$ROOT/tests/preflight.php" "$ROOT" 2>&1)"$'\n'
  fi
  case "$groups" in *" http "*)
    command -v python3 >/dev/null 2>&1 || problems+="python3 is missing (the HTTP tests need it)"$'\n'
    command -v curl >/dev/null 2>&1 || problems+="curl is missing (the HTTP tests wait for the server with it)"$'\n'
  esac
  case "$groups" in *" js "*)
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
      problems+="node and npm are missing (Node 20 or newer is needed for the JavaScript checks)"$'\n'
    else
      local major
      major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null)"
      case "$major" in ''|*[!0-9]*) major=0 ;; esac
      [ "$major" -ge 20 ] || problems+="Node 20 or newer is needed for the JavaScript checks; this is $(node -v 2>/dev/null) (node -v)"$'\n'
    fi
  esac
  problems="$(printf '%s' "$problems" | grep -v '^$')"
  [ -z "$problems" ] && return 0
  echo "Environment check failed, nothing was run (GNOZIO_TESTS_SKIP_PREFLIGHT=1 skips this check):"
  printf '%s\n' "$problems" | sed 's/^/  /'
  exit "$PREFLIGHT_EXIT"
}

save_log() { # name output
  if [ -n "$LOG_DIR" ]; then
    printf '%s\n' "$2" > "$LOG_DIR/$PHASE-$1.log" || { echo "  FAIL  cannot save $1 log"; FAILED+=("log write"); }
  fi
}

run_check() { # name working-directory command [arguments...]   (RUN_TIMEOUT, when set, limits the run in seconds)
  local name="$1" directory="$2" out status
  shift 2
  if [ -n "$RUN_TIMEOUT" ]; then
    out=$(cd "$directory" && python3 "$ROOT/tests/with_timeout.py" "$RUN_TIMEOUT" "$@" 2>&1)
  else
    out=$(cd "$directory" && "$@" 2>&1)
  fi
  status=$?
  save_log "$name" "$out"
  report "$name" "$out" "$status"
}

report() { # name output exit-status
  local ok bad skipped
  ok=$(printf '%s\n' "$2" | grep -c '^ok')
  bad=$(printf '%s\n' "$2" | grep -c '^FAIL')
  skipped=$(printf '%s\n' "$2" | grep -c '^skip')
  TOTAL_SKIPPED=$((TOTAL_SKIPPED + skipped))
  if [ "$3" -eq 124 ] && [ -n "$RUN_TIMEOUT" ]; then
    printf '  FAIL  %-28s TIMEOUT %s (%s s)\n' "$1" "$1" "$RUN_TIMEOUT"
    printf '%s\n' "$2" | grep -vE '^ok|^note|^skip' | tail -8 | sed 's/^/          /'
    FAILED+=("$1 (TIMEOUT)")
  elif [ "$3" -eq 0 ] && [ "$bad" -eq 0 ] && printf '%s\n' "$2" | grep -q 'ALL PASSED'; then
    if [ "$skipped" -gt 0 ]; then
      printf '  pass  %-28s %s checks, %s skipped\n' "$1" "$ok" "$skipped"
    else
      printf '  pass  %-28s %s checks\n' "$1" "$ok"
    fi
    TOTAL_OK=$((TOTAL_OK + ok))
  else
    if [ "$skipped" -gt 0 ]; then
      printf '  FAIL  %-28s %s failed, %s skipped (exit %s)\n' "$1" "$bad" "$skipped" "$3"
    else
      printf '  FAIL  %-28s %s failed (exit %s)\n' "$1" "$bad" "$3"
    fi
    printf '%s\n' "$2" | grep -vE '^ok|^note|^skip' | sed 's/^/          /' | head -20
    FAILED+=("$1")
  fi
}

test_timeout() { # the seconds one HTTP test may take; add a case line for a test that legitimately needs longer
  case "$1" in
    *) echo "${GNOZIO_TEST_TIMEOUT:-180}" ;;
  esac
}

run_unit() {
  PHASE=unit
  echo "Unit checks"
  for spec in "namespace-compatibility:tests/unit/namespace-compatibility.php" "blocks:tests/unit/blocks.php" "content-types:tests/unit/content-types.php" "repeaters:tests/unit/repeaters.php" "revisions:tests/unit/revisions.php" "taxonomies:tests/unit/taxonomies.php" "links:tests/unit/links.php" "robots:tests/unit/robots.php" "seo:tests/unit/seo.php" "feed:tests/unit/feed.php" "content-store:tests/unit/content-store.php" "search-document:tests/unit/search-document.php" "search:tests/unit/search.php" "front-cache:tests/unit/front-cache.php" "analytics:tests/unit/analytics.php" "menus:tests/unit/menus.php" "content-csv:tests/unit/content-csv.php" "form-fields:tests/unit/form-fields.php" "form-translations:tests/unit/form-translations.php" "form-templates:tests/unit/form-templates.php" "branding:tests/unit/branding.php" "branding-extras:tests/unit/branding-extras.php" "branding-fonts:tests/unit/branding-fonts.php" "branding-buttons:tests/unit/branding-buttons.php" "palettes:tests/unit/palettes.php" "palette-unify:tests/unit/palette-unify.php" "footer-blocks:tests/unit/footer-blocks.php" "relations:tests/unit/relations.php" "relation-bindings:tests/unit/relation-bindings.php" "entry-bindings:tests/unit/entry-bindings.php" "list-bindings:tests/unit/list-bindings.php" "starting-templates:tests/unit/starting-templates.php" "single-template-admin:tests/unit/single-template-admin.php" "cron-status:tests/unit/cron-status.php" "starter-content:tests/unit/starter-content.php" "single-layouts:tests/unit/single-layouts.php" "layouts-admin:tests/unit/layouts-admin.php" "site-settings:tests/unit/site-settings.php" "update-source:tests/unit/update-source.php" "backup-manager:tests/unit/backup-manager.php" "taxonomy-editor:tests/unit/taxonomy-editor.php" "redirect-admin:tests/unit/redirect-admin.php" "translations:tests/unit/translations.php" "media-admin:tests/unit/media-admin.php" "site-limits:tests/unit/site-limits.php" "system-status:tests/unit/system-status.php" "omni-report:tests/unit/omni-report.php" "omni-client:tests/unit/omni-client.php" "dashboard:tests/unit/dashboard.php" "visual-markdown:tests/unit/visual-markdown.php" "youtube-playlist:tests/unit/youtube-playlist.php" "twig-site-functions:tests/unit/twig-site-functions.php" "template-resolver:tests/unit/template-resolver.php" "site-twig:tests/unit/site-twig.php" "block-runtime:tests/unit/block-runtime.php" "login-controller:tests/unit/login-controller.php" "content-paths:tests/unit/content-paths.php" "geo:tests/unit/geo.php" "geo-cache:tests/unit/geo-cache.php" "map-filters:tests/unit/map-filters.php" "forms:tests/unit/forms.php" "form-uploads:tests/unit/form-uploads.php" "accounts:tests/unit/accounts.php" "content-type-admin:tests/unit/content-type-admin.php" "archive-builder:tests/unit/archive-builder.php" "archive-query:tests/unit/archive-query.php" "archive-templates:tests/unit/archive-templates.php" "backup-admin:tests/unit/backup-admin.php" "content-screens:tests/unit/content-screens.php" "admin-screens:tests/unit/admin-screens.php" "public-site:tests/unit/public-site.php" "settings-and-front:tests/unit/settings-and-front.php" "release-package:tests/unit/release-package.php" "update-installer:tests/unit/update-installer.php" "installer:tests/unit/installer.php" "update-installer-restricted:tests/unit/update-installer-restricted.php" "first-admin:tests/unit/first-admin.php" "content-type-catalogue:tests/unit/content-type-catalogue.php" "html-to-markdown:tests/unit/html-to-markdown.php" "wordpress-import:tests/unit/wordpress-import.php" "wordpress-rest-types:tests/unit/wordpress-rest-types.php" "skill:tests/unit/skill.php" "permissions:scripts/check-permissions.php" "slugs-and-redirects:scripts/check-slugs.php" "block-containers:tests/unit/block-containers.php" "image-width:tests/unit/image-width.php" "content-blocks:scripts/check-blocks.php"; do
    local name="${spec%%:*}" file="${spec#*:}" out status
    out=$(cd "$ROOT" && php "$file" 2>&1)
    status=$?
    save_log "$name" "$out"
    if [ "$name" = "content-blocks" ]; then
      # This one prints a summary instead of "ALL PASSED".
      if [ "$status" -eq 0 ] && printf '%s\n' "$out" | tail -1 | grep -q ' 0 problems'; then printf '  pass  %-28s %s\n' "$name" "$(printf '%s\n' "$out" | tail -1)"; else printf '  FAIL  %-28s (exit %s)\n' "$name" "$status"; printf '%s\n' "$out" | tail -5 | sed 's/^/          /'; FAILED+=("$name"); fi
    else
      report "$name" "$out" "$status"
    fi
  done
}

run_js() {
  PHASE=js
  echo "JavaScript checks"
  if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    echo "  FAIL  node and npm are needed for the JavaScript checks"; FAILED+=("node missing"); return
  fi
  # The one tool they need (jsdom) is installed beside them, once, from the lock file. Nothing outside tests/js is touched.
  if [ ! -d "$ROOT/tests/js/node_modules/jsdom" ]; then
    local out status
    out=$(cd "$ROOT/tests/js" && npm ci --no-audit --no-fund 2>&1)
    status=$?
    save_log "npm-install" "$out"
    if [ "$status" -ne 0 ]; then
      echo "  FAIL  npm ci in tests/js did not work (exit $status)"
      printf '%s\n' "$out" | tail -20
      FAILED+=("npm ci"); return
    fi
  fi
  run_check "brand-compatibility" "$ROOT" node tests/js/brand-compatibility.test.js
  run_check "visual-editor-serializer" "$ROOT" node tests/js/serializer.test.js
  run_check "visual-editor-toolbar" "$ROOT" node tests/js/editor-tools.test.js
  run_check "seo-count" "$ROOT" node tests/js/seo-count.test.js
  run_check "entry-preview" "$ROOT" node tests/js/entry-preview.test.js
  run_check "branding-preview" "$ROOT" node tests/js/branding-preview.test.js
  run_check "branding-controls" "$ROOT" node tests/js/branding-controls.test.js
  run_check "branding-inheritance" "$ROOT" node tests/js/branding-inheritance.test.js
  run_check "branding-buttons" "$ROOT" node tests/js/branding-buttons.test.js
  run_check "palettes-ui" "$ROOT" node tests/js/palettes.test.js
  run_check "block-sortable" "$ROOT" node tests/js/sortable.test.js
  run_check "block-containers" "$ROOT" node tests/js/block-containers.test.js
  run_check "block-panel" "$ROOT" node tests/js/block-panel.test.js
  run_check "image-width-editor" "$ROOT" node tests/js/image-width.test.js
  run_check "nested-repeaters" "$ROOT" node tests/js/repeaters.test.js
  run_check "template-bindings" "$ROOT" node tests/js/template-bindings.test.js
  run_check "list-bindings" "$ROOT" node tests/js/list-bindings.test.js
  run_check "relations-picker" "$ROOT" node tests/js/relations.test.js
  run_check "content-types-kind" "$ROOT" node tests/js/content-types-kind.test.js
  run_check "map-filters" "$ROOT" node tests/js/map-filters.test.js
}

cleanup_http() {
  if [ -n "$HTTP_PID" ]; then
    kill "$HTTP_PID" 2>/dev/null
    wait "$HTTP_PID" 2>/dev/null
  fi
  if [ -n "$HTTP_WORK" ]; then
    if [ -n "$LOG_DIR" ] && [ -f "$HTTP_WORK/server.log" ]; then
      cp "$HTTP_WORK/server.log" "$LOG_DIR/http-server.log"
    fi
    rm -rf "$HTTP_WORK"
  fi
}

run_http() {
  PHASE=http
  local only="${1:-}"
  command -v python3 >/dev/null 2>&1 || { echo "python3 is needed for the HTTP tests"; FAILED+=("python3 missing"); return; }
  local work port pid ready=0
  work="$(mktemp -d)" || { FAILED+=("temporary directory"); return; }
  HTTP_WORK="$work"
  trap cleanup_http EXIT
  mkdir -p "$work/app"
  # Copy the code but not the site itself (content, storage, custom, uploads) or the tests.
  if ! python3 - "$ROOT" "$work/app" <<'PYCOPY'
import os, shutil, sys
root, dst = sys.argv[1:3]
def ignore(directory, names):
    rel = os.path.relpath(directory, root)
    skip = {'.git', 'node_modules', 'storage', 'custom', 'content', 'tests'} if rel == '.' else ({'uploads'} if rel == 'public' else set())
    return [n for n in names if n in skip]
shutil.copytree(root, dst, ignore=ignore, dirs_exist_ok=True)
PYCOPY
  then
    echo "  FAIL  could not prepare the temporary site"; FAILED+=("HTTP setup"); return
  fi
  port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')" || {
    echo "  FAIL  could not allocate a local HTTP port"; FAILED+=("HTTP port"); return
  }
  (cd "$work/app" && export PHP_CLI_SERVER_WORKERS=4 && exec php -d disable_functions=set_time_limit,ignore_user_abort,disk_free_space,opcache_reset,curl_exec -S "127.0.0.1:$port" -t public public/index.php >"$work/server.log" 2>&1) &
  pid=$!
  HTTP_PID="$pid"
  for _ in $(seq 1 50); do
    if curl -s --max-time 1 -o /dev/null "http://127.0.0.1:$port/"; then ready=1; break; fi
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.1
  done
  if [ "$ready" -ne 1 ]; then
    echo "  FAIL  the PHP test server did not start"
    tail -20 "$work/server.log"
    FAILED+=("HTTP server"); return
  fi

  echo "HTTP tests (temporary copy on port $port)"
  for name in search editor roles import redirects save history taxonomies links delete blocks hero_layouts mobile_menu bottom_bar logs users_tabs sidebar sidebar_brand theme_page media_upload upload_limits notifications bad_yaml custom_roles storage storage_cache line_endings media_usage media_picker admin_a11y seo_jsonld robots media_usage_cache update_install setup fresh_install catalogue menu_editor form_builder form_translation layouts header_footer branding branding_transfer custom_taxonomies type_options demo_content hero_video seo analytics translations content_types media dashboard editor_visual youtube_playlist blocks_pack geo clean_addresses footer_blocks single_templates archive_templates archive_lists relations updates_screen backup_schedule backup_remote feed omni category_tree form_upload block_widths entry_preview block_reorder block_containers accordion faq_initial palettes branding_fonts installer; do
    [ -n "$only" ] && [ "$only" != "$name" ] && continue
    # Every test starts from the same clean site.
    rm -rf "$work/app/content" "$work/app/storage" "$work/app/custom" "$work/app/public/uploads"
    mkdir -p "$work/app/storage/db" "$work/app/storage/backups" "$work/app/custom" "$work/app/public/uploads"
    cp -R "$ROOT/tests/fixtures/content" "$work/app/content"
    RUN_TIMEOUT="$(test_timeout "$name")"
    run_check "$name" "$work" env FAROS_TEST_BASE="http://127.0.0.1:$port" PYTHONPATH="$ROOT/tests/http" python3 "$ROOT/tests/http/${name}_test.py"
    RUN_TIMEOUT=""
  done
  if grep -qiE 'fatal|warning:|deprecated' "$work/server.log" 2>/dev/null; then
    echo "  note  the server log has PHP warnings or errors:"; grep -iE 'fatal|warning:|deprecated' "$work/server.log" | sort | uniq -c | head -5 | sed 's/^/          /'
    FAILED+=("php warnings")
  fi
}

case "$MODE" in
  all) preflight unit js http; run_unit; echo; run_js; echo; run_http ;;
  unit) preflight unit; run_unit ;;
  js) preflight js; run_js ;;
  http) preflight http; run_http ;;
  search|editor|roles|import|redirects|save|history|taxonomies|links|delete|blocks|hero_layouts|mobile_menu|bottom_bar|logs|users_tabs|sidebar|sidebar_brand|theme_page|media_upload|upload_limits|notifications|bad_yaml|custom_roles|storage|storage_cache|line_endings|media_usage|media_picker|admin_a11y|seo_jsonld|robots|media_usage_cache|update_install|setup|fresh_install|catalogue|menu_editor|form_builder|form_translation|layouts|header_footer|branding|branding_transfer|custom_taxonomies|type_options|demo_content|hero_video|seo|analytics|translations|content_types|media|dashboard|editor_visual|youtube_playlist|blocks_pack|geo|clean_addresses|footer_blocks|single_templates|archive_templates|archive_lists|relations|updates_screen|backup_schedule|backup_remote|feed|omni|category_tree|form_upload|block_widths|entry_preview|block_reorder|block_containers|accordion|faq_initial|palettes|branding_fonts|installer) preflight http; run_http "$MODE" ;;
  *) echo "Unknown option: $MODE"; exit 2 ;;
esac

echo
[ "$TOTAL_SKIPPED" -gt 0 ] && echo "$TOTAL_SKIPPED checks skipped because an earlier step of their test failed (shown as 'skip' lines in the log)"
if [ ${#FAILED[@]} -eq 0 ]; then echo "ALL GOOD ($TOTAL_OK checks)"; exit 0; fi
echo "FAILED: ${FAILED[*]}"; exit 1
