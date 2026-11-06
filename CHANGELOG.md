# Release Notes

## [Unreleased](https://github.com/datawellphp/datawell/compare/v0.1.0...main)

## [v0.1.0](https://github.com/datawellphp/datawell/compare/v0.0.1...v0.1.0) - 2026-11-06

First release of the Datawell core.

- Declarative data sources: fields, filters, sorts, parameters and actions defined once in a `DataSource` class and registered by key.
- A schema that describes each source, so tables, reports and AI tools can be generated from the same definition.
- An executor that validates and compiles queries for filtering, search, sorting, grouping, aggregates, pagination and timezone conversion, with permissions enforced in one place.
- Relations (eager loads, joins, `whereHas`, aggregate fields), and actions that run synchronously or queued.
- `make:datawell` to generate a source, plus `datawell:lint` to check definitions.
- Tested on SQLite, MySQL and Postgres, PHP 8.3+ and Laravel 12 or 13.

The API may still change between minor versions until 1.0.

## [v0.0.1](https://github.com/datawellphp/datawell/commits/v0.0.1) - 2026-08-31

Placeholder release of the initial commit.
