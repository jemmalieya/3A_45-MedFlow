<?php

namespace App\Controller;

use App\Repository\UserRepository;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleController extends AbstractController
{
    private function normalizePhoneNumber(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Keep only digits, and optionally a leading +.
        if (str_starts_with($raw, '+')) {
            return '+' . preg_replace('/\D+/', '', $raw);
        }
        if (str_starts_with($raw, '00')) {
            $digits = preg_replace('/\D+/', '', substr($raw, 2));
            return $digits !== '' ? ('+' . $digits) : '';
        }

        return preg_replace('/\D+/', '', $raw);
    }

    #[Route('/login/google', name: 'google_login', methods: ['GET'])]
    public function login(ClientRegistry $clientRegistry, Request $request): RedirectResponse
    {
        // KnpU bundle handles state/CSRF internally via session.
        return $clientRegistry
            ->getClient('google')
            ->redirect([
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/user.phonenumbers.read',
                'https://www.googleapis.com/auth/user.birthday.read',
            ], [
                // Ensure user is asked again when new sensitive scopes are introduced
                'prompt' => 'consent select_account',
                'include_granted_scopes' => 'true',
            ]);
    }

    #[Route('/login/google/callback', name: 'google_callback', methods: ['GET'])]
    public function callback(ClientRegistry $clientRegistry, Request $request, UserRepository $userRepo, HttpClientInterface $httpClient, LoggerInterface $logger): Response
    {
        if ($request->query->get('error')) {
            return new Response('Google OAuth error: ' . $request->query->get('error'), 400);
        }

        $client = $clientRegistry->getClient('google');
        try {
            $accessToken = $client->getAccessToken();
            $googleUser = $client->fetchUserFromToken($accessToken);
        } catch (\Throwable $e) {
            return new Response('OAuth callback error: ' . $e->getMessage(), 400);
        }

        // ResourceOwnerInterface guarantees toArray() + getId().
        $arr = $googleUser->toArray();
        $email = $arr['email'] ?? null;
        $name = $arr['name'] ?? ($arr['given_name'] ?? null);
        $givenName = $arr['given_name'] ?? null;
        $familyName = $arr['family_name'] ?? null;
        $picture = $arr['picture'] ?? null;
        $googleId = (string) $googleUser->getId();

        // People API (phone numbers + birthdays). Might be unavailable if user denied scopes.
        $phoneNumber = null;
        $birthDateIso = null; // YYYY-MM-DD when year is present
        try {
            $tokenValue = method_exists($accessToken, 'getToken') ? $accessToken->getToken() : null;
            if (is_string($tokenValue) && $tokenValue !== '') {
                $peopleResp = $httpClient->request('GET', 'https://people.googleapis.com/v1/people/me', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $tokenValue,
                    ],
                    'query' => [
                        'personFields' => 'phoneNumbers,birthdays',
                    ],
                ]);

                if ($peopleResp->getStatusCode() === 200) {
                    $people = $peopleResp->toArray(false);
                    if (is_array($people)) {
                        $phones = $people['phoneNumbers'] ?? null;
                        if (is_array($phones)) {
                            foreach ($phones as $p) {
                                if (!is_array($p)) {
                                    continue;
                                }

                                $candidate = null;
                                if (isset($p['canonicalForm']) && is_string($p['canonicalForm']) && trim($p['canonicalForm']) !== '') {
                                    $candidate = $p['canonicalForm'];
                                } elseif (isset($p['value']) && is_string($p['value']) && trim($p['value']) !== '') {
                                    $candidate = $p['value'];
                                }

                                if (is_string($candidate)) {
                                    $normalized = $this->normalizePhoneNumber($candidate);
                                    if ($normalized !== '') {
                                        $phoneNumber = $normalized;
                                        break;
                                    }
                                }
                            }
                        }

                        $birthdays = $people['birthdays'] ?? null;
                        if (is_array($birthdays)) {
                            foreach ($birthdays as $b) {
                                if (!is_array($b)) {
                                    continue;
                                }
                                $date = $b['date'] ?? null;
                                if (!is_array($date)) {
                                    continue;
                                }
                                $y = $date['year'] ?? null;
                                $m = $date['month'] ?? null;
                                $d = $date['day'] ?? null;

                                // Only use birthdate when year is present (your DB field is a full date).
                                if (is_int($y) && is_int($m) && is_int($d) && $y > 1900 && $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
                                    $birthDateIso = sprintf('%04d-%02d-%02d', $y, $m, $d);
                                    break;
                                }
                            }
                        }

                        $logger->info('Google People API parsed', [
                            'has_phoneNumbers' => array_key_exists('phoneNumbers', $people),
                            'phone_count' => is_array($phones) ? count($phones) : 0,
                            'phone_found' => (bool) $phoneNumber,
                            'has_birthdays' => array_key_exists('birthdays', $people),
                            'birthday_found' => (bool) $birthDateIso,
                        ]);
                    }
                } else {
                    $logger->warning('Google People API request failed', [
                        'status' => $peopleResp->getStatusCode(),
                        'body' => $peopleResp->getContent(false),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Fallback: user can still fill missing fields in register form.
            $logger->warning('Google People API exception', ['error' => $e->getMessage()]);
        }

        if (!$email) {
            return new Response('Google did not return an email', 400);
        }

        // ✅ Si user existe déjà: on peut décider de le rediriger direct login
        // MAIS ton objectif = compléter infos + validation email
        // => on passe toujours par register si info manquante OU user inexistant.
        $existingUser = $userRepo->findOneBy(['emailUser' => $email]);

        // ✅ Stocker en session pour pré-remplir /register
        $session = $request->getSession();
        $session->start();
        $session->set('google_oauth', [
            'email'    => $email,
            'name'     => (string) $name,
            'given_name' => $givenName,
            'family_name' => $familyName,
            'picture'  => $picture,
            'phone'    => $phoneNumber,
            'birthdate' => $birthDateIso,
            'googleId' => (string) $googleId,
            'existingUserId' => $existingUser ? $existingUser->getId() : null,
        ]);
        $session->save();

        // ✅ Rediriger vers ton formulaire register pour compléter les champs
        return $this->redirectToRoute('app_register');
    }
}