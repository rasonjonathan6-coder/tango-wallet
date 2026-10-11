<?php

/**
 * Envoi d'e-mails transactionnels.
 *
 * Deux modes possibles :
 *  - SMTP (recommandé) : définit MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS,
 *    MAIL_FROM, MAIL_FROM_NAME.
 *  - Repli sur la fonction mail() de PHP si aucun hôte SMTP n'est configuré.
 *
 * En développement (aucun des deux disponible), l'e-mail est écrit dans
 * /tmp/wallet-mails.log au lieu d'échouer, afin de ne jamais bloquer un
 * parcours utilisateur.
 */

function sendMail($to, $subject, $body)
{
    $host = getenv('MAIL_HOST') ?: '';
    $port = (int) (getenv('MAIL_PORT') ?: 587);
    $user = getenv('MAIL_USER') ?: '';
    $pass = getenv('MAIL_PASS') ?: '';
    $from = getenv('MAIL_FROM') ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = getenv('MAIL_FROM_NAME') ?: 'Wallet';

    if ($host !== '') {
        return sendMailSmtp($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body);
    }

    $headers = "From: $fromName <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

    $ok = @mail($to, $subject, $body, $headers);

    if (!$ok) {
        // Environnement sans transport mail : on journalise pour ne pas perdre le message.
        @file_put_contents(
            sys_get_temp_dir() . '/wallet-mails.log',
            date('c') . " TO:$to SUBJECT:$subject\n$body\n---\n",
            FILE_APPEND
        );
    }

    return $ok;
}

function sendMailSmtp($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body)
{
    $errno = 0;
    $errstr = '';
    $secure = ($port === 465) ? 'ssl://' : '';
    $fp = @stream_socket_client($secure . $host . ':' . $port, $errno, $errstr, 15);

    if (!$fp) {
        @file_put_contents(
            sys_get_temp_dir() . '/wallet-mails.log',
            date('c') . " SMTP-ERROR:$errstr TO:$to SUBJECT:$subject\n$body\n---\n",
            FILE_APPEND
        );
        return false;
    }

    $read = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = function ($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    $read();
    $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $read();

    if ($port !== 465) {
        $write('STARTTLS');
        $read();
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $read();
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $read();
    }

    $write("MAIL FROM:<$from>");
    $read();
    $write("RCPT TO:<$to>");
    $read();
    $write('DATA');
    $read();

    $headers = "From: $fromName <$from>\r\n"
        . "To: <$to>\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $body = str_replace("\n.", "\n..", $body);
    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    $read();
    $write('QUIT');
    fclose($fp);

    return true;
}
