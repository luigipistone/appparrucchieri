<?php
declare(strict_types=1);

$key = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if (!$key) {
    fwrite(STDERR, "Impossibile generare chiavi VAPID. Verifica l'estensione OpenSSL.\n");
    exit(1);
}

openssl_pkey_export($key, $privatePem);
$details = openssl_pkey_get_details($key);
$publicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];

function base64url_encode_cli(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

echo "const VAPID_PUBLIC_KEY = '" . base64url_encode_cli($publicKey) . "';\n";
echo "const VAPID_PRIVATE_KEY = <<<'PEM'\n" . $privatePem . "PEM;\n";
echo "const VAPID_SUBJECT = 'mailto:admin@salone.local';\n";
