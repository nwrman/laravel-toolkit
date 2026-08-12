---
name: provision-laravel-cloud
description: Stand up a Laravel Cloud application for this project for the first time — organization, application, environment, database, instance, and optionally a custom domain — by gathering the project's provisioning inputs and running scripts/cloud-setup.sh. Use when the user wants to put an app on Laravel Cloud that has never been provisioned, add a custom domain to one that was provisioned without it, or asks why a deploy target does not exist yet. For deploying, monitoring, env vars, or anything on an application that already exists, use the deploying-laravel-cloud skill instead.
---

# Provision a Laravel Cloud application

First-time provisioning only. Everything after — deploying, monitoring, editing variables,
running remote commands — belongs to Laravel's own `deploying-laravel-cloud` skill. Hand off to
it as soon as the stack exists.

## The two files

| File | Owner | Holds |
|---|---|---|
| `.cloud/config.json` | Laravel's `cloud` CLI | `organization_id` (the org pin), `application_id` |
| `.cloud/provision.json` | this project | repository, names, region, database and instance sizing, optional domain |

Do not move `organization_id` out of `.cloud/config.json`. The CLI reads it from there, and it
is the only thing that selects an organization.

## Get the organization right before anything else

There is no `--organization` flag on any `:create` command. The organization is ambient state,
so a wrong pin means a client's application provisioned into the wrong account, billed to the
wrong card, with no flag on the command that would have caught it.

When several API tokens exist and no organization is pinned, the CLI refuses to run:

```
Multiple API tokens found. Set organization_id in .cloud/config.json
```

That failure is a safety net, not a bug. It stops guessing; it does not stop you pinning the
*wrong* organization. So before creating anything:

1. Read `organization_id` from `.cloud/config.json`. If absent, run `cloud repo:config`.
2. Resolve its name: `cloud application:list --json -n --fields=organization.id,organization.name`.
3. Check that name against the repository owner in `git remote -v`. A repo under a client's
   GitHub organization almost never belongs in a personal Cloud organization.
4. **Show the user the organization name and the repository, and get explicit confirmation.**
   Never skip this because the pin "looks right".

The script repeats the check and aborts if a created application lands somewhere unexpected,
but by then the resource exists and has to be deleted by hand. The confirmation is the cheap
one.

## Choose values, never inherit them

Do not copy sizes, regions, or database types from another project in this lineage. They were
that project's answers at that moment. Ask Cloud what it offers today:

- `cloud instance:sizes --json -n`
- `cloud database-cluster:create -h` for current cluster types
- `cloud -h` and `cloud <command> -h` for anything else — never hardcode a signature

Then propose the **smallest thing that works** and let the user override. Projects start small
and grow; scaling up later is a deliberate manual step in the dashboard, not something this
script does.

Regions deserve care and have a shelf life. List what exists rather than trusting a remembered
answer — a region that did not exist last quarter may exist now. As a worked example: in June
2026 talok-app chose `us-east-2` for Los Angeles callers because Laravel Cloud had no US-West
region at all and Ohio was the closer of the two US options. Verify that is still true before
repeating it.

## Gather the inputs

Fill `.cloud/provision.json`. Ask for anything you cannot infer; do not invent values.

```json
{
  "repository": "Owner/repo",
  "application_name": "Acme",
  "environment_name": "PRD",
  "branch": "main",
  "region": "us-east-2",
  "database_cluster_name": "acme",
  "database_schema_name": "main",
  "database_type": "neon_serverless_postgres_18",
  "instance_size": "flex-512mb"
}
```

Optional keys:

- `domain` — **omit it entirely** when there is no custom domain yet. The script skips the
  domain step and the app runs on its Cloud-assigned URL. Adding the key later and re-running
  creates the domain then.
- `env_file` — defaults to `.cloud/env.production`.
- `build_command` / `deploy_command` — default to `composer cloud:build` and
  `composer cloud:deploy`, which the toolkit publishes into `scripts/`.

