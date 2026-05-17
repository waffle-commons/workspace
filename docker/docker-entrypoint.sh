#!/bin/bash
################################################################################
# Waffle Workspace Docker Entrypoint
# 
# Responsibilities:
#   1. Environment & configuration validation
#   2. Symlink health check (all 15 components)
#   3. Conditional composer.lock refresh
#   4. PHP runtime & extension verification
#   5. Diagnostics output (startup summary)
#   6. Graceful shutdown handling (SIGTERM/SIGINT)
#   7. Handoff to FrankenPHP worker mode
#
# Exit Codes:
#   0   = Startup successful, FrankenPHP running
#   1   = Validation failed (symlinks, extensions, config)
#   143 = SIGTERM received (graceful shutdown)
################################################################################

set -euo pipefail

# Enable error propagation in subshells
set -E

# === CONFIGURATION ===
WAFFLE_ROOT="/waffle-commons"
WORKSPACE_ROOT="${WAFFLE_ROOT}/workspace"
VENDOR_DIR="${WORKSPACE_ROOT}/vendor"
CONFIG_DIR="${WORKSPACE_ROOT}/config"
VAR_DIR="${WORKSPACE_ROOT}/var"
LOG_DIR="${VAR_DIR}/log"
STARTUP_LOG="${LOG_DIR}/startup.log"

# All 15 waffle-commons components
COMPONENTS=(
  cache
  config
  container
  contracts
  console
  error-handler
  event-dispatcher
  http
  log
  pipeline
  routing
  runtime
  security
  utils
  waffle
)

# === LOGGING UTILITIES ===

log_info() {
  local msg="$*"
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO]  $msg" >&2
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO]  $msg" >> "$STARTUP_LOG" 2>/dev/null || true
}

log_warn() {
  local msg="$*"
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [WARN]  $msg" >&2
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [WARN]  $msg" >> "$STARTUP_LOG" 2>/dev/null || true
}

log_error() {
  local msg="$*"
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $msg" >&2
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $msg" >> "$STARTUP_LOG" 2>/dev/null || true
}

log_divider() {
  echo "════════════════════════════════════════════════════════════════" >&2
}

# === PHASE 1: ENVIRONMENT SETUP & LOGGING ===

mkdir -p "$LOG_DIR"
log_divider
log_info "🚀 Waffle Workspace Docker Entrypoint Starting"
log_info "Time: $(date '+%Y-%m-%d %H:%M:%S %Z')"
log_info "Environment: ${APP_ENV:-undefined}"
log_divider

# === PHASE 2: ENVIRONMENT VALIDATION ===

log_info "PHASE 2: Environment Validation"

if [ ! -f "${WORKSPACE_ROOT}/.env" ]; then
  log_warn ".env file not found, proceeding with defaults"
else
  log_info "✓ .env file found"
fi

if [ ! -f "${CONFIG_DIR}/app.yaml" ]; then
  log_error "config/app.yaml not found!"
  exit 1
fi
log_info "✓ config/app.yaml readable"

if [ ! -f "${CONFIG_DIR}/app_dev.yaml" ]; then
  log_warn "config/app_dev.yaml not found (optional)"
else
  log_info "✓ config/app_dev.yaml readable"
fi

# === PHASE 3: SYMLINK VERIFICATION (if vendor exists) ===

if [ ! -d "$VENDOR_DIR" ]; then
  log_info "PHASE 3: Symlink Verification - SKIPPED (vendor/ not yet created)"
else
  log_info "PHASE 3: Symlink Verification (15 Components)"
  
  symlink_errors=0
  for component in "${COMPONENTS[@]}"; do
    symlink="${VENDOR_DIR}/waffle-commons/${component}"
    
    if [ ! -L "$symlink" ]; then
      log_error "Symlink BROKEN: waffle-commons/${component}"
      ((symlink_errors++))
    else
      log_info "  ✓ waffle-commons/${component}"
    fi
  done
  
  if [ $symlink_errors -gt 0 ]; then
    log_error "Failed to verify $symlink_errors component symlinks!"
    exit 1
  fi
  
  log_info "✓ All 15 component symlinks verified"
fi

# === PHASE 4: COMPOSER LOCK REFRESH & INSTALL ===

log_info "PHASE 4: Composer Dependency Management"

autoload_php="${VENDOR_DIR}/autoload.php"
composer_lock="${WORKSPACE_ROOT}/composer.lock"

if [ ! -f "$autoload_php" ]; then
  log_warn "vendor/autoload.php missing"
  log_info "Running composer install (first time or Dockerfile install skipped)..."
  cd "$WORKSPACE_ROOT"
  composer install --prefer-dist --no-progress --no-interaction
  log_info "✓ Dependencies installed"
