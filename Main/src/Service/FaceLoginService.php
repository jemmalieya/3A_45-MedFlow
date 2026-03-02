<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Free face-login implementation.
 *
 * The browser computes an embedding (vector of floats) locally and we store that vector in DB.
 */
final class FaceLoginService
{
    private const EMBEDDING_VERSION = 2;
    private const EMBEDDING_TYPE = 'mp_image_embedder_face_crop_v1';

    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {
    }

    /**
     * For the free/local version, there is no external configuration.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Keeps the existing env name but now means cosine-similarity threshold in percent.
     * Example: 90 => cosine >= 0.90.
     */
    public function getSimilarityThreshold(): int
    {
        $raw = $this->params->get('face_login_similarity');
        $value = is_numeric($raw) ? (int) $raw : 90;
        return max(0, min(100, $value));
    }

    public function getLockMaxFails(): int
    {
        $raw = $this->params->get('face_login_lock_max_fail');
        $value = is_numeric($raw) ? (int) $raw : 5;
        return max(1, $value);
    }

    public function getLockMinutes(): int
    {
        $raw = $this->params->get('face_login_lock_minutes');
        $value = is_numeric($raw) ? (int) $raw : 15;
        return max(1, $value);
    }

    /**
     * @param array<int, float|int|string> $embedding
     */
    public function enrollFromEmbedding(User $user, array $embedding): void
    {
        [$version, $type, $vector] = $this->unpackAndSanitizeEmbedding($embedding, true);

        // Always store in a structured format so we can safely detect incompatibilities later.
        $stored = [
            'v' => $version,
            'type' => $type,
            'dim' => count($vector),
            'vec' => $vector,
        ];

        $user
            ->setFaceReferenceEmbedding($stored)
            ->markFaceEnrolledAt()
            ->setFaceLoginEnabled(true)
            ->setFaceFailedAttempts(0)
            ->clearFaceLock();
    }

    public function disable(User $user): void
    {
        $user
            ->setFaceLoginEnabled(false)
            ->setFaceReferenceEmbedding(null)
            ->clearFaceEnrolledAt()
            ->clearFaceLastVerifiedAt()
            ->setFaceFailedAttempts(0)
            ->clearFaceLock();
    }

    /**
     * @param array<int, float|int|string> $probeEmbedding
     * @return array{0: float|null, 1: null} [similarityPercent, null]
     */
    public function compareProbeToReference(User $user, array $probeEmbedding): array
    {
        $ref = $user->getFaceReferenceEmbedding();
        if (!is_array($ref) || count($ref) === 0) {
            throw new \RuntimeException('No reference enrolled.');
        }

        // Force re-enrollment if the stored reference is the legacy landmark-based vector.
        // This prevents false accepts caused by weak embeddings.
        [$refVersion, $refType, $refVec] = $this->unpackAndSanitizeEmbedding($ref, false);
        if ($refVersion < self::EMBEDDING_VERSION) {
            throw new \RuntimeException('Veuillez ré-enregistrer votre visage (mise à jour de sécurité).');
        }

        [$probeVersion, $probeType, $probeVec] = $this->unpackAndSanitizeEmbedding($probeEmbedding, false);

        if ($refVersion !== $probeVersion || $refType !== $probeType) {
            throw new \RuntimeException('Embedding incompatible.');
        }

        if (count($refVec) !== count($probeVec)) {
            throw new \RuntimeException('Embedding incompatible.');
        }

        $cos = $this->cosineSimilarity($refVec, $probeVec);
        $pct = max(0.0, min(100.0, $cos * 100.0));

        return [$pct, null];
    }

    /**
     * Accept either:
     * - legacy: [float, float, ...] (FaceLandmarker landmarks)
     * - v2: ['v' => 2, 'type' => '...', 'vec' => [float, ...]]
     *
     * @param array<mixed> $embedding
     * @return array{0:int,1:string,2:array<int,float>} [version, type, vector]
     */
    private function unpackAndSanitizeEmbedding(array $embedding, bool $isEnroll): array
    {
        // New structured format.
        if (array_key_exists('vec', $embedding) && is_array($embedding['vec'])) {
            $version = (int) ($embedding['v'] ?? self::EMBEDDING_VERSION);
            $type = (string) ($embedding['type'] ?? self::EMBEDDING_TYPE);
            $vector = $this->sanitizeAndNormalizeVector($embedding['vec'], $version);
            return [$version, $type, $vector];
        }

        // Some clients might send {embedding:{...}} or {embedding:[...]}
        if (array_key_exists('embedding', $embedding) && is_array($embedding['embedding'])) {
            return $this->unpackAndSanitizeEmbedding($embedding['embedding'], $isEnroll);
        }

        // Legacy raw vector list.
        // On enroll, we refuse the legacy (landmark) dimensionality to force the stronger pipeline.
        $vector = $this->sanitizeAndNormalizeVector($embedding, 1);
        $dim = count($vector);

        if ($isEnroll && $dim >= 900) {
            throw new \RuntimeException('Mise à jour requise: veuillez réessayer (capture du visage améliorée).');
        }

        return [1, 'legacy_landmarks', $vector];
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $na += $av * $av;
            $nb += $bv * $bv;
        }

        if ($na <= 0.0 || $nb <= 0.0) {
            throw new \RuntimeException('Embedding invalide.');
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * - Coerces values to float
     * - Basic bounds + length checks
     * - L2 normalizes to make cosine meaningful
     *
     * @param array<int, float|int|string> $embedding
     * @return array<int, float>
     */
    private function sanitizeAndNormalizeVector(array $embedding, int $version): array
    {
        $out = [];

        foreach ($embedding as $v) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '') {
                    continue;
                }
            }

            if (!is_numeric($v)) {
                throw new \RuntimeException('Embedding invalide.');
            }

            $v = (float) $v;

            if (!is_finite($v)) {
                throw new \RuntimeException('Embedding invalide.');
            }

            if ($v < -10_000.0 || $v > 10_000.0) {
                throw new \RuntimeException('Embedding invalide.');
            }

            $out[] = $v;
        }

        $dim = count($out);

        if ($version >= self::EMBEDDING_VERSION) {
            // ImageEmbedder vectors are typically a few hundred to a few thousand floats.
            if ($dim < 128) {
                throw new \RuntimeException('Veuillez cadrer un seul visage (détection insuffisante).');
            }
            if ($dim > 4_096) {
                throw new \RuntimeException('Embedding invalide.');
            }
        } else {
            // Legacy landmark vectors are usually ~1400 floats.
            if ($dim < 900) {
                throw new \RuntimeException('Veuillez cadrer un seul visage (détection insuffisante).');
            }
            if ($dim > 2_500) {
                throw new \RuntimeException('Embedding invalide.');
            }
        }

        $norm = 0.0;
        foreach ($out as $vv) {
            $norm += $vv * $vv;
        }
        if ($norm <= 0.0) {
            throw new \RuntimeException('Embedding invalide.');
        }

        $norm = sqrt($norm);
        foreach ($out as $i => $vv) {
            $out[$i] = $vv / $norm;
        }

        return $out;
    }
}
