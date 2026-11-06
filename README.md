<div align="center">
    <h1>Datawell</h1>
    <p><em>Declarative data sources for Laravel — define the well; everything draws from it.</em></p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://img.shields.io/packagist/v/datawell/datawell.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://img.shields.io/packagist/php-v/datawell/datawell.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://badge.laravel.cloud/badge/datawell/datawell?style=flat" alt="Laravel versions"></a>
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://img.shields.io/packagist/dt/datawell/datawell.svg?style=flat-square" alt="Total Downloads"></a>
</p>

> **Status: early release (0.x).** The core works and is tested on SQLite, MySQL and Postgres, but its API can still change between minor versions (0.1 → 0.2) until 1.0. See the [changelog](CHANGELOG.md).

## The idea

Define each unit of data in your application once — its query, fields, filters, sorts, actions, parameters, and permissions — and let every consumer render from that single, machine-readable definition:

- **Tables** — one generic component renders any source: columns, filters, sorting, search, actions.
- **AI** — every source and action becomes a permission-scoped tool automatically; the AI knows exactly what data exists, what it may see, and what it may do.
- **Reports** — group-by and aggregates over the same definitions; a chart is just another query.
- **Exports** — replay any table view to CSV/XLSX through the same pipeline, same permissions.

One contract, one enforcement point, many consumers.

## Packages

| Package | Purpose |
|---|---|
| `datawell/datawell` | Core — definitions, registry, schema, executor |
| `datawell/tables` | Table UI consumer |
| `datawell/reports` | Charts and report builder |
| `datawell/ai` | AI bridge — tool generation from sources |
| `datawell` (npm, planned) | JS companion for the table component |

## Documentation

Documentation will live at [datawell.ebarlow.dev](https://datawell.ebarlow.dev) as the package takes shape.

## Installation

Install the package with Composer:

```bash
composer require datawell/datawell
```

The configuration file can be published with:

```bash
php artisan vendor:publish --tag="datawell-config"
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

This repository is a read-only copy of the `datawell` package, published automatically from Datawell's development repository, so changes aren't merged here directly. Issues and pull requests are still welcome: accepted changes are applied upstream and published back here.

Thank you for considering contributing to Datawell! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Ethan Barlow](https://github.com/EthanBarlo)
- [All Contributors](../../contributors)

## License

Datawell is open-sourced software licensed under the [MIT license](LICENSE.md).
