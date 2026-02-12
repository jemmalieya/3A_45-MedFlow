<?php
namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class MailerService
{
    private MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    public function sendJitsiLink(string $to, string $doctorName, string $roomName)
    {
        $link = 'https://meet.jit.si/' . $roomName;
        $email = (new Email())
            ->from('no-reply@medflow.com')
            ->to($to)
            ->subject('Lien de consultation en ligne')
            ->html('<p>Bonjour,<br>Votre consultation en ligne avec Dr. ' . $doctorName . ' est prête.<br>Voici le lien pour rejoindre la visioconférence :<br><a href="' . $link . '">' . $link . '</a></p>');
        $this->mailer->send($email);
    }
}