`repository` must match the GitHub remote exactly, owner included.

On a re-run, keys already present are used as-is. Only ask about what is missing, so adding a
domain in three weeks does not re-interrogate the user about instance sizes.

## Environment variables

`.cloud/env.production` is committed and holds non-secret configuration plus secret *names*
valued `REPLACE_IN_DASHBOARD`. Real secrets are pasted once into the Cloud dashboard and never
committed.

The script skips every placeholder-valued key rather than writing it, and lists them at the end.
That matters on re-runs: writing `REPLACE_IN_DASHBOARD` over a real secret would break the app
silently, because setting a variable always reports success.

Do not put `APP_URL` in the file. The script derives it — the custom domain when one is
configured, otherwise the Cloud-assigned URL — because the correct value does not exist until
the environment does.

**Never declare credentials Cloud injects.** When a database or a bucket is attached to an
environment, Laravel Cloud injects everything needed to reach it — `DB_*` for databases,
`FILESYSTEM_DISK` and the S3-compatible `AWS_*` variables for buckets. They will not appear in
the environment's declared variable list, and writing them yourself overrides working values
with stale ones.

## Object storage

Only relevant when the app stores uploads — media library avatars, user documents, anything
written at runtime. Cloud's filesystem does not survive a deploy, so an app writing to the local
`public` disk loses every file each time it ships. That is a bucket, not a `storage:link`.

**This part cannot be scripted.** The CLI has `bucket:create`, `bucket:update` and
`bucket-key:*`, but there is no attach command and `environment:update` has no `--bucket-id`.
Attaching a bucket — which is what sets the disk name and triggers credential injection — is
done on the environment's infrastructure canvas in the dashboard. Creating a bucket from the CLI
without attaching it leaves a resource that looks provisioned and does nothing, so do not.

Walk the user through it:

1. Confirm `league/flysystem-aws-s3-v3` is in `composer.json`. Cloud object storage requires it,
   and its absence only shows up at the first upload.
2. Dashboard → the environment's infrastructure canvas → **Add bucket** → Laravel Object Storage.
3. Choose a **disk name** — this is what `Storage::disk('...')` takes. Marking it the default
   disk means the app can call `Storage::` with no disk name.
4. Choose **visibility**. Public for avatars and anything served straight to a browser; private
   for personal documents, reached through `Storage::temporaryUrl`. It is set per bucket and
   cannot be mixed within one.
5. **Redeploy** — the injected variables only reach the app on the next deploy.

Two traps worth naming before the first upload:

- `AWS_URL` is **not** injected for public buckets. If the app reads it, copy the value from the
  bucket settings page into the environment's own variables by hand.
- Cloudflare R2 backs these buckets and does not support per-object ACLs. Do not set
  `'visibility' => 'public'` in the disk's config in `config/filesystems.php` — R2 rejects it
  with `NotImplemented`. Bucket-level visibility governs access instead.

## Run it

```sh
composer cloud:setup
```

Create-only and re-runnable. It never deploys, deletes, renames, or resizes, so running it again
after adding a domain or a secret is safe. An existing instance is left untouched, which means
dashboard scaling is never silently reverted.

`.cloud/provision.json` records what was provisioned initially, not current state. If someone
resizes in the dashboard, the file goes stale and the script will not notice or fight it.

## After it succeeds

1. Commit the updated `.cloud/config.json` — ids, not secrets.
2. Fill any secrets the script listed as pending, in the dashboard.
3. If a domain was created, set the DNS records it printed, then
   `cloud domain:verify <DOMAIN_ID> -n`.
4. Re-apply any dashboard-only edge or network settings; the CLI does not cover them.
5. Deploy with the `deploying-laravel-cloud` skill. Provisioning does not deploy.

## When something fails

Read the error. The script aborts on the first API-level error rather than continuing into a
half-built stack, so fix the cause and re-run — it picks up where it left off. If an application
was created in the wrong organization, the script stops immediately and tells you what to
delete; it will not delete anything itself.
