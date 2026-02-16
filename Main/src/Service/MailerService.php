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
        $apiKey = $_ENV['BREVO_API_KEY1'] ?? null;
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL1'] ?? null;
        $senderName = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            throw new \Exception("Config Brevo manquante: BREVO_API_KEY ou BREVO_SENDER_EMAIL.");
        }

        $link = 'https://meet.jit.si/' . $roomName;
                $payload = [
                        'sender' => [
                                'name' => $senderName,
                                'email' => $senderEmail,
                        ],
                        'to' => [[
                                'email' => $to,
                        ]],
                        'subject' => 'Lien de consultation en ligne',
                        'htmlContent' => '
                                <div style="font-family:Roboto,Arial,sans-serif;background:#f9f9f9;padding:32px 0;">
                                    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px #0001;padding:32px 24px;">
                                        <div style="text-align:center;margin-bottom:24px;">
                                            <h2 style="margin:16px 0 0 0;color:#0d6efd;font-weight:700;">MedFlow</h2>
                                        </div>
                                        <h3 style="color:#222;">Consultation en ligne</h3>
                                        <p style="color:#444;font-size:16px;">Bonjour,<br>Votre consultation en ligne avec Dr. ' . htmlspecialchars($doctorName) . ' est prête.</p>
                                        <div style="margin:24px 0;text-align:center;">
                                            <a href="' . $link . '" style="display:inline-block;padding:14px 28px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:8px;font-size:18px;font-weight:500;">Rejoindre la visioconférence</a>
                                        </div>
                                        <p style="color:#888;font-size:13px;">Merci d’utiliser MedFlow.</p>
                                    </div>
                                </div>'
                ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Erreur CURL: ' . $err);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Brevo error ($httpCode): " . $response);
        }
    }

    public function sendRendezVousConfirmed(string $to, string $patientName, \DateTime $dateTime)
    {
        $apiKey = $_ENV['BREVO_API_KEY1'] ?? null;
        $appUrl = $_ENV['APP_URL'] ?? 'http://127.0.0.1:8000';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL1'] ?? null;
        $senderName = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            throw new \Exception("Config Brevo manquante: BREVO_API_KEY ou BREVO_SENDER_EMAIL.");
        }

                $payload = [
                        'sender' => [
                                'name' => $senderName,
                                'email' => $senderEmail,
                        ],
                        'to' => [[
                                'email' => $to,
                                'name' => $patientName,
                        ]],
                        'subject' => 'Votre rendez-vous est confirmé',
                        'htmlContent' => '
                                <div style="font-family:Roboto,Arial,sans-serif;background:#f9f9f9;padding:32px 0;">
                                    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px #0001;padding:32px 24px;">
                                        <div style="text-align:center;margin-bottom:24px;">
                                            <h2 style="margin:16px 0 0 0;color:#0d6efd;font-weight:700;">MedFlow</h2>
                                        </div>
                                        <h3 style="color:#222;">Confirmation de rendez-vous</h3>
                                        <p style="color:#444;font-size:16px;">Bonjour ' . htmlspecialchars($patientName) . ',<br>Votre rendez-vous du ' . $dateTime->format('d/m/Y H:i') . ' a été <b>confirmé</b>.</p>
                                        <p style="color:#888;font-size:13px;">Merci de votre confiance.<br>L’équipe MedFlow.</p>
                                    </div>
                                </div>'
                ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Erreur CURL: ' . $err);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Brevo error ($httpCode): " . $response);
        }
    }
}