<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\OAuthAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Oauth2;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class GoogleController extends AbstractController
{
    
    private function buildClient(): Client
    {
        $client = new Client();

        $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? 'PUT_ID_HERE');
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? 'PUT_SECRET_HERE');

        // ✅ Doit correspondre EXACTEMENT au redirect URI configuré dans Google Cloud
        $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI'] ?? 'http://127.0.0.1:8000/login/google/callback');

        $client->setScopes(['email', 'profile']);
        $client->setPrompt('select_account');
        // $client->setAccessType('offline'); // optionnel

        return $client;
    }

    #[Route('/login/google', name: 'google_login', methods: ['GET'])]
    public function login(Request $request): RedirectResponse
    {
        $client = $this->buildClient();

        // ✅ session/state anti-CSRF
        $session = $request->getSession();
        $session->start();

        $state = bin2hex(random_bytes(16));
        $session->set('oauth2state', $state);
        $session->save();

        $client->setState($state);

        return $this->redirect($client->createAuthUrl());
    }

    #[Route('/login/google/callback', name: 'google_callback', methods: ['GET'])]
    public function callback(Request $request, UserRepository $userRepo, UserAuthenticatorInterface $userAuthenticator, OAuthAuthenticator $oauthAuthenticator, EntityManagerInterface $em): Response
    {
        if ($request->query->get('error')) {
            return new Response('Google OAuth error: ' . $request->query->get('error'), 400);
        }

        // ✅ state check
        $state = $request->query->get('state');
        $savedState = $request->getSession()->get('oauth2state');

        if (!$state || !$savedState || $state !== $savedState) {
            return new Response('Invalid state (session lost or CSRF)', 400);
        }

        $code = $request->query->get('code');
        if (!$code) {
            return new Response('No code returned by Google', 400);
        }

        $client = $this->buildClient();
        $accessToken = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($accessToken['error'])) {
            return new Response(
                'Token error: ' . $accessToken['error'] . ' | ' . ($accessToken['error_description'] ?? 'no description'),
                400
            );
        }

        $client->setAccessToken($accessToken);

        $oauth2 = new Oauth2($client);
        $userInfo = $oauth2->userinfo->get();

        $email    = $userInfo->getEmail();
        $name     = $userInfo->getName();
        $givenName = $userInfo->getGivenName();
        $familyName = $userInfo->getFamilyName();
        $picture = $userInfo->getPicture();
        $googleId = $userInfo->getId();

        if (!$email) {
            return new Response('Google did not return an email', 400);
        }

        $existingUser = $userRepo->findOneBy(['emailUser' => $email]);

        if ($existingUser) {
            // Associate Google ID if missing and persist
            $existingUser->setGoogleId((string) $googleId);

            // Google OAuth already proves ownership of the email => skip email verification
            if (method_exists($existingUser, 'setIsVerified')) {
                $existingUser->setIsVerified(true);
            }
            if (method_exists($existingUser, 'setVerificationToken')) {
                $existingUser->setVerificationToken(null);
            }
            if (method_exists($existingUser, 'setTokenExpiresAt')) {
                $existingUser->setTokenExpiresAt(null);
            }
            $em->flush();

            // Programmatically authenticate existing verified users
            $response = $userAuthenticator->authenticateUser($existingUser, $oauthAuthenticator, $request);
            // If authenticator doesn't return a Response, fallback to home
            return $response ?? $this->redirectToRoute('app_home');
        }

        // Store data in session to prefill registration and continue flow
        $session = $request->getSession();
        $session->start();
        $session->set('google_oauth', [
            'email'    => $email,
            'name'     => (string) $name,
            'givenName' => (string) ($givenName ?? ''),
            'familyName' => (string) ($familyName ?? ''),
            'picture'  => (string) ($picture ?? ''),
            'googleId' => (string) $googleId,
        ]);
        $session->save();

        return $this->redirectToRoute('app_register');
    }
}