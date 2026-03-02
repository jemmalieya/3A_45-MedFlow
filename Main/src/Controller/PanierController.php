<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Service\DrugInteractionService;
use App\Service\OcrService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[Route('/panier')]
class PanierController extends AbstractController
{
    /**
     * ✅ Fix PHPStan: getParameter() returns mixed
     */
    private function getParameterString(string $name): string
    {
        $value = $this->getParameter($name);

        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('Parameter "%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * ✅ Normalise le panier pour DrugInteractionService:
     * - clés -> int
     * - item -> array<string,mixed>
     *
     * @param array<int|string, array{quantite?:int, prix?:float}> $panier
     * @return array<int, array<string, mixed>>
     */
    private function normalizePanierForInteractions(array $panier): array
    {
        $out = [];

        foreach ($panier as $k => $item) {
            $id = (int) $k;

            $out[$id] = [
                'quantite' => (int) ($item['quantite'] ?? 0),
                'prix'     => (float) ($item['prix'] ?? 0.0),
            ];
        }

        return $out;
    }

    #[Route('', name: 'panier_index', methods: ['GET'])]
    public function index(
        SessionInterface $session,
        EntityManagerInterface $em,
        DrugInteractionService $interactionService
    ): Response {
        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        $produitsPanier = [];
        $total = 0.0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) {
                continue;
            }

            $qty = (int) ($item['quantite'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            // ✅ FIX: ne pas utiliser une propriété inexistante (quantite_panier)
            $produitsPanier[] = [
                'produit' => $produit,
                'quantite' => $qty,
            ];

            $total += (float) $produit->getPrixProduit() * $qty;
        }

        // ✅ FIX PHPStan: type attendu par DrugInteractionService
        $interactionResult = $interactionService->checkCartInteractions(
            $this->normalizePanierForInteractions($panier)
        );

        // ✅ canValidate (ordonnance permet de débloquer SI danger)
        $canValidate = true;

        // ✅ FIX PHPStan: severity existe déjà dans le type => pas de ??
        if ($interactionResult['severity'] === 'danger') {
            $canValidate = false;

            $ok = (bool) $session->get('ordonnance_ok', false);
            $hash = $this->cartHash($panier);
            $hashSaved = (string) $session->get('ordonnance_cart_hash', '');

            if ($ok && $hashSaved === $hash) {
                $canValidate = true;
            }
        }

        // ✅ status ordonnance (affiché une seule fois)
        $ordonnanceStatus = $session->get('ordonnance_status');
        $ordonnanceDrugs = $session->get('ordonnance_drugs_found', []);
        $ordonnanceOcrText = $session->get('ordonnance_ocr_text');

        $session->remove('ordonnance_status');
        $session->remove('ordonnance_drugs_found');
        $session->remove('ordonnance_ocr_text');

        return $this->render('panier/index.html.twig', [
            // ✅ maintenant c’est une liste [{produit, quantite}]
            'produits' => $produitsPanier,
            'total' => $total,
            'interactionResult' => $interactionResult,
            'canValidate' => $canValidate,
            'ordonnanceStatus' => $ordonnanceStatus,
            'ordonnanceDrugs' => $ordonnanceDrugs,
            'ordonnanceOcrText' => $ordonnanceOcrText,
        ]);
    }

    #[Route('/check-interactions', name: 'panier_check_interactions', methods: ['GET'])]
    public function checkInteractions(
        SessionInterface $session,
        DrugInteractionService $interactionService
    ): JsonResponse {
        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        // ✅ FIX PHPStan: type attendu par DrugInteractionService
        $interactionResult = $interactionService->checkCartInteractions(
            $this->normalizePanierForInteractions($panier)
        );

        $canValidate = true;

        // ✅ FIX PHPStan: severity existe déjà
        if ($interactionResult['severity'] === 'danger') {
            $canValidate = false;

            $ok = (bool) $session->get('ordonnance_ok', false);
            $hash = $this->cartHash($panier);
            $hashSaved = (string) $session->get('ordonnance_cart_hash', '');

            if ($ok && $hashSaved === $hash) {
                $canValidate = true;
            }
        }

        $interactionResult['canValidate'] = $canValidate;

        return new JsonResponse($interactionResult);
    }

    #[Route('/count', name: 'panier_count', methods: ['GET'])]
    public function count(SessionInterface $session): JsonResponse
    {
        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        return new JsonResponse(['count' => $this->getCount($panier)]);
    }

    #[Route('/verifier/{id}', name: 'panier_verifier', methods: ['GET'])]
    public function verifier(Produit $produit, SessionInterface $session): JsonResponse
    {
        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        $id = $produit->getId_produit();

        return new JsonResponse([
            'quantite' => isset($panier[$id]) ? (int) ($panier[$id]['quantite'] ?? 0) : 0,
        ]);
    }

    // ✅ IMPORTANT: dès que panier change -> ordonnance invalidée
    private function invalidateOrdonnance(SessionInterface $session): void
    {
        $session->remove('ordonnance_ok');
        $session->remove('ordonnance_cart_hash');
    }

    #[Route('/ajouter/{id}', name: 'panier_ajouter', methods: ['POST','GET'])]
    public function ajouter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $this->invalidateOrdonnance($session);

        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        $id = $produit->getId_produit();

        if ($produit->getStatusProduit() !== 'Disponible') {
            return new JsonResponse(['success' => false, 'message' => 'Produit indisponible.'], 400);
        }

        $stock = $produit->getQuantiteProduit();
                $q = isset($panier[$id]) ? (int) ($panier[$id]['quantite'] ?? 0) : 0;

        if ($stock > 0 && $q >= $stock) {
            return new JsonResponse(['success' => false, 'message' => 'Stock insuffisant !'], 400);
        }

        $panier[$id]['quantite'] = $q + 1;
        $panier[$id]['prix'] = (float) $produit->getPrixProduit();
        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => $produit->getNomProduit().' ajouté ✅',
            'count' => $this->getCount($panier),
'quantite' => (int) $panier[$id]['quantite'],        ]);
    }

    #[Route('/augmenter/{id}', name: 'panier_augmenter', methods: ['POST'])]
    public function augmenter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $this->invalidateOrdonnance($session);

        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse(['success' => false, 'message' => 'Produit absent du panier.'], 400);
        }

        $stock = $produit->getQuantiteProduit();
                $newQty = (int) ($panier[$id]['quantite'] ?? 0) + 1;

        if ($stock > 0 && $newQty > $stock) {
            return new JsonResponse(['success' => false, 'message' => 'Stock épuisé.'], 400);
        }

        $panier[$id]['quantite'] = $newQty;
        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => $newQty,
            'count' => $this->getCount($panier),
        ]);
    }

    #[Route('/diminuer/{id}', name: 'panier_diminuer', methods: ['POST'])]
    public function diminuer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $this->invalidateOrdonnance($session);

        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse(['success' => false, 'message' => 'Produit absent du panier.'], 400);
        }

        $panier[$id]['quantite'] = (int) ($panier[$id]['quantite'] ?? 0) - 1;

        if ($panier[$id]['quantite'] <= 0) {
            unset($panier[$id]);
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => true,
                'quantite' => 0,
                'count' => $this->getCount($panier),
            ]);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
