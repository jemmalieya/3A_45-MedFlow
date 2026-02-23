<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\FaceLoginService;
use App\Service\RecaptchaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FaceLoginController extends AbstractController
{
    #[Route('/login/face', name: 'app_login_face', methods: ['POST'])]
    public function loginFace(
        Request $request,
        UserRepository $users,
        FaceLoginService $face,
        RecaptchaService $recaptcha,
        EntityManagerInterface $em,
        Security $security,
        CsrfTokenManagerInterface $csrf,
        LoggerInterface $logger,
    ): Response {
        $wantsJson = $request->isXmlHttpRequest() || str_contains(strtolower((string) $request->headers->get('accept', '')), 'application/json');

        if ($this->getUser()) {
            if ($wantsJson) {
                return $this->json(['ok' => true, 'redirectTo' => $this->generateUrl('app_home')]);
            }
            return $this->redirectToRoute('app_home');
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$csrf->isTokenValid(new CsrfToken('authenticate', $csrfToken))) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 400);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        if ($recaptcha->isEnabled() && !$recaptcha->verifyRequest($request)) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Veuillez valider le reCAPTCHA.'], 200);
            }
            $this->addFlash('danger', 'Veuillez valider le reCAPTCHA.');
            return $this->redirectToRoute('app_login');
        }

        $email = trim((string) $request->request->get('emailUser', ''));
        $probeRaw = (string) $request->request->get('faceEmbedding', '');

        if ($email === '' || $probeRaw === '') {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        $probe = json_decode($probeRaw, true);
        if (!is_array($probe)) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        $user = $users->findOneBy(['emailUser' => $email]);
        if (!$user instanceof User) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        // Mirror UserChecker rules (so face login can’t bypass them)
        $status = strtoupper((string) $user->getStatutCompte());
        if ($status === 'EN_ATTENTE_ADMIN') {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        if (!$user->isVerified()) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        if ($status === 'BLOQUE') {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        if (!$user->isFaceLoginEnabled() || $user->getFaceReferenceEmbedding() === null) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isFaceLocked()) {
            $until = $user->getFaceLockedUntil();
            $msg = 'Trop de tentatives. Réessayez plus tard.';
            if ($until) {
                $msg = sprintf('Trop de tentatives. Réessayez après %s.', $until->format('H:i'));
            }
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => $msg], 200);
            }
            $this->addFlash('danger', $msg);
            return $this->redirectToRoute('app_login');
        }

        try {
            [$maxSim] = $face->compareProbeToReference($user, $probe);

            $ok = $maxSim !== null && $maxSim >= $face->getSimilarityThreshold();
            if ($ok) {
                $user
                    ->setFaceFailedAttempts(0)
                    ->setFaceLockedUntil(null)
                    ->setFaceLastVerifiedAt(new \DateTime());

                $em->persist($user);
                $em->flush();

                $loginResponse = $security->login($user, 'form_login', 'main');
                if ($wantsJson) {
                    $json = new JsonResponse(['ok' => true, 'redirectTo' => $this->generateUrl('app_home')]);
                    if ($loginResponse instanceof Response) {
                        foreach ($loginResponse->headers->all('set-cookie') as $cookie) {
                            $json->headers->set('Set-Cookie', $cookie, false);
                        }
                    }
                    $json->headers->set('Cache-Control', 'no-store');
                    return $json;
                }

                return $loginResponse ?? $this->redirectToRoute('app_home');
            }

            $user->incrementFaceFailedAttempts();
            if ($user->getFaceFailedAttempts() >= $face->getLockMaxFails()) {
                $user->setFaceLockedUntil((new \DateTime())->modify('+' . $face->getLockMinutes() . ' minutes'));
            }

            $em->persist($user);
            $em->flush();

            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Échec de la connexion. Réessayez.'], 200);
            }

            $this->addFlash('danger', 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        } catch (\RuntimeException $e) {
            // Expected/controlled failures: incompatible embedding, re-enrollment required, etc.
            $logger->info('Face login rejected', ['error' => $e->getMessage()]);
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => $e->getMessage() ?: 'Échec de la connexion.'], 200);
            }
            $this->addFlash('danger', $e->getMessage() ?: 'Échec de la connexion.');
            return $this->redirectToRoute('app_login');
        } catch (\Throwable $e) {
            $logger->error('Face login failed', ['error' => $e->getMessage()]);
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'Connexion par visage indisponible.'], 500);
            }
            $this->addFlash('danger', 'Connexion par visage indisponible.');
            return $this->redirectToRoute('app_login');
        }
    }
}
