# Upgrade Guide

## Upgrading from `v0.1.5` to `v1.0.0`

`v1.0.0` introduces a new database table and related schema updates required by the blueprint revision workflow.

If you are upgrading from `v0.1.5` or any earlier release, you **must** publish the latest package migrations and run your application's migrations before opening Filament Architect.

## Required upgrade steps

1. Update the package to `v1.0.0`.
2. Publish the latest Architect migrations.
3. Run your application's database migrations.

```bash
php artisan vendor:publish --tag=architect-migrations
php artisan migrate
```

If you prefer to use the package installer, you may run:

```bash
php artisan architect:install
php artisan migrate
```

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

