<?php
// Standalone regression test: php php/tests/CloudStorageCredentialGuardTest.php
// It never starts a service or reads production data.

require __DIR__ . '/../modules/main/backend/Service/CloudStorageCredentialGuard.php';

use Modules\Main\Service\CloudStorageCredentialGuard;

function expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: {$label}\n";
}

$raw = [
    'provider' => '阿里云',
    'bucket' => 'private-bucket',
    'access_key' => 'LTAI-real-access-key',
    'secret_key' => 'real-secret-key',
];
$redacted = CloudStorageCredentialGuard::redact($raw);

expect($redacted['provider'] === $raw['provider'], 'non-credential fields remain available');
expect($redacted['access_key'] === CloudStorageCredentialGuard::MASK, 'access key is redacted');
expect($redacted['secret_key'] === CloudStorageCredentialGuard::MASK, 'secret key is redacted');
expect(!str_contains(json_encode($redacted), $raw['access_key']), 'response contains no raw access key');
expect(!str_contains(json_encode($redacted), $raw['secret_key']), 'response contains no raw secret key');

expect(
    CloudStorageCredentialGuard::resolve(CloudStorageCredentialGuard::MASK, 'stored-access', 'AccessKey ID') === 'stored-access',
    'masked access key keeps stored value'
);
expect(
    CloudStorageCredentialGuard::resolve('new-access', 'stored-access', 'AccessKey ID') === 'new-access',
    'new access key replaces stored value'
);

try {
    CloudStorageCredentialGuard::resolve(CloudStorageCredentialGuard::MASK, null, 'AccessKey ID');
    throw new RuntimeException('masked first-time credential was accepted');
} catch (LogicException $error) {
    expect(str_contains($error->getMessage(), '首次配置'), 'first-time setup cannot use a masked credential');
}

expect(CloudStorageCredentialGuard::redact([]) === [], 'empty setting remains empty');
