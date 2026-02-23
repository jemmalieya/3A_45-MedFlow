<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\UserGamificationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UserGamificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserGamificationService $userGamificationService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_gamification', [$this, 'userGamification']),
        ];
    }

    /**
     * @return array{score:int, badges: array<int, array{key:string,label:string,variant:string,locked:bool}>}
     */
    public function userGamification(User $user): array
    {
        return $this->userGamificationService->build($user);
    }
}
