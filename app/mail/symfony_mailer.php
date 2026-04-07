<?php
require_once __DIR__ . '/config.php';

function webtest_mail_smtp_dsn(array $config): string
{
    $user = rawurlencode((string) ($config['username'] ?? ''));
    $password = rawurlencode((string) ($config['password'] ?? ''));
    $host = trim((string) ($config['host'] ?? ''));
    $port = (int) ($config['port'] ?? 587);
    $query = [];
    $encryption = strtolower(trim((string) ($config['encryption'] ?? '')));

    if ($encryption !== '' && $encryption !== 'none') {
        $query[] = 'encryption=' . rawurlencode($encryption);
    }

    $dsn = sprintf('smtp://%s:%s@%s:%d', $user, $password, $host, $port);
    if ($query !== []) {
        $dsn .= '?' . implode('&', $query);
    }

    return $dsn;
}

function webtest_mail_send_via_symfony(array $message, array $config): void
{
    require_once webtest_mail_autoload_path();

    if (
        !class_exists(\Symfony\Component\Mailer\Mailer::class) ||
        !class_exists(\Symfony\Component\Mailer\Transport::class) ||
        !class_exists(\Symfony\Component\Mime\Email::class) ||
        !class_exists(\Symfony\Component\Mime\Address::class)
    ) {
        throw new RuntimeException('Email dependency is not available.');
    }

    try {
        $transport = \Symfony\Component\Mailer\Transport::fromDsn(webtest_mail_smtp_dsn($config));
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);

        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address(
                (string) ($config['from_address'] ?? ''),
                (string) ($config['from_name'] ?? 'WebTest')
            ))
            ->to(new \Symfony\Component\Mime\Address(
                (string) ($message['to']['email'] ?? ''),
                (string) ($message['to']['name'] ?? '')
            ))
            ->subject((string) ($message['subject'] ?? ''))
            ->html((string) ($message['html'] ?? ''))
            ->text((string) ($message['text'] ?? ''));

        $mailer->send($email);
    } catch (\Throwable $e) {
        throw new RuntimeException('Mail delivery failed: ' . $e->getMessage(), 0, $e);
    }
}