elif [ "$composer_lock" -nt "$autoload_php" ]; then
  log_info "composer.lock is newer than vendor/autoload.php"
  log_info "Running composer install to refresh dependencies..."
  cd "$WORKSPACE_ROOT"
  composer install --prefer-dist --no-progress --no-interaction
  log_info "✓ Dependencies refreshed"
else
  log_info "✓ Dependencies up-to-date (no refresh needed)"
fi

# === PHASE 5: PHP RUNTIME VALIDATION ===

log_info "PHASE 5: PHP Runtime Validation"

php_version=$(php -r 'echo phpversion();')
log_info "PHP Version: $php_version"

required_extensions=(gd intl zip opcache yaml xdebug)
missing_extensions=()

for ext in "${required_extensions[@]}"; do
  if php -m | grep -qi "^$ext\$"; then
    log_info "  ✓ Extension: $ext"
  else
    log_error "  ✗ Extension MISSING: $ext"
    missing_extensions+=("$ext")
  fi
done

if [ ${#missing_extensions[@]} -gt 0 ]; then
  log_error "Required PHP extensions missing: ${missing_extensions[*]}"
  exit 1
fi

# Verify Opcache is enabled
if php -r 'exit(extension_loaded("opcache") && ini_get("opcache.enable") ? 0 : 1);'; then
  log_info "  ✓ Opcache enabled"
else
  log_warn "  ⚠ Opcache not enabled (performance impact in worker mode)"
fi

# === PHASE 6: DIAGNOSTICS SUMMARY ===

log_divider
log_info "📊 STARTUP DIAGNOSTICS SUMMARY"
log_divider

log_info "Environment Variables:"
log_info "  APP_ENV: ${APP_ENV:-undefined}"
log_info "  APP_DEBUG: ${APP_DEBUG:-undefined}"
log_info "  SERVER_NAME: ${SERVER_NAME:-undefined}"
log_info "  XDEBUG_MODE: ${XDEBUG_MODE:-undefined}"

log_info ""
log_info "Detected Components (15 symlinked):"
for component in "${COMPONENTS[@]}"; do
  target=$(readlink "${VENDOR_DIR}/waffle-commons/${component}" 2>/dev/null || echo "broken")
  log_info "  → $component ($target)"
done

log_info ""
log_info "PHP Configuration:"
log_info "  Version: $php_version"
log_info "  INI: $(php -r 'echo php_ini_loaded_file();')"
log_info "  Timezone: $(php -r 'echo ini_get("date.timezone");')"
log_info "  Memory Limit: $(php -r 'echo ini_get("memory_limit");')"
log_info "  Max Upload: $(php -r 'echo ini_get("upload_max_filesize");')"

log_info ""
log_info "FrankenPHP Worker Mode:"
log_info "  Config: /etc/caddy/Caddyfile"
log_info "  Listen: ${SERVER_NAME:-:80}"
log_info "  Log: stderr (container logs)"

log_divider
log_info "✓ All startup validation passed!"
log_info "📝 Full logs: $STARTUP_LOG"
log_divider

# === PHASE 7: GRACEFUL SHUTDOWN HANDLER ===

cleanup() {
  local exit_code=$?
  log_info "Received shutdown signal (exit code: $exit_code)"
  
  if [ -n "${CHILD_PID:-}" ] && kill -0 "$CHILD_PID" 2>/dev/null; then
    log_info "Forwarding SIGTERM to FrankenPHP (PID: $CHILD_PID)..."
    kill -TERM "$CHILD_PID" 2>/dev/null || true
    
    # Wait for graceful shutdown (max 30 seconds)
    local wait_count=0
    while kill -0 "$CHILD_PID" 2>/dev/null && [ $wait_count -lt 30 ]; do
      sleep 1
      ((wait_count++))
    done
    
    if kill -0 "$CHILD_PID" 2>/dev/null; then
      log_warn "FrankenPHP did not shutdown gracefully, sending SIGKILL..."
      kill -9 "$CHILD_PID" 2>/dev/null || true
    fi
  fi
  
  log_info "Entrypoint shutdown complete (exit code: $exit_code)"
  exit "$exit_code"
}

trap cleanup SIGTERM SIGINT EXIT

# === PHASE 8: HANDOFF TO FRANKENPHP ===

log_info "PHASE 8: Starting FrankenPHP Worker Mode"
log_info "Ready to accept connections!"
log_divider

cd "$WORKSPACE_ROOT"

# Start FrankenPHP in background (allows trap to work)
exec frankenphp run --config /etc/caddy/Caddyfile
