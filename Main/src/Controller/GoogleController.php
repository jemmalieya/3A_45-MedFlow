<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Google\Client;
use Google\Service\Oauth2;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function callback(Request $request, UserRepository $userRepo): Response
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
        $googleId = $userInfo->getId();

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
            'googleId' => (string) $googleId,
            'existingUserId' => $existingUser ? $existingUser->getId() : null,
        ]);
        $session->save();

        // ✅ Rediriger vers ton formulaire register pour compléter les champs
        return $this->redirectToRoute('app_register');
    }
}
