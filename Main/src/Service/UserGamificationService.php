<?php

namespace App\Service;

use App\Entity\User;

final class UserGamificationService
{
    /**
     * @return array{score:int, badges: array<int, array{key:string,label:string,variant:string,locked:bool}>}
     */
    public function build(User $user): array
    {
        $badges = [];

        $add = static function (array &$badges, string $key, string $label, string $variant, bool $locked): void {
            $badges[] = [
                'key' => $key,
                'label' => $label,
                'variant' => $variant,
                'locked' => $locked,
            ];
        };

        // 1) Email verified
        $add(
            $badges,
            'verified',
            $user->isVerified() ? 'Compte vérifié' : 'Compte non vérifié',
            $user->isVerified() ? 'success' : 'secondary',
            !$user->isVerified()
        );

        // 2) 2FA
        $add(
            $badges,
            '2fa',
            $user->isTotpEnabled() ? '2FA activée' : '2FA désactivée',
            $user->isTotpEnabled() ? 'success' : 'secondary',
            !$user->isTotpEnabled()
        );

        // 3) Face login
        $add(
            $badges,
            'face',
            $user->isFaceLoginEnabled() ? 'Visage activé' : 'Visage désactivé',
            $user->isFaceLoginEnabled() ? 'info' : 'secondary',
            !$user->isFaceLoginEnabled()
        );

        // 4) Profile picture
        $hasProfilePicture = (string) $user->getProfilePicture();
        $add(
            $badges,
            'profile_picture',
            $hasProfilePicture !== '' ? 'Photo de profil' : 'Pas de photo',
            $hasProfilePicture !== '' ? 'info' : 'secondary',
            $hasProfilePicture === ''
        );

        // 5) Google linked (optional)
        $googleId = (string) $user->getGoogleId();
        $add(
            $badges,
            'google',
            $googleId !== '' ? 'Google connecté' : 'Google non connecté',
            $googleId !== '' ? 'primary' : 'secondary',
            $googleId === ''
        );

        // 6) Role badge (always present)
        $role = strtoupper((string) $user->getRoleSysteme());
        $roleLabel = match ($role) {
            'ADMIN' => 'Rôle: Admin',
            'STAFF_MEDICAL' => 'Rôle: Staff',
            default => 'Rôle: Patient',
        };
        $roleVariant = match ($role) {
            'ADMIN' => 'warning',
            'STAFF_MEDICAL' => 'info',
            default => 'secondary',
        };
        $add($badges, 'role', $roleLabel, $roleVariant, false);

        // 7) Staff request status (always present)
        $sr = strtoupper((string) $user->getStaffRequestStatus());
        [$srLabel, $srVariant] = match ($sr) {
            'PENDING' => ['Demande: en attente', 'warning'],
            'APPROVED' => ['Demande: approuvée', 'success'],
            'REJECTED' => ['Demande: refusée', 'danger'],
            default => ['Demande: —', 'secondary'],
        };
        $add($badges, 'staff_request', $srLabel, $srVariant, $sr === '');

        // 8) Account status
        $isBanned = $user->getBannedAt() !== null;
        $add(
            $badges,
            'status',
            $isBanned ? 'Compte banni' : 'Compte actif',
            $isBanned ? 'danger' : 'success',
            false
        );

        // Security score (0-100)
        $score = 0;
        if ($user->isVerified()) {
            $score += 30;
        }
        if ($user->isTotpEnabled()) {
            $score += 40;
        }
        if ($user->isFaceLoginEnabled()) {
            $score += 20;
        }
        if ($hasProfilePicture !== '') {
            $score += 10;
        }
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'badges' => $badges,
        ];
    }
}
