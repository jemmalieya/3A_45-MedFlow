<?php
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$dsn = $_ENV['MAILER_DSN'] ?? null;
echo "DSN = ".$dsn."<br>";

$transport = Transport::fromDsn($dsn);
$mailer = new Mailer($transport);

$email = (new Email())
    ->from('medflow.noreply@gmail.com')
    ->to('mayssem.mannai@esprit.tn')  // ou ton gmail
    ->subject('Test mail Symfony')
    ->text('Hello from MedFlow');

$mailer->send($email);

echo "✅ Sent";
