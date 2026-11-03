<?php
require __DIR__ . '/../vendor/autoload.php';

use GraphQL\Type\Definition\Type;
use matthiasott\webmention\fields\WebmentionSwitch;

$failures = 0;

function assert_true(bool $result, string $label): void {
    global $failures;
    if (!$result) {
        echo "FAIL: {$label}\n";
        $failures++;
    } else {
        echo "PASS: {$label}\n";
    }
}

/**
 * Returns a switch field whose "is this element new?" answer is fixed, so
 * normalizeValue() can be exercised without a saved element.
 */
function makeSwitch(bool $default, bool $isFresh): WebmentionSwitch {
    $field = new WebmentionSwitch(['handle' => 'sendWebmentions', 'default' => $default]);
    $field->setIsFresh($isFresh);
    return $field;
}

// The field's Default Value setting applies to new elements only.
assert_true(makeSwitch(true, true)->normalizeValue(null, null) === true, 'New element without a value starts on when the default is on');
assert_true(makeSwitch(false, true)->normalizeValue(null, null) === false, 'New element without a value starts off when the default is off');
assert_true(makeSwitch(true, false)->normalizeValue(null, null) === false, 'Existing element without a stored value stays off, so it can\'t start sending just because a default is set');

// An explicit value always beats the default.
assert_true(makeSwitch(true, true)->normalizeValue(false, null) === false, 'Explicit false wins over a default of on');
assert_true(makeSwitch(false, true)->normalizeValue(true, null) === true, 'Explicit true wins over a default of off');

// Every representation the value can arrive in has to mean what it says. Form
// posts send '1' and '', and API clients can send strings. Casting with (bool)
// would turn the string "false" into true and send webmentions by mistake.
$field = makeSwitch(false, false);
foreach ([
    ['false', false],
    ['true', true],
    ['0', false],
    ['1', true],
    ['', false],
    [0, false],
    [1, true],
] as [$input, $expected]) {
    assert_true($field->normalizeValue($input, null) === $expected, 'Value ' . var_export($input, true) . ' normalizes to ' . var_export($expected, true));
}

// MCP and GraphQL derive the type they accept from these methods, so clients
// have to be told this is a boolean rather than a string.
$field = makeSwitch(false, true);
assert_true($field->getContentGqlType() === Type::boolean(), 'GraphQL output type is boolean');
$argument = $field->getContentGqlMutationArgumentType();
assert_true(($argument['type'] ?? null) === Type::boolean(), 'GraphQL/MCP input type is boolean');
assert_true(($argument['name'] ?? null) === 'sendWebmentions', 'Input type is named after the field handle');

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
