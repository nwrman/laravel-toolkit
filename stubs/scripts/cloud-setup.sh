#!/bin/sh
# Laravel Cloud provisioning — CREATE-ONLY, re-runnable, never destructive.
#
# Reads its inputs from two files:
#
#   .cloud/config.json    Laravel's own file, written by `cloud repo:config`. Its
#                         `organization_id` pins which Cloud organization the CLI acts on —
#                         without it the CLI refuses to run at all when several API tokens
#                         exist. `application_id` is written back here once resolved.
#
#   .cloud/provision.json This project's provisioning inputs: repository, names, region,
#                         database and instance sizing, and an optional custom domain.
#                         Values are chosen per project, not inherited from a template —
#                         list what Cloud actually offers before filling it in.
#
# Creates, if missing: application, environment, database cluster + schema, app instance, and
# (only when `domain` is set) a custom domain. Wires the environment to the committed build and
# deploy scripts, sets APP_KEY, loads environment variables, and derives APP_URL.
#
# Never deploys, deletes, renames, or resizes. Re-running fills gaps only, so it is safe to run
# again later when a domain or a secret finally exists. Scaling up is a deliberate manual step.
#
# Run from the repo root: `composer cloud:setup`.
set -eu

CONFIG_FILE=".cloud/config.json"
PROVISION_FILE=".cloud/provision.json"

note() { printf '\033[36m==>\033[0m %s\n' "$1"; }
fail() { printf '\033[31mError:\033[0m %s\n' "$1" >&2; exit 1; }

# Read a top-level scalar from a JSON file; empty when the file or key is absent.
jfile() {
    php -r '
        $f = $argv[1];
        if (! is_file($f)) { exit; }
        $d = json_decode(file_get_contents($f), true);
        if (! is_array($d)) { exit; }
        $v = $d[$argv[2]] ?? "";
        echo is_scalar($v) ? (string) $v : "";
    ' "$1" "$2"
}

# Read a top-level scalar from a JSON object on stdin.
jval() {
    php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $v = is_array($d) ? ($d[$argv[1]] ?? "") : "";
        echo is_scalar($v) ? (string) $v : "";
    ' "$1"
}

