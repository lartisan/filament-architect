# Upgrade Guide

## Upgrading from any pre-1.0.0 release to `v1.0.0`

`v1.0.0` introduces a new database table and related schema updates required by the blueprint revision workflow.

If you are upgrading from any release prior to `v1.0.0`, run `architect:upgrade` — it publishes the new migrations, runs them, and backfills revisions for existing blueprints.

## Required upgrade steps

1. Update the package to `v1.0.0`.
2. Run `architect:upgrade`.

```bash
composer require lartisan/filament-architect:^1.0
php artisan architect:upgrade
```

> **Tip:** Run `php artisan architect:upgrade --dry-run` first to preview which blueprints will be backfilled without writing any changes.

## Why `architect:upgrade` is needed

`v1.0.0` introduces the `architect_blueprint_revisions` table. The **Blueprints** tab reads the most recent revision of each blueprint and will show nothing for records that pre-date this release. Running `architect:upgrade` creates an initial revision (revision 1) for every existing blueprint that has none, using the data already stored in `architect_blueprints`. The revision is tagged with `backfilled_by_upgrade: true` in its metadata so it is easy to identify later.

## Why this upgrade requires migrations

This release adds the `architect_blueprint_revisions` table and related schema changes used to store blueprint revision history and snapshot metadata.

Without these migrations, Filament Architect cannot safely access its required tables in `v1.0.0`.

## Recommended deployment note

For shared or production environments, include `php artisan migrate` in your normal deployment pipeline so the new schema is available before users access the upgraded plugin.

## Optional application-level reminder

Avoid relying on the package's own `composer.json` scripts to surface upgrade notices to consumers. In Composer, library package scripts are not a reliable way to display post-update reminders inside the host application.

The more reliable approach is the one used in this release:

- a visible upgrade notice in `README.md`
- a dedicated upgrade guide in this file
- an explicit reminder in `php artisan architect:install`
- a runtime warning in the Filament UI if the required tables are missing

