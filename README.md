# rafflesia/rafflesia

Official Rafflesia PHP SDK. Auto-generated from the Rafflesia OpenAPI spec.

## Requirements

- PHP >= 8.2
- [Composer](https://getcomposer.org/)

## Installation

```bash
composer require rafflesia/rafflesia
```

## Usage

```php
<?php

require 'vendor/autoload.php';

use Rafflesia\Client;
use Rafflesia\Resource\HomologyQuery;

// Reads RAFFLESIA_API_KEY from the environment when no key is passed.
$client = new Client(apiKey: getenv('RAFFLESIA_API_KEY'));

$query = new HomologyQuery(
    sequence: 'MTEYKLVVVGAGGVGKSALTIQLIQNHFVDEYDPTIEDSYRKQ',
);
$result = $client->homology(
    database: 'tomato',
    query: $query,
);
$batch = $client->homologyMany(
    database: 'tomato',
    queries: [
        $query,
        new HomologyQuery(sequence: 'GAGGVGKS'),
    ],
    include: [],
    limit: 100,
);
$releases = $client->homologyDatabaseReleases()->list('tomato');
```

The client also reads `RAFFLESIA_API_KEY` and `RAFFLESIA_CLIENT_ID` from the
environment automatically when the corresponding constructor arguments are
omitted.

## License

[MIT](./LICENSE)