'quantite' => (int) $panier[$id]['quantite'],
            'count' => $this->getCount($panier),
        ]);
    }

    #[Route('/supprimer/{id}', name: 'panier_supprimer', methods: ['POST'])]
    public function supprimer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $this->invalidateOrdonnance($session);

        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        unset($panier[$produit->getId_produit()]);
        $session->set('panier', $panier);

        return new JsonResponse(['success' => true, 'count' => $this->getCount($panier)]);
    }

    #[Route('/vider', name: 'panier_vider', methods: ['POST'])]
    public function vider(SessionInterface $session): JsonResponse
    {
        $session->remove('panier');
        $this->invalidateOrdonnance($session);

        return new JsonResponse(['success' => true, 'count' => 0]);
    }

    /**
     * @param array<int|string, array{quantite?:int, prix?:float}> $panier
     */
    private function getCount(array $panier): int
    {
        $sum = 0;
        foreach ($panier as $item) {
            $sum += (int) ($item['quantite'] ?? 0);
        }
        return $sum;
    }

    /**
     * @param array<int|string, array{quantite?:int, prix?:float}> $panier
     */
    private function cartHash(array $panier): string
    {
        ksort($panier);

        $json = json_encode($panier, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '';
        }

        return hash('sha256', $json);
    }

    private function normalize(string $s): string
    {
        $s = mb_strtoupper($s);

        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($converted) && $converted !== '') {
            $s = $converted;
        }

        $r1 = preg_replace('/[^A-Z0-9\s]/', ' ', $s);
        $s = is_string($r1) ? $r1 : $s;

        $r2 = preg_replace('/\s+/', ' ', $s);
        $s = is_string($r2) ? $r2 : $s;

        return trim($s);
    }

    #[Route('/ordonnance/scan', name: 'panier_ordonnance_scan', methods: ['POST'])]
    public function scanOrdonnance(
        Request $request,
        SessionInterface $session,
        DrugInteractionService $interactionService,
        OcrService $ocr
    ): Response {
        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);

        if (empty($panier)) {
            $session->set('ordonnance_status', 'refused');
            $this->addFlash('error', 'Panier vide.');
            return $this->redirectToRoute('panier_index');
        }

        // ✅ FIX PHPStan: type attendu par DrugInteractionService
        $interaction = $interactionService->checkCartInteractions(
            $this->normalizePanierForInteractions($panier)
        );

        // ✅ FIX PHPStan: severity existe déjà
        if ($interaction['severity'] !== 'danger') {
            $session->set('ordonnance_status', 'approved');
            $this->addFlash('success', 'Pas d’interaction dangereuse → ordonnance non nécessaire ✅');
            return $this->redirectToRoute('panier_index');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('ordonnance');

        if (!$file) {
            $session->set('ordonnance_status', 'refused');
            $this->addFlash('error', 'Veuillez choisir une image d’ordonnance.');
            return $this->redirectToRoute('panier_index');
        }

        $ext = strtolower((string) $file->guessExtension());
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            $session->set('ordonnance_status', 'refused');
            $this->addFlash('error', 'Format non supporté. JPG/PNG/WEBP uniquement.');
            return $this->redirectToRoute('panier_index');
        }

        // ✅ FIX PHPStan: kernel.project_dir -> string
        $projectDir = $this->getParameterString('kernel.project_dir');
        $dir = $projectDir . '/public/uploads/ordonnances';

        @mkdir($dir, 0777, true);

        $filename = 'ord_' . uniqid('', true) . '.' . $ext;
        $file->move($dir, $filename);
        $path = $dir . '/' . $filename;

        try {
            $text = $ocr->extractText($path, 'fre');
        } catch (\Throwable $e) {
            $session->set('ordonnance_status', 'refused');
            $this->addFlash('error', 'OCR échoué: '.$e->getMessage());
            return $this->redirectToRoute('panier_index');
        }

        $ocrNorm = $this->normalize($text);

        // ✅ FIX PHPStan: interactions existe déjà (pas ??)
        $pairs = $interaction['interactions'];

        $ok = false;
        $foundDrugs = [];

        foreach ($pairs as $it) {
            // ✅ drug1/drug2 existent déjà (pas ??)
            $d1 = $this->normalize($it['drug1']);
            $d2 = $this->normalize($it['drug2']);

            if ($d1 !== '' && $d2 !== '' && str_contains($ocrNorm, $d1) && str_contains($ocrNorm, $d2)) {
                $ok = true;
                $foundDrugs[] = $it['drug1'];
                $foundDrugs[] = $it['drug2'];
                break;
            }
        }

        if (!$ok) {
            $session->set('ordonnance_status', 'refused');
            $session->set('ordonnance_ocr_text', mb_substr($text, 0, 300));
            $this->addFlash('error', 'Ordonnance scannée ❌ mais médicaments non détectés.');
            return $this->redirectToRoute('panier_index');
        }

        $session->set('ordonnance_ok', true);
        $session->set('ordonnance_cart_hash', $this->cartHash($panier));
        $session->set('ordonnance_status', 'approved');
        $session->set('ordonnance_drugs_found', array_values(array_unique($foundDrugs)));

        $this->addFlash('success', 'Ordonnance validée ✅ Commande débloquée.');
        return $this->redirectToRoute('panier_index');
    }
}