# Run a `cloud` command, aborting on a transport failure or an API-level error payload.
cloud_json() {
    out=$("$@" 2>&1) || { printf '%s\n' "$out" >&2; fail "command failed: $*"; }
    err=$(printf '%s' "$out" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        echo (is_array($d) && ($d["error"] ?? false)) ? ($d["message"] ?? "unknown error") : "";
    ')
    [ -z "$err" ] || fail "Laravel Cloud: $err"
    printf '%s' "$out"
}

# --- preflight -------------------------------------------------------------------------------

command -v cloud >/dev/null 2>&1 || fail "the \`cloud\` CLI is not installed. Run: composer global require laravel/cloud-cli && cloud auth"

[ -f "$PROVISION_FILE" ] || fail "$PROVISION_FILE not found. The provision-laravel-cloud skill writes it, or copy the example from the toolkit."

ORG_ID=$(jfile "$CONFIG_FILE" organization_id)
[ -n "$ORG_ID" ] || fail "organization_id is not set in $CONFIG_FILE.
  This is what pins the Cloud organization; without it the CLI cannot tell which of your
  API tokens to use, and an application could be created in the wrong account.
  Run \`cloud repo:config\`, or add it by hand, then re-run."

REPO=$(jfile "$PROVISION_FILE" repository)
APP_NAME=$(jfile "$PROVISION_FILE" application_name)
ENV_NAME=$(jfile "$PROVISION_FILE" environment_name)
BRANCH=$(jfile "$PROVISION_FILE" branch)
REGION=$(jfile "$PROVISION_FILE" region)
DB_CLUSTER_NAME=$(jfile "$PROVISION_FILE" database_cluster_name)
DB_SCHEMA_NAME=$(jfile "$PROVISION_FILE" database_schema_name)
DB_TYPE=$(jfile "$PROVISION_FILE" database_type)
INSTANCE_SIZE=$(jfile "$PROVISION_FILE" instance_size)
DOMAIN=$(jfile "$PROVISION_FILE" domain)
ENV_FILE=$(jfile "$PROVISION_FILE" env_file)
BUILD_COMMAND=$(jfile "$PROVISION_FILE" build_command)
DEPLOY_COMMAND=$(jfile "$PROVISION_FILE" deploy_command)

[ -n "$ENV_FILE" ] || ENV_FILE=".cloud/env.production"
[ -n "$BUILD_COMMAND" ] || BUILD_COMMAND="composer cloud:build"
[ -n "$DEPLOY_COMMAND" ] || DEPLOY_COMMAND="composer cloud:deploy"

for pair in "repository=$REPO" "application_name=$APP_NAME" "environment_name=$ENV_NAME" \
    "branch=$BRANCH" "region=$REGION" "database_cluster_name=$DB_CLUSTER_NAME" \
    "database_schema_name=$DB_SCHEMA_NAME" "database_type=$DB_TYPE" "instance_size=$INSTANCE_SIZE"; do
    case "$pair" in
        *=) fail "${pair%=} is missing from $PROVISION_FILE" ;;
    esac
done

# --- organization guard ----------------------------------------------------------------------
#
# The CLI has no --organization flag on any :create command, so the pinned organization_id is the
# only thing standing between a client's application and your personal account. A successful list
# proves the pin resolves to a reachable organization; the post-create check below proves the
# application actually landed in it.

note "Verifying organization $ORG_ID..."
APPS=$(cloud_json cloud application:list --json -n --fields=id,name,region,repositoryFullName,organization.id,organization.name)

ORG_NAME=$(printf '%s' "$APPS" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($d as $a) {
        if (($a["organization"]["id"] ?? "") === $argv[1]) { echo $a["organization"]["name"] ?? ""; break; }
    }
' "$ORG_ID")

if [ -n "$ORG_NAME" ]; then
    note "Organization: $ORG_NAME"
else
    note "Organization has no applications yet; the pin resolved, so continuing."
fi

# --- application (reuse only within our region; wrong-region leftovers are ignored) ------------

note "Resolving application ($REPO in $REGION)..."
APP_ID=$(printf '%s' "$APPS" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($d as $a) {
        if (($a["repositoryFullName"] ?? "") === $argv[1] && ($a["region"] ?? "") === $argv[2]) {
            echo $a["id"]; break;
        }
    }
' "$REPO" "$REGION")

if [ -z "$APP_ID" ]; then
    note "Creating application \"$APP_NAME\"..."
    CREATED=$(cloud_json cloud application:create --name "$APP_NAME" --repository "$REPO" --region "$REGION" --json -n)
    APP_ID=$(printf '%s' "$CREATED" | jval id)
    [ -n "$APP_ID" ] || fail "application:create returned no id"

    CREATED_ORG=$(printf '%s' "$CREATED" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true) ?: [];
        echo $d["organization"]["id"] ?? ($d["organizationId"] ?? "");
    ')

    if [ -n "$CREATED_ORG" ] && [ "$CREATED_ORG" != "$ORG_ID" ]; then
        fail "application $APP_ID was created in organization $CREATED_ORG, not the pinned $ORG_ID.
  Nothing further has been provisioned. Delete it in the Laravel Cloud dashboard, fix
  organization_id in $CONFIG_FILE, then re-run."
    fi
else
    note "Reusing application $APP_ID"
fi

# Record the application id alongside the org pin. Both are ids, not secrets — safe to commit.
php -r '
    $f = $argv[1];
    $d = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $d["organization_id"] = $argv[2];
    $d["application_id"] = $argv[3];
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
' "$CONFIG_FILE" "$ORG_ID" "$APP_ID"
note "$CONFIG_FILE updated (application_id=$APP_ID)"

# --- environment -------------------------------------------------------------------------------

note "Resolving environment ($ENV_NAME)..."
ENV_ID=$(cloud_json cloud environment:list "$APP_ID" --json -n | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($d as $e) { if (($e["name"] ?? "") === $argv[1]) { echo $e["id"]; break; } }
' "$ENV_NAME")

if [ -z "$ENV_ID" ]; then
    note "Creating environment..."
    ENV_ID=$(cloud_json cloud environment:create "$APP_ID" --name "$ENV_NAME" --branch "$BRANCH" --json -n | jval id)
else
    note "Reusing environment $ENV_ID"
fi
[ -n "$ENV_ID" ] || fail "failed to resolve environment id"

# --- database cluster + schema (reuse only within our region) -----------------------------------

note "Resolving database cluster ($DB_CLUSTER_NAME in $REGION)..."
CLUSTER_ID=$(cloud_json cloud database-cluster:list --json -n | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($d as $c) {
        if (($c["name"] ?? "") === $argv[1] && ($c["region"] ?? "") === $argv[2]) { echo $c["id"]; break; }
    }
' "$DB_CLUSTER_NAME" "$REGION")

if [ -z "$CLUSTER_ID" ]; then
    note "Creating database cluster ($DB_TYPE, $REGION)..."
    CLUSTER_ID=$(cloud_json cloud database-cluster:create --name "$DB_CLUSTER_NAME" --type "$DB_TYPE" --region "$REGION" --json -n | jval id)
else
    note "Reusing database cluster $CLUSTER_ID"
fi
[ -n "$CLUSTER_ID" ] || fail "failed to resolve database cluster id"

note "Resolving database schema ($DB_SCHEMA_NAME)..."
SCHEMA_ID=$(cloud_json cloud database:list "$CLUSTER_ID" --json -n | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($d as $s) { if (($s["name"] ?? "") === $argv[1]) { echo $s["id"]; break; } }
' "$DB_SCHEMA_NAME")

if [ -z "$SCHEMA_ID" ]; then
    note "Creating database schema..."
    SCHEMA_ID=$(cloud_json cloud database:create "$CLUSTER_ID" --name "$DB_SCHEMA_NAME" --json -n | jval id)
else
    note "Reusing database schema $SCHEMA_ID"
fi
[ -n "$SCHEMA_ID" ] || fail "failed to resolve database schema id"

# --- app instance --------------------------------------------------------------------------------
#
# Create-only by design: an existing instance is left exactly as it is, so scaling done in the
# dashboard is never silently reverted by a re-run.

note "Ensuring app instance..."
INSTANCE_ID=$(cloud_json cloud instance:list "$ENV_ID" --json -n | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($d as $i) { if (($i["type"] ?? "") === "app") { echo $i["id"]; break; } }
')

if [ -z "$INSTANCE_ID" ]; then
    note "Creating app instance ($INSTANCE_SIZE)..."
    cloud_json cloud instance:create "$ENV_ID" --type app --size "$INSTANCE_SIZE" --json -n >/dev/null
else
    note "Reusing app instance $INSTANCE_ID (size unchanged — resize in the dashboard)"
fi

# --- wire the environment to the committed pipeline ------------------------------------------------

note "Wiring environment (database, build and deploy commands)..."
cloud_json cloud environment:update "$ENV_ID" --branch "$BRANCH" \
    --database-id "$SCHEMA_ID" \
    --build-command "$BUILD_COMMAND" \
    --deploy-command "$DEPLOY_COMMAND" \
    --force --json -n >/dev/null

# --- APP_KEY (generated here, never committed) ------------------------------------------------------

ENV_JSON=$(cloud_json cloud environment:get "$ENV_ID" --json -n)

HAS_KEY=$(printf '%s' "$ENV_JSON" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach (($d["environmentVariables"] ?? []) as $v) {
        if (($v["key"] ?? "") === "APP_KEY") { echo "1"; break; }
    }
')

if [ -z "$HAS_KEY" ]; then
    note "Generating APP_KEY..."
    cloud environment:variables "$ENV_ID" --action set --key APP_KEY --value "$(php artisan key:generate --show)" --force -n >/dev/null
else
    note "APP_KEY already set"
fi

# --- environment variables --------------------------------------------------------------------------
#
# Placeholder-valued keys are skipped rather than written. Setting REPLACE_IN_DASHBOARD as a real
# value is worse than leaving a key unset — config validation cannot tell "unset" from "set wrong" —
# and on a re-run it would overwrite the real secret you pasted into the dashboard weeks earlier.

if [ -f "$ENV_FILE" ]; then
    note "Setting environment variables from $ENV_FILE..."
    PENDING=""
    while IFS= read -r line || [ -n "$line" ]; do
        line=$(printf '%s' "$line" | tr -d '\r')
        case "$line" in ''|\#*) continue ;; esac
        key=${line%%=*}
        val=${line#*=}

        case "$val" in
            REPLACE_IN_DASHBOARD)
                PENDING="$PENDING $key"
                continue
                ;;
        esac

        case "$key" in
            APP_URL)
                # Derived below from the live environment; a committed value goes stale the moment
                # a domain is added or the Cloud-assigned URL is the real address.
                continue
                ;;
        esac

        cloud environment:variables "$ENV_ID" --action set --key "$key" --value "$val" --force -n >/dev/null
        printf '    set %s\n' "$key"
    done < "$ENV_FILE"
else
    note "$ENV_FILE not found; skipping environment variables"
    PENDING=""
fi

# --- custom domain (optional) --------------------------------------------------------------------------

DOMAIN_CREATED=""
if [ -n "$DOMAIN" ]; then
    note "Ensuring custom domain ($DOMAIN)..."
    HAS_DOMAIN=$(cloud_json cloud domain:list "$ENV_ID" --json -n | php -r '
        $d = json_decode(stream_get_contents(STDIN), true) ?: [];
        foreach ($d as $dm) { if (($dm["name"] ?? "") === $argv[1]) { echo "1"; break; } }
    ' "$DOMAIN")

    if [ -z "$HAS_DOMAIN" ]; then
        # --wildcard-enabled=0 — the `=0` matters. The backend casts the string "false" to a
        # truthy bool, so only 0 actually disables the *.$DOMAIN wildcard.
        note "Creating domain — set the DNS records it returns at your DNS host:"
        cloud domain:create "$ENV_ID" --name "$DOMAIN" --wildcard-enabled=0 --json -n
        DOMAIN_CREATED="1"
    else
        note "Domain already present"
    fi
else
    note "No domain configured; using the Cloud-assigned URL"
fi

# --- APP_URL (derived, never committed) ------------------------------------------------------------------

if [ -n "$DOMAIN" ]; then
    APP_URL="https://$DOMAIN"
else
    APP_URL=$(cloud_json cloud environment:get "$ENV_ID" --json -n | php -r '
        $d = json_decode(stream_get_contents(STDIN), true) ?: [];
        $u = $d["url"] ?? ($d["vanityDomain"] ?? "");
        echo is_string($u) ? $u : "";
    ')
    case "$APP_URL" in
        '') : ;;
        http*) : ;;
        *) APP_URL="https://$APP_URL" ;;
    esac
fi

if [ -n "$APP_URL" ]; then
    note "Setting APP_URL=$APP_URL"
    cloud environment:variables "$ENV_ID" --action set --key APP_URL --value "$APP_URL" --force -n >/dev/null
else
    note "Could not determine the environment URL; set APP_URL by hand"
fi

# --- summary -------------------------------------------------------------------------------------------

printf '\n\033[32m✓\033[0m %s stack provisioned (NOT deployed).\n' "$REGION"
printf '  Organization: %s (%s)\n' "${ORG_NAME:-unknown name}" "$ORG_ID"
printf '  Application:  %s (%s)\n' "$APP_NAME" "$APP_ID"
printf '  Environment:  %s (%s)   DB schema: %s\n' "$ENV_NAME" "$ENV_ID" "$SCHEMA_ID"
printf '  URL:          %s\n' "${APP_URL:-unknown}"

if [ -n "$PENDING" ]; then
    printf '\n\033[33mSecrets still to fill in the Cloud dashboard:\033[0m\n'
    for key in $PENDING; do printf '  - %s\n' "$key"; done
    printf '  These were skipped, not written. Re-running will not overwrite them once set.\n'
fi

if [ -n "$DOMAIN_CREATED" ]; then
    printf '\nPoint %s at the CNAME target shown above, then:\n' "$DOMAIN"
    printf '  cloud domain:list %s --json -n     # find the domain id\n' "$ENV_ID"
    printf '  cloud domain:verify <DOMAIN_ID> -n\n'
fi

printf '\nWhen ready to go live:\n  cloud deploy "%s" %s -n\n' "$APP_NAME" "$ENV_NAME"
