<div align="center">
    <h1>Datawell</h1>
    <p><em>Declarative data sources for Laravel — define the well; everything draws from it.</em></p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://img.shields.io/packagist/v/datawell/datawell.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://img.shields.io/packagist/php-v/datawell/datawell.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://badge.laravel.cloud/badge/datawell/datawell?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/datawellphp/datawell/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/datawellphp/datawell/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/datawell/datawell"><img src="https://img.shields.io/packagist/dt/datawell/datawell.svg?style=flat-square" alt="Total Downloads"></a>
</p>

> **Status: pre-release, under construction.** The design is complete and the contract layer is being built. There is no usable API yet — this repository claims the namespace and carries the package as it takes shape. Watch the [changelog](CHANGELOG.md) for the first tagged release.

## The idea

Define each unit of data in your application once — its query, fields, filters, sorts, actions, parameters, and permissions — and let every consumer render from that single, machine-readable definition:

- **Tables** — one generic component renders any source: columns, filters, sorting, search, actions.
- **AI** — every source and action becomes a permission-scoped tool automatically; the AI knows exactly what data exists, what it may see, and what it may do.
- **Reports** — group-by and aggregates over the same definitions; a chart is just another query.
- **Exports** — replay any table view to CSV/XLSX through the same pipeline, same permissions.

One contract, one enforcement point, many consumers.

## Planned packages

| Package | Purpose |
|---|---|
| `datawell/datawell` | Core — definitions, registry, schema, executor |
| `datawell/tables` | Table UI consumer |
| `datawell/reports` | Charts and report builder |
| `datawell/ai` | AI bridge — tool generation from sources |
| `datawell` (npm) | JS companion for the table component |

## Documentation

Documentation will live at [datawell.ebarlow.dev](https://datawell.ebarlow.dev) as the package takes shape.

## Installation

Once released, the package will be installable via Composer:

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

Thank you for considering contributing to Datawell! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Ethan Barlow](https://github.com/EthanBarlo)
- [All Contributors](../../contributors)

## License

Datawell is open-sourced software licensed under the [MIT license](LICENSE.md).
