<?php
require_once __DIR__ . '/../includes/functions.php';

function expect_email_verification(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$generatedCode = generate_email_verification_code();
expect_email_verification(
    is_email_verification_code($generatedCode),
    'Generated confirmation codes must contain exactly six digits.'
);
expect_email_verification(
    is_email_verification_code('000042'),
    'Confirmation codes must preserve valid leading zeroes.'
);

foreach (['12345', '1234567', '12a456', ' 123456', '123456 '] as $invalidCode) {
    expect_email_verification(
        !is_email_verification_code($invalidCode),
        'Malformed confirmation codes must be rejected.'
    );
}

echo "Email confirmation code checks passed.\n";
