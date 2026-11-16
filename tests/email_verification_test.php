<?php
require_once __DIR__ . '/../includes/functions.php';

function expect_email_verification(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$generatedCode = generate_six_digit_code();
expect_email_verification(
    is_six_digit_code($generatedCode),
    'Generated confirmation and password reset codes must contain exactly six digits.'
);
expect_email_verification(
    is_six_digit_code('000042'),
    'Codes must preserve valid leading zeroes.'
);

foreach (['12345', '1234567', '12a456', ' 123456', '123456 '] as $invalidCode) {
    expect_email_verification(
        !is_six_digit_code($invalidCode),
        'Malformed confirmation and password reset codes must be rejected.'
    );
}

echo "Email confirmation and password reset code checks passed.\n";
