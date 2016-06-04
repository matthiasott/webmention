Representative H-Card Parsing
=============================

[![Build Status](https://travis-ci.org/aaronpk/representative-h-card-php.png?branch=master)](http://travis-ci.org/aaronpk/representative-h-card-php)

Given a parsed mf2 document, return the [representative h-card](http://microformats.org/wiki/representative-h-card-parsing) for the page.


Installation
------------

Install via composer:

```json
{
  "indieweb/representative-h-card": "0.1.*"
}
```

Or just require the one file:

```php
require_once('src/mf2/representative-h-card.php');
```

Usage
-----

This function accepts a PHP array in the format returned by the [microformats2 parser](https://github.com/indieweb/php-mf2).

```php
$html = file_get_contents('http://aaronparecki.com/');
$parsed = Mf2\parse($html);
$representative = Mf2\HCard\representative($parsed, 'http://aaronparecki.com/');
print_r($representative);
```

The function will find the representative h-card (according to the [representative h-card parsing](http://microformats.org/wiki/representative-h-card-parsing) rules) and will
return the h-card that is found.

```
Array
(
    [type] => Array
        (
            [0] => h-card
        )
    [properties] => Array
        (
            [name] => Array
                (
                    [0] => Aaron Parecki
                )
            [photo] => Array
                (
                    [0] => http://aaronparecki.com/images/aaronpk.png
                )
            [url] => Array
                (
                    [0] => http://aaronparecki.com/
                )
            [uid] => Array
                (
                    [0] => http://aaronparecki.com/
                )
        )
)
```

License
-------

Copyright 2015 by Aaron Parecki

Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except in compliance with the License. You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software distributed under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the License for the specific language governing permissions and limitations under the License.
