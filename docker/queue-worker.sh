#!/bin/sh

# Queue workers are long-lived CLI processes. A small, bounded startup jitter
# prevents all replicas from recycling at the same second when max-time or
# max-jobs is reached, which would otherwise create a short capacity cliff.
set -eu

jitter_max="${QUEUE_WORKER_STARTUP_JITTER_MAX_SECONDS:-30}"
case "$jitter_max" in
    ''|*[!0-9]*) jitter_max=30 ;;
esac

if [ "$jitter_max" -gt 0 ]; then
    entropy="$(od -An -N4 -tu4 /dev/urandom 2>/dev/null | tr -d ' ' || true)"
    case "$entropy" in
        ''|*[!0-9]*) entropy=0 ;;
    esac
    sleep "$((entropy % (jitter_max + 1)))"
fi

exec php artisan queue:work "$@